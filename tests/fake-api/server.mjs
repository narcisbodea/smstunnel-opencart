// A fake SMSTunnel API for the tests of SMSTunnel for OpenCart. It answers like the real server
// (SMSserver public-api controller, as the SMSTunnel PHP SDK and the Node SDK read it) and records every
// request, so the tests can check exactly what the extension sent. It never sends a text anywhere.
//
//   node tests/fake-api/server.mjs [--port 0] [--host 127.0.0.1] [--port-file path]
//
// Endpoints of the real API:
//   POST /api/v1/sms/send          { to, message, deviceId?, defaultCountryCode? }
//   GET  /api/v1/devices
//   GET  /api/v1/sms/status/:id
//   GET  /api/v1/account/usage
// Test controls (never on the real API):
//   GET  /__requests               every request recorded since the last reset
//   POST /__reset                  forget the requests and texts, back to normal mode
//   POST /__control                { mode, sendMode, times, delayMs, rateLimit, devicesOnline, status }
//   GET  /__health
//
// Modes (for every API call, or only for sends with sendMode; `times` limits it to the next N calls):
//   normal | auth (401) | forbidden (403 PERMISSION_REQUIRED) | rate (429 per-key limit, Retry-After)
//   | daily (429 plan limit) | error500 | html502 (a proxy page) | hang (never answers) | drop (closes the
//   connection) | slow (answers after delayMs) | notjson (200 with HTML)
import http from 'node:http';
import crypto from 'node:crypto';
import fs from 'node:fs';

const args = Object.fromEntries(
  process.argv.slice(2).reduce((pairs, value, i, all) => (value.startsWith('--') ? [...pairs, [value.slice(2), all[i + 1]]] : pairs), []),
);

// Built from two parts so that no file holds something a secret scanner takes for a real key.
export const TEST_KEY = process.env.SMSTUNNEL_FAKE_KEY || 'sk_test_' + 'FakeOpenCartKey00000000000001';
export const PHONES = [
  { id: '6650c0ffee0000000000a001', name: 'Pixel 8 (store)' },
  { id: '6650c0ffee0000000000a002', name: 'Galaxy A54' },
];

const state = {
  seq: 0,
  requests: [],
  texts: new Map(),
  mode: 'normal',
  sendMode: null,
  times: 0, // 0: until changed
  delayMs: 0,
  rateLimit: 0, // sends a minute per key; 0: no limit
  sendTimes: [],
  devicesOnline: [true, false],
  finalStatus: 'sent', // what a text becomes once the phone has it: sent | delivered | failed | unconfirmed
};

function reset() {
  state.requests = [];
  state.texts.clear();
  state.mode = 'normal';
  state.sendMode = null;
  state.times = 0;
  state.delayMs = 0;
  state.rateLimit = 0;
  state.sendTimes = [];
  state.devicesOnline = [true, false];
  state.finalStatus = 'sent';
}

function json(res, status, body, headers = {}) {
  const text = JSON.stringify(body);
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Content-Length': Buffer.byteLength(text), ...headers });
  res.end(text);
  return { status, body };
}

const errors = {
  missingKey: { statusCode: 401, message: 'API key is required. Use the X-API-Key header, Authorization: Bearer <key>, or HTTP Basic auth with the key as the password.', error: 'Unauthorized' },
  badKey: { message: 'Invalid or expired API key', error: 'Unauthorized', statusCode: 401 },
  forbidden: { statusCode: 403, error: 'Forbidden', code: 'PERMISSION_REQUIRED', permission: 'sms:send', message: "This API key lacks the 'sms:send' permission" },
  rate: { statusCode: 429, message: 'ThrottlerException: Too Many Requests' },
  daily: { message: "You reached today's limit of 500 texts on the Starter plan. More can go out tomorrow, or on a higher plan.", error: 'Too Many Requests', statusCode: 429 },
  server: { statusCode: 500, message: 'Internal server error' },
  notFound: { message: 'Message not found', error: 'Not Found', statusCode: 404 },
};

/** The mode that applies to this call, counting down `times`. */
function modeFor(isSend) {
  const mode = isSend && state.sendMode ? state.sendMode : state.mode;
  if (mode !== 'normal' && state.times > 0) {
    state.times -= 1;
    if (state.times === 0) {
      state.mode = 'normal';
      state.sendMode = null;
    }
  }
  return mode;
}

/** Answers with a failure mode, or returns null to go on normally. */
function failure(res, mode) {
  switch (mode) {
    case 'auth':
      return json(res, 401, errors.badKey);
    case 'forbidden':
      return json(res, 403, errors.forbidden);
    case 'rate':
      return json(res, 429, errors.rate, { 'Retry-After': '42' });
    case 'daily':
      return json(res, 429, errors.daily);
    case 'error500':
      return json(res, 500, errors.server);
    case 'html502': {
      const page = '<html><head><title>502 Bad Gateway</title></head><body><center><h1>502 Bad Gateway</h1></center><hr><center>nginx</center></body></html>';
      res.writeHead(502, { 'Content-Type': 'text/html' });
      res.end(page);
      return { status: 502, body: page };
    }
    case 'notjson':
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end('<html><body>Welcome</body></html>');
      return { status: 200, body: 'html' };
    case 'hang':
      // Never answers: the client's own timeout ends it.
      return { status: 0, body: 'hang', pending: true };
    case 'drop':
      res.socket.destroy();
      return { status: 0, body: 'dropped' };
    default:
      return null;
  }
}

function e164(to, countryCode) {
  const raw = String(to).trim();
  const digits = raw.replace(/\D+/g, '');
  if (raw.startsWith('+')) return `+${digits}`;
  if (digits.startsWith('00')) return `+${digits.slice(2)}`;
  const prefix = String(countryCode || '+40').replace(/\D+/g, '');
  return `+${prefix}${digits.replace(/^0/, '')}`;
}

function validateSend(body) {
  const allowed = ['to', 'message', 'deviceId', 'defaultCountryCode', 'is2FA', 'encrypted', 'encryptedPayload'];
  const problems = [];
  for (const key of Object.keys(body)) {
    if (!allowed.includes(key)) problems.push(`property ${key} should not exist`);
  }
  if (typeof body.to !== 'string') problems.push('to must be a string');
  else if (!/^[\d\s.\-()+ ]{7,25}$/.test(body.to)) problems.push('Phone number must contain 7-25 characters (digits, spaces, dots, dashes, + allowed)');
  if (typeof body.message !== 'string') problems.push('message must be a string');
  else {
    if (body.message.length < 1) problems.push('message must be longer than or equal to 1 characters');
    if ([...body.message].length > 1600) problems.push('message must be shorter than or equal to 1600 characters');
  }
  if (body.deviceId !== undefined && typeof body.deviceId !== 'string') problems.push('deviceId must be a string');
  if (body.defaultCountryCode !== undefined && typeof body.defaultCountryCode !== 'string') problems.push('defaultCountryCode must be a string');
  return problems;
}

async function readBody(req) {
  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);
  return Buffer.concat(chunks).toString('utf8');
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://fake');
  const raw = await readBody(req);

  // Test controls.
  if (url.pathname.startsWith('/__')) {
    if (url.pathname === '/__health') return json(res, 200, { ok: true, mode: state.mode, sendMode: state.sendMode });
    if (url.pathname === '/__requests') return json(res, 200, { requests: state.requests });
    if (url.pathname === '/__texts') return json(res, 200, { texts: [...state.texts.values()] });
    if (url.pathname === '/__shutdown' && req.method === 'POST') return json(res, 200, { ok: true, stopping: true });
    if (url.pathname === '/__reset' && req.method === 'POST') {
      reset();
      return json(res, 200, { ok: true });
    }
    if (url.pathname === '/__control' && req.method === 'POST') {
      let control = {};
      try {
        control = raw ? JSON.parse(raw) : {};
      } catch {
        return json(res, 400, { error: 'not JSON' });
      }
      if (control.mode !== undefined) state.mode = control.mode;
      if (control.sendMode !== undefined) state.sendMode = control.sendMode;
      if (control.times !== undefined) state.times = Number(control.times) || 0;
      if (control.delayMs !== undefined) state.delayMs = Number(control.delayMs) || 0;
      if (control.rateLimit !== undefined) state.rateLimit = Number(control.rateLimit) || 0;
      if (control.devicesOnline !== undefined) state.devicesOnline = control.devicesOnline;
      if (control.finalStatus !== undefined) state.finalStatus = control.finalStatus;
      if (control.allTextsStatus !== undefined) {
        for (const text of state.texts.values()) text.status = control.allTextsStatus;
      }
      return json(res, 200, { ok: true, mode: state.mode, sendMode: state.sendMode, times: state.times });
    }
    return json(res, 404, errors.notFound);
  }

  // Every API request is recorded, the key only as "right or wrong" and its last 4 characters.
  const key = req.headers['x-api-key'];
  let body = null;
  if (raw) {
    try {
      body = JSON.parse(raw);
    } catch {
      body = raw;
    }
  }
  const record = {
    seq: ++state.seq,
    at: new Date().toISOString(),
    method: req.method,
    path: url.pathname,
    query: Object.fromEntries(url.searchParams),
    keyPresent: typeof key === 'string' && key !== '',
    keyOk: key === TEST_KEY,
    keyTail: typeof key === 'string' ? key.slice(-4) : '',
    contentType: req.headers['content-type'] || '',
    accept: req.headers.accept || '',
    userAgent: req.headers['user-agent'] || '',
    idempotencyKey: req.headers['idempotency-key'] || '',
    body,
    status: null,
  };
  state.requests.push(record);
  const answer = await route(req, res, url, body, key);
  record.status = answer ? answer.status : null;
  record.response = answer ? answer.body : null;
});

async function route(req, res, url, body, key) {
  const isSend = req.method === 'POST' && url.pathname === '/api/v1/sms/send';
  if (!key) return json(res, 401, errors.missingKey);
  if (key !== TEST_KEY) return json(res, 401, errors.badKey);

  const mode = modeFor(isSend);
  if (mode === 'slow' && state.delayMs > 0) await new Promise((resolve) => setTimeout(resolve, state.delayMs));
  const failed = failure(res, mode);
  if (failed) return failed;

  if (isSend) {
    if (!/^application\/json\b/i.test(req.headers['content-type'] || '')) {
      return json(res, 400, { statusCode: 400, message: ['to must be a string', 'message must be a string'], error: 'Bad Request' });
    }
    const payload = body && typeof body === 'object' && !Array.isArray(body) ? body : {};
    const problems = validateSend(payload);
    if (problems.length) return json(res, 400, { statusCode: 400, message: problems, error: 'Bad Request' });

    if (state.rateLimit > 0) {
      const now = Date.now();
      state.sendTimes = state.sendTimes.filter((t) => now - t < 60000);
      if (state.sendTimes.length >= state.rateLimit) return json(res, 429, errors.rate, { 'Retry-After': '60' });
      state.sendTimes.push(now);
    }

    const phoneIndex = payload.deviceId ? PHONES.findIndex((p) => p.id === payload.deviceId) : state.devicesOnline.findIndex(Boolean);
    const online = phoneIndex >= 0 && Boolean(state.devicesOnline[phoneIndex]);
    const id = crypto.randomBytes(12).toString('hex');
    const text = {
      id,
      phoneNumber: e164(payload.to, payload.defaultCountryCode),
      to: payload.to,
      message: payload.message,
      deviceId: payload.deviceId || (phoneIndex >= 0 ? PHONES[phoneIndex].id : PHONES[0].id),
      defaultCountryCode: payload.defaultCountryCode,
      status: online ? state.finalStatus : 'pending',
      createdAt: new Date().toISOString(),
    };
    state.texts.set(id, text);
    // The real server leaves `queued` out (undefined) when the phone took the text.
    return json(res, 200, {
      success: true,
      messageId: id,
      ...(online ? {} : { queued: true }),
      data: { messageId: id, branded: false, ...(online ? {} : { queued: true }), remaining: { clean: -1, total: 'unlimited' } },
    });
  }

  if (req.method === 'GET' && url.pathname === '/api/v1/devices') {
    const devices = PHONES.map((phone, i) => ({
      id: phone.id,
      name: phone.name,
      isOnline: Boolean(state.devicesOnline[i]),
      lastSeenAt: new Date(Date.now() - (state.devicesOnline[i] ? 20000 : 2 * 86400000)).toISOString(),
    }));
    return json(res, 200, { success: true, data: { devices, online: devices.filter((d) => d.isOnline).length, total: devices.length } });
  }

  const status = url.pathname.match(/^\/api\/v1\/sms\/status\/([^/]+)$/);
  if (req.method === 'GET' && status) {
    const text = state.texts.get(decodeURIComponent(status[1]));
    if (!text) return json(res, 404, errors.notFound);
    const unconfirmed = text.status === 'unconfirmed';
    const sent = ['sent', 'delivered', 'failed', 'unconfirmed'].includes(text.status);
    return json(res, 200, {
      success: true,
      data: {
        id: text.id,
        phoneNumber: text.phoneNumber,
        status: unconfirmed ? 'failed' : text.status,
        ...(unconfirmed ? { unconfirmed: true } : {}),
        deliveryStatus: text.status,
        sentAt: sent ? text.createdAt : undefined,
        deliveredAt: text.status === 'delivered' ? new Date().toISOString() : undefined,
        errorMessage: text.status === 'failed' ? 'Generic failure (no signal)' : unconfirmed ? 'Not confirmed by the phone' : null,
      },
    });
  }

  if (req.method === 'GET' && url.pathname === '/api/v1/account/usage') {
    return json(res, 200, {
      success: true,
      data: {
        plan: 'standard',
        accountType: 'standard',
        usage: { smsClean: state.texts.size, smsBranded: 0, twoFaClean: 0 },
        limits: { smsClean: 'unlimited', twoFaClean: 100 },
        remaining: { smsClean: 'unlimited', twoFaClean: 100 },
      },
    });
  }

  return json(res, 404, { message: `Cannot ${req.method} ${url.pathname}`, error: 'Not Found', statusCode: 404 });
}

const host = args.host || '127.0.0.1';
server.keepAliveTimeout = 2000;
server.listen(Number(args.port || process.env.PORT || 0), host, () => {
  const { port } = server.address();
  if (args['port-file']) fs.writeFileSync(args['port-file'], String(port));
  console.log(JSON.stringify({ listening: port, host }));
});

function shutdown() {
  server.close(() => process.exit(0));
  // Requests left hanging on purpose ("hang" mode) must not keep the port.
  if (typeof server.closeAllConnections === 'function') server.closeAllConnections();
  setTimeout(() => process.exit(0), 1500).unref();
}
for (const signal of ['SIGINT', 'SIGTERM', 'SIGBREAK']) {
  process.on(signal, shutdown);
}
// The tests can also stop it over HTTP (Windows has no SIGTERM for a detached process).
server.on('request', (req) => {
  if (req.method === 'POST' && req.url === '/__shutdown') setTimeout(shutdown, 50);
});
