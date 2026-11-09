// Shared helpers for the end-to-end tests: the Docker test stores, the fake SMSTunnel API, Playwright.
import { execFileSync, spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const HERE = path.dirname(fileURLToPath(import.meta.url));
export const REPO = path.resolve(HERE, '..', '..');
export const ADMIN_USER = 'admin';
export const ADMIN_PASSWORD = 'Smstunnel-test-2026';
// Built from two parts so that no file holds something a secret scanner takes for a real key.
export const FAKE_KEY = 'sk_test_' + 'FakeOpenCartKey00000000000001';

// Playwright from the shared install (no browser download), with the headless shell already on disk.
const PLAYWRIGHT_FROM = process.env.SMSTUNNEL_PLAYWRIGHT || 'D:/ANDROID/integrari/smstunnel-chrome/node_modules/playwright/package.json';
export const CHROME =
  process.env.SMSTUNNEL_CHROME ||
  'C:/Users/narci/AppData/Local/ms-playwright/chromium_headless_shell-1243/chrome-headless-shell-win64/chrome-headless-shell.exe';
export function playwright() {
  return createRequire(PLAYWRIGHT_FROM)('playwright');
}

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export function docker(args, options = {}) {
  return execFileSync('docker', args, { encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], maxBuffer: 64 * 1024 * 1024, ...options });
}

/** One SQL statement on the test store's database; rows as arrays of strings. */
export function sql(project, query) {
  const out = docker(['exec', `${project}-db-1`, 'mariadb', '-uroot', '-popencart', 'opencart', '-N', '-B', '-e', query]);
  return out
    .split('\n')
    .filter((line) => line !== '')
    .map((line) => line.split('\t'));
}

/** A file of the store (in the web container), or '' when missing. */
export function storeFile(project, file) {
  try {
    return docker(['exec', `${project}-web-1`, 'sh', '-c', `cat ${file} 2>/dev/null || true`]);
  } catch {
    return '';
  }
}

/** The fake SMSTunnel API, started on a free port of 127.0.0.1. */
export async function startFakeApi() {
  const portFile = path.join(HERE, 'results', `fake-port-${process.pid}.txt`);
  fs.mkdirSync(path.dirname(portFile), { recursive: true });
  fs.rmSync(portFile, { force: true });
  const log = fs.openSync(path.join(HERE, 'results', 'fake-api.log'), 'a');
  const child = spawn(process.execPath, [path.join(REPO, 'tests', 'fake-api', 'server.mjs'), '--port', '0', '--port-file', portFile], {
    stdio: ['ignore', log, log],
  });
  for (let i = 0; i < 100 && !(fs.existsSync(portFile) && fs.readFileSync(portFile, 'utf8')); i++) await sleep(100);
  const port = Number(fs.readFileSync(portFile, 'utf8'));
  fs.rmSync(portFile, { force: true });
  const base = `http://127.0.0.1:${port}`;
  const call = async (pathname, body) => {
    const response = await fetch(base + pathname, body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    return response.json();
  };
  return {
    port,
    url: base,
    fromDocker: `http://host.docker.internal:${port}`,
    child,
    reset: () => call('/__reset', {}),
    control: (body) => call('/__control', body),
    requests: async () => (await call('/__requests')).requests,
    sends: async () => (await call('/__requests')).requests.filter((r) => r.method === 'POST' && r.path === '/api/v1/sms/send'),
    async stop() {
      try {
        await call('/__shutdown', {});
      } catch {
        // already stopped
      }
      for (let i = 0; i < 30 && child.exitCode === null; i++) await sleep(100);
      if (child.exitCode === null) child.kill();
    },
  };
}

/** Waits until the store answers with a page. */
export async function waitForStore(base, timeout = 240000) {
  const end = Date.now() + timeout;
  while (Date.now() < end) {
    try {
      const response = await fetch(base + '/');
      if (response.status === 200) return true;
    } catch {
      // not yet
    }
    await sleep(2000);
  }
  return false;
}

/** A small report of checks. */
export class Report {
  constructor(label) {
    this.label = label;
    this.checks = [];
  }

  ok(name, condition, detail = '') {
    const pass = Boolean(condition);
    this.checks.push({ name, pass, detail: String(detail ?? '').slice(0, 500) });
    console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? `  (${String(detail).slice(0, 300)})` : ''}`);
    return pass;
  }

  get failed() {
    return this.checks.filter((c) => !c.pass).length;
  }

  summary() {
    return `${this.label}: ${this.checks.length - this.failed}/${this.checks.length} checks passed`;
  }
}
