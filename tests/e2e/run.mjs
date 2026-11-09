// End-to-end test of SMSTunnel for OpenCart on a throwaway Docker store (tests/docker/docker-compose.yml)
// against the FAKE SMSTunnel API (tests/fake-api/server.mjs). Nothing goes to smstunnel.io.
//
//   node tests/e2e/run.mjs --oc 3.0.5.1                       (project smst-int-oc3, port 8731, PHP 8.3)
//   node tests/e2e/run.mjs --oc 4.0.2.3                       (project smst-int-oc4, port 8741, PHP 8.2)
//   node tests/e2e/run.mjs --oc 4.1.0.4                       (project smst-int-oc4, port 8741, PHP 8.3)
//   options: --project <name> --port <n> --image <php apache image> --keep (leave the store running)
//            --reuse (use a store already running) --shots (listing screenshots into listing/)
//
// What it does, like a store owner would: uploads the .ocmod.zip in Extensions > Installer, installs the
// module, saves the settings against the fake API, sends a test text, places real guest orders in the
// storefront (cash on delivery), changes their status in the admin, and checks the requests the fake API
// received: the texts, no duplicates, nothing for unticked statuses or bad numbers. Also: SMSTunnel down,
// unreachable or not answering during checkout, a rate-limited text sent again, the phone's delivery
// state, masked numbers, the error log, uninstall.
import fs from 'node:fs';
import path from 'node:path';
import { ADMIN_PASSWORD, ADMIN_USER, CHROME, FAKE_KEY, HERE, REPO, Report, docker, playwright, sleep, sql, startFakeApi, storeFile, waitForStore } from './lib.mjs';

const args = Object.fromEntries(
  process.argv.slice(2).reduce((pairs, value, i, all) => (value.startsWith('--') ? [...pairs, [value.slice(2), all[i + 1] && !all[i + 1].startsWith('--') ? all[i + 1] : true]] : pairs), []),
);
const version = String(args.oc || '3.0.5.1');
const major = version.startsWith('3') ? 3 : 4;
const project = String(args.project || (major === 3 ? 'smst-int-oc3' : 'smst-int-oc4'));
if (!project.startsWith('smst-int-')) throw new Error('The compose project name must start with smst-int-.');
const port = Number(args.port || (major === 3 ? 8731 : 8741));
const image = String(args.image || (version.startsWith('4.0') ? 'wordpress:php8.2-apache' : 'wordpress:latest'));
const base = `http://localhost:${port}`;
const compose = path.join(REPO, 'tests', 'docker', 'docker-compose.yml');
const resultsDir = path.join(HERE, 'results');
const shotsDir = path.join(REPO, 'listing');
fs.mkdirSync(resultsDir, { recursive: true });

const VERSION = fs.readFileSync(path.join(REPO, 'src', 'core', 'smstunnel', 'Version.php'), 'utf8').match(/const NUMBER = '([^']+)'/)[1];
const OWNER = ['+14155550100', '+14155550101'];
const TEST_NUMBER = '+14155550199';
const PHONE_ONLINE = '6650c0ffee0000000000a001';
const PHONE_OFFLINE = '6650c0ffee0000000000a002';
const report = new Report(`OpenCart ${version} (${image})`);
const timings = {};

// The official release zip.
const zip = path.join(REPO, '.upstream', `zip-${version}`, `opencart-${version}.zip`);
if (!fs.existsSync(zip)) {
  fs.mkdirSync(path.dirname(zip), { recursive: true });
  console.log(`downloading OpenCart ${version}`);
  const response = await fetch(`https://github.com/opencart/opencart/releases/download/${version}/opencart-${version}.zip`);
  if (!response.ok) throw new Error(`OpenCart ${version}: HTTP ${response.status}`);
  fs.writeFileSync(zip, Buffer.from(await response.arrayBuffer()));
}
const pkg = major === 3 ? path.join(REPO, 'build', 'smstunnel-oc3.ocmod.zip') : path.join(REPO, 'build', 'smstunnel-oc4', 'smstunnel.ocmod.zip');
if (!fs.existsSync(pkg)) throw new Error(`${pkg} is missing: run bash tools/build.sh first.`);

const env = { ...process.env, OC_ZIP: zip.replace(/\\/g, '/'), OC_IMAGE: image, OC_PORT: String(port) };
const up = () => docker(['compose', '-p', project, '-f', compose, 'up', '-d'], { env });
const down = () => docker(['compose', '-p', project, '-f', compose, 'down', '-v'], { env });

const fake = await startFakeApi();
console.log(`fake SMSTunnel API on ${fake.url} (${fake.fromDocker} from the store)`);
const { chromium } = playwright();
let browser;
let admin;
let shopper;
let token = '';
let apiIpAllowed = false;

// Routes that differ between OpenCart 3.0 and 4.x.
const R =
  major === 3
    ? {
        settings: 'extension/module/smstunnel',
        order: (id) => `sale/order/info&order_id=${id}`,
        comment: '#input-comment',
      }
    : {
        settings: 'extension/smstunnel/module/smstunnel',
        order: (id) => `sale/order.info&order_id=${id}`,
        comment: '#input-history',
      };
const adminUrl = (route) => `${base}/admin/index.php?route=${route}&user_token=${token}`;

try {
  if (!args.reuse) {
    try {
      down();
    } catch {
      // nothing to remove
    }
    up();
  }
  report.ok('store answers', await waitForStore(base), base);
  // Errors go to the error log only (checked at the end); OpenCart 4 asks for a telephone at checkout.
  sql(project, "UPDATE oc_setting SET value = '0' WHERE `key` = 'config_error_display'");
  if (major === 4) {
    sql(project, "DELETE FROM oc_setting WHERE store_id = 0 AND `key` IN ('config_telephone_display', 'config_telephone_required')");
    sql(project, "INSERT INTO oc_setting (store_id, code, `key`, value, serialized) VALUES (0, 'config', 'config_telephone_display', '1', 0), (0, 'config', 'config_telephone_required', '1', 0)");
  }
  sql(project, 'UPDATE oc_product SET quantity = 1000, status = 1 WHERE product_id = 40');
  docker(['exec', '-u', 'www-data', `${project}-web-1`, 'sh', '-c', ': > /var/www/html/system/storage/logs/error.log']);

  browser = await chromium.launch({ executablePath: CHROME, headless: true });
  admin = await (await browser.newContext({ viewport: { width: 1360, height: 900 } })).newPage();
  admin.on('dialog', (dialog) => dialog.accept());
  shopper = await (await browser.newContext({ viewport: { width: 1300, height: 1000 } })).newPage();

  // 1. Install from the zip, like a store owner.
  await login();
  const installed = await installPackage();
  report.ok('package installs from Extensions > Installer', installed.ok, installed.detail);
  await installModule();
  report.ok('module installs: events registered', sql(project, "SELECT COUNT(*) FROM oc_event WHERE code LIKE 'smstunnel%' AND status = 1")[0][0] === (major === 3 ? '2' : '4'));
  report.ok('module installs: table of texts created', sql(project, "SHOW TABLES LIKE 'oc_smstunnel_log'").length === 1);
  const statuses = Object.fromEntries(sql(project, 'SELECT name, order_status_id FROM oc_order_status WHERE language_id = 1'));
  const defaults = JSON.parse(sql(project, "SELECT value FROM oc_setting WHERE `key` = 'module_smstunnel_customer_statuses'")[0][0]);
  report.ok(
    'module installs: Shipped, Complete, Canceled, Refunded ticked',
    JSON.stringify(defaults) === JSON.stringify([statuses.Shipped, statuses.Complete, statuses.Canceled, statuses.Refunded].map(Number).sort((a, b) => a - b)),
    JSON.stringify(defaults),
  );

  if (major === 3) {
    // Installed while the store ran a PHP older than 7.1, where install() sets nothing up: on a newer PHP,
    // opening the SMSTunnel page sets the module up.
    sql(project, "DELETE FROM oc_event WHERE code LIKE 'smstunnel%'");
    sql(project, "DELETE FROM oc_setting WHERE code = 'module_smstunnel'");
    sql(project, 'DROP TABLE oc_smstunnel_log');
    await admin.goto(adminUrl(R.settings));
    report.ok(
      'OpenCart 3: installed under an old PHP, the page sets it up (events, table, settings)',
      (await admin.isVisible('#smstunnel-status ol.smstunnel-steps')) &&
        sql(project, "SELECT COUNT(*) FROM oc_event WHERE code LIKE 'smstunnel%' AND status = 1")[0][0] === '2' &&
        sql(project, "SHOW TABLES LIKE 'oc_smstunnel_log'").length === 1 &&
        sql(project, "SELECT COUNT(*) FROM oc_setting WHERE `key` = 'module_smstunnel_customer_statuses'")[0][0] === '1',
    );
  }

  // 2. Settings.
  await admin.goto(adminUrl(R.settings));
  report.ok('settings page opens, with the first steps', await admin.isVisible('#smstunnel-status ol.smstunnel-steps'));
  await saveSettings({ api_key: FAKE_KEY, api_url: fake.fromDocker, owner_numbers: '+1 415 555 0100, (415) 555-0101', country: 'US' });
  report.ok('settings saved', /saved/i.test(await flash()), await flash());
  const html = await admin.content();
  report.ok('the API key never comes back to the page', !html.includes(FAKE_KEY) && (await admin.inputValue('#input-api-key')) === '');
  report.ok('the key shows masked', html.includes('sk_test_…0001'));
  const savedOwner = sql(project, "SELECT value FROM oc_setting WHERE `key` = 'module_smstunnel_owner_numbers'")[0][0];
  report.ok('owner numbers saved in international format', savedOwner === OWNER.join(','), savedOwner);
  report.ok('phones listed from SMSTunnel, online and offline', (await admin.locator('.smstunnel-phones tbody tr').count()) === 2 && /Online/.test(await admin.innerText('.smstunnel-phones')) && /Offline/.test(await admin.innerText('.smstunnel-phones')));
  const devicesCall = (await fake.requests()).find((r) => r.path === '/api/v1/devices');
  report.ok('phones read with GET /api/v1/devices and the key', Boolean(devicesCall) && devicesCall.keyOk && devicesCall.userAgent.startsWith(`SMSTunnel-OpenCart/${VERSION} (OpenCart `), devicesCall && devicesCall.userAgent);
  await saveSettings({ device_id: PHONE_ONLINE });
  report.ok('a phone can be picked from the list', sql(project, "SELECT value FROM oc_setting WHERE `key` = 'module_smstunnel_device_id'")[0][0] === PHONE_ONLINE);
  await saveSettings({ owner_numbers: '12, +1 900 555 0123' });
  report.ok('a bad owner number is refused, with the numbers named', /12, \+1 900 555 0123/.test(await admin.innerText('#form-smstunnel')) && sql(project, "SELECT value FROM oc_setting WHERE `key` = 'module_smstunnel_owner_numbers'")[0][0] === OWNER.join(','));
  await admin.goto(adminUrl(R.settings));
  if (major === 4) {
    report.ok('OpenCart 4: no warning while checkout asks for a telephone', !(await admin.isVisible('#smstunnel-telephone-off')));
  }

  // 3. Test text, to an online phone and to an offline one.
  await fake.reset();
  const testFlash = await sendTestText(TEST_NUMBER);
  report.ok('test text: the owner is told it went out', /Test text sent to \+14155550199/.test(testFlash), testFlash);
  let sends = await fake.sends();
  report.ok(
    'test text: one request, as the SDK sends it',
    sends.length === 1 && sends[0].keyOk && sends[0].contentType === 'application/json' && sends[0].accept === 'application/json' && sends[0].body.to === TEST_NUMBER && sends[0].body.deviceId === PHONE_ONLINE && sends[0].body.defaultCountryCode === '+1' && /^This is a test text from Your Store\./.test(sends[0].body.message) && Object.keys(sends[0].body).sort().join() === 'defaultCountryCode,deviceId,message,to',
    sends[0] && JSON.stringify(sends[0].body),
  );
  await saveSettings({ device_id: PHONE_OFFLINE });
  const queuedFlash = await sendTestText(TEST_NUMBER);
  report.ok('test text to an offline phone: the owner is told it waits', /offline/i.test(queuedFlash), queuedFlash);
  await saveSettings({ device_id: PHONE_ONLINE });

  // 4. A guest order in the storefront: a text to each owner number, none to the customer (Pending).
  await fake.reset();
  const order1 = await placeOrder({ firstname: 'Ana', lastname: 'Pop', telephone: '(415) 555-0123' });
  report.ok('storefront order placed', order1.id > 0, `#${order1.id} in ${order1.ms} ms`);
  sends = await fake.sends();
  const total = sql(project, `SELECT total FROM oc_order WHERE order_id = ${order1.id}`)[0][0];
  report.ok('new order: one text to each owner number', sends.map((s) => s.body.to).join() === OWNER.join(), sends.map((s) => s.body.to).join());
  report.ok(
    'new order: the owner text',
    sends[0] && sends[0].body.message === `Your Store: new order #${order1.id} from Ana Pop, $${Number(total).toFixed(2)}.`,
    sends[0] && sends[0].body.message,
  );
  report.ok('new order: Pending is not ticked, no customer text', sends.length === 2);

  // 5. The admin changes the status: Shipped (ticked, with the comment), again, Processing (not ticked), Complete.
  await setStatus(order1.id, 'Shipped', { comment: 'Tracking 1Z999AA10', notify: true });
  sends = await fake.sends();
  report.ok('shipped: the customer gets a text at the order telephone', sends.length === 3 && sends[2].body.to === '+14155550123', sends[2] && sends[2].body.to);
  report.ok('shipped: completed with the delivery country code', sends[2] && sends[2].body.defaultCountryCode === '+1');
  report.ok(
    'shipped: the suggested text, with the comment',
    sends[2] && sends[2].body.message === `Hello Ana, your order #${order1.id} from Your Store is on its way. Tracking 1Z999AA10`,
    sends[2] && sends[2].body.message,
  );
  await setStatus(order1.id, 'Shipped', { comment: 'Shipped again', notify: true });
  report.ok('the same status saved again: no second text', (await fake.sends()).length === 3);
  await setStatus(order1.id, 'Processing');
  report.ok('an unticked status: no text', (await fake.sends()).length === 3);
  await setStatus(order1.id, 'Shipped', { comment: 'Back to shipped', notify: true });
  report.ok('a status already texted: no second text', (await fake.sends()).length === 3);
  await setStatus(order1.id, 'Complete', { comment: 'internal note', notify: false });
  sends = await fake.sends();
  report.ok('complete: a text, the comment not shared without Notify', sends.length === 4 && sends[3].body.message === `Hello Ana, your order #${order1.id} from Your Store is complete. Thank you!`, sends[3] && sends[3].body.message);
  const perRecipient = {};
  for (const s of await fake.sends()) perRecipient[s.body.to] = (perRecipient[s.body.to] || 0) + 1;
  report.ok('no duplicates: the customer got 2 texts, each owner number 1', perRecipient['+14155550123'] === 2 && perRecipient[OWNER[0]] === 1 && perRecipient[OWNER[1]] === 1, JSON.stringify(perRecipient));

  // 6. Numbers that must never be texted: invalid, premium-rate.
  await fake.reset();
  const order2 = await placeOrder({ firstname: 'Ion', lastname: 'Invalid', telephone: '123' });
  await setStatus(order2.id, 'Shipped', { comment: 'x', notify: true });
  const order3 = await placeOrder({ firstname: 'Pat', lastname: 'Premium', telephone: '+1 900 555 0123' });
  await setStatus(order3.id, 'Shipped', { comment: 'x', notify: true });
  sends = await fake.sends();
  report.ok('bad numbers: both orders placed, owner texted for each', order2.id > 0 && order3.id > 0 && sends.filter((s) => OWNER.includes(s.body.to)).length === 4);
  report.ok('bad numbers: nothing sent to them', sends.filter((s) => !OWNER.includes(s.body.to)).length === 0, sends.map((s) => s.body.to).join());
  const skipped = sql(project, `SELECT order_id, status, error FROM oc_smstunnel_log WHERE kind = 'customer' AND order_id IN (${order2.id}, ${order3.id}) ORDER BY order_id`);
  report.ok('bad numbers: skipped, with the reason', JSON.stringify(skipped.map((r) => r.slice(1))) === JSON.stringify([['skipped', 'number:invalid'], ['skipped', 'number:premium']]), JSON.stringify(skipped));

  // 7. SMSTunnel down (refused connection), not answering (hang), unreachable (no route): checkout goes on.
  // OpenCart 4 first, with an error log the store cannot write: OpenCart 4 stops a request on any PHP
  // warning, and writing the failure down would raise one. (OpenCart 3.0 itself fails on PHP 8 when its
  // log cannot be written, with or without this extension, so the check would test OpenCart, not us.)
  await saveSettings({ api_url: 'http://host.docker.internal:1' });
  if (major === 4) {
    const logFile = '/var/www/html/system/storage/logs/error.log';
    docker(['exec', `${project}-web-1`, 'sh', '-c', `chown root:root ${logFile} && chmod 444 ${logFile}`]);
    const orderLocked = await placeOrder({ firstname: 'Locked', lastname: 'Log', telephone: '(415) 555-0128' }).catch((error) => ({ id: 0, ms: 0, error }));
    docker(['exec', `${project}-web-1`, 'sh', '-c', `chown www-data:www-data ${logFile} && chmod 664 ${logFile}`]);
    report.ok('SMSTunnel down and an error log that cannot be written: checkout still completes', orderLocked.id > 0, orderLocked.error ? String(orderLocked.error.message).split('\n')[0] : `${orderLocked.ms} ms`);
  }
  const order4 = await placeOrder({ firstname: 'Down', lastname: 'Test', telephone: '(415) 555-0124' });
  timings.down = order4.ms;
  const log4 = sql(project, `SELECT smstunnel_log_id, status, error FROM oc_smstunnel_log WHERE order_id = ${order4.id} ORDER BY smstunnel_log_id`);
  report.ok('SMSTunnel down: checkout completes', order4.id > 0, `${order4.ms} ms`);
  report.ok('SMSTunnel down: the texts are marked not sent', log4.length === 2 && log4.every((r) => r[1] === 'failed'), JSON.stringify(log4));
  report.ok('SMSTunnel down: after the first failure the second text is not tried', Boolean(log4[1]) && log4[1][2].startsWith('Not tried: Could not reach SMSTunnel'), log4[1] && log4[1][2]);
  await saveSettings({ api_url: fake.fromDocker });
  await fake.control({ sendMode: 'hang' });
  const order5 = await placeOrder({ firstname: 'Slow', lastname: 'Server', telephone: '(415) 555-0125' });
  timings.hang = order5.ms;
  await fake.control({ sendMode: null, mode: 'normal' });
  report.ok('SMSTunnel not answering: checkout completes, bounded by the 8 s timeout', order5.id > 0 && order5.ms < 15000, `${order5.ms} ms`);
  const log5 = sql(project, `SELECT status, LEFT(error, 40) FROM oc_smstunnel_log WHERE order_id = ${order5.id} ORDER BY smstunnel_log_id`);
  report.ok(
    'SMSTunnel not answering: the text that left is "not confirmed" (never resent on its own), the next one not tried',
    log5.length === 2 && log5[0][0] === 'unconfirmed' && log5[0][1].startsWith('No answer from SMSTunnel') && log5[1][0] === 'failed' && log5[1][1].startsWith('Not tried'),
    JSON.stringify(log5),
  );
  await saveSettings({ api_url: 'http://10.255.255.1:3101' });
  const order6 = await placeOrder({ firstname: 'No', lastname: 'Route', telephone: '(415) 555-0126' });
  timings.unreachable = order6.ms;
  report.ok('SMSTunnel unreachable: checkout completes, bounded by the 3 s connect timeout', order6.id > 0 && order6.ms < 9000, `${order6.ms} ms`);
  await saveSettings({ api_url: fake.fromDocker });

  // 8. "Send again" for a text that did not go out, and a rate-limited text.
  await fake.reset();
  await admin.goto(adminUrl(R.settings));
  const failedId = Number(log4[0][0]);
  await clickSendAgain(failedId);
  const againFlash = await flash();
  sends = await fake.sends();
  report.ok('send again: the owner is told, number masked', /Text sent again to \+•••••••••00/.test(againFlash), againFlash);
  report.ok('send again: the same text reached SMSTunnel', sends.length === 1 && sends[0].body.to === OWNER[0] && sends[0].body.message.includes(`#${order4.id}`), sends[0] && JSON.stringify(sends[0].body));
  report.ok('send again: the row keeps no number and no words any more', JSON.stringify(sql(project, `SELECT status, recipient, message IS NULL, attempts FROM oc_smstunnel_log WHERE smstunnel_log_id = ${failedId}`)[0]) === JSON.stringify(['sent', '+•••••••••00', '1', '2']));
  await fake.control({ sendMode: 'rate', times: 1 });
  await setStatus(order1.id, 'Refunded', { comment: '', notify: false });
  const rated = sql(project, `SELECT smstunnel_log_id, status, error FROM oc_smstunnel_log WHERE order_id = ${order1.id} AND order_status_id = ${statuses.Refunded}`)[0];
  report.ok('rate limited (429): the text is marked not sent, with the reason', rated && rated[1] === 'failed' && /^HTTP 429: /.test(rated[2]), rated && rated[2]);
  report.ok('rate limited (429): a failed text keeps its number for Send again', sql(project, `SELECT recipient FROM oc_smstunnel_log WHERE smstunnel_log_id = ${rated[0]}`)[0][0] === '+14155550123');
  await admin.goto(adminUrl(R.settings));
  report.ok('the last failure shows on the settings page', /Too many texts at once/.test(await admin.innerText('#smstunnel-status')));
  await clickSendAgain(Number(rated[0]));
  sends = await fake.sends();
  report.ok('rate limited: Send again delivers it', sends.length === 3 && sends[2].body.message === `Hello Ana, your order #${order1.id} from Your Store has been refunded.`, sends[2] && sends[2].body.message);

  // 9. What the phone did with the texts (GET /api/v1/sms/status/:id), and the list itself. A text is asked
  // about at most every 2 minutes: the clock is moved back 5 minutes for the texts of this run.
  await fake.control({ allTextsStatus: 'delivered', finalStatus: 'delivered' });
  sql(project, 'UPDATE oc_smstunnel_log SET date_modified = DATE_SUB(date_modified, INTERVAL 5 MINUTE)');
  const statusCallsOf = async () => (await fake.requests()).filter((r) => r.path.startsWith('/api/v1/sms/status/'));
  const earlier = (await statusCallsOf()).length;
  await admin.goto(adminUrl(R.settings));
  await admin.waitForFunction(() => /Delivered/.test(document.querySelector('#smstunnel-recent').innerText), null, { timeout: 15000 }).catch(() => {});
  const statusCalls = (await statusCallsOf()).slice(earlier);
  report.ok('delivery state read with GET /api/v1/sms/status/:id, 10 texts at most', statusCalls.length > 0 && statusCalls.length <= 10 && statusCalls.every((r) => r.keyOk && r.method === 'GET'), `${statusCalls.length} calls`);
  report.ok('the list shows Delivered', /Delivered/.test(await admin.innerText('#smstunnel-recent')));
  await admin.goto(adminUrl(R.settings));
  await sleep(1500);
  const asked = new Set(statusCalls.map((r) => r.path));
  const askedAgain = (await statusCallsOf()).slice(earlier + statusCalls.length).filter((r) => asked.has(r.path));
  report.ok('a page shown again soon after asks again about no text', askedAgain.length === 0, `${askedAgain.length} texts asked twice`);
  // The owner's own numbers are in the settings form; the list and the panels never show a number in full.
  const shown = (await admin.locator('#smstunnel-recent').innerHTML()) + (await admin.locator('#smstunnel-status').innerText());
  const fullNumbers = ['4155550123', '4155550100', '4155550101', '4155550124', '4155550125', '4155550126'].filter((n) => shown.includes(n));
  const numbersInTable = (await admin.locator('#smstunnel-recent .smstunnel-number').allInnerTexts()).filter((t) => t.trim() !== '');
  report.ok('the list shows numbers masked to the last 2 digits', numbersInTable.length > 0 && numbersInTable.every((t) => /^\+?•+\d{2}$/.test(t.trim())), numbersInTable.slice(0, 4).join(' '));
  report.ok('no number in full in the list or the panels', fullNumbers.length === 0, fullNumbers.join());
  report.ok('the list links each order to its admin page', (await admin.locator(`#smstunnel-recent a:has-text("#${order1.id}")`).count()) > 0);
  if (args.shots) await listingShots();

  // 10. The error log: our own lines only, no number in full, no key, no PHP notice from the extension.
  const errorLog = storeFile(project, '/var/www/html/system/storage/logs/error.log');
  const ours = errorLog.split('\n').filter((line) => /smstunnel/i.test(line));
  fs.writeFileSync(path.join(resultsDir, `${project}-${version}-error.log`), errorLog);
  report.ok('error log: the failures are there', ours.some((line) => line.includes(`about order ${order4.id}`)), ours[0]);
  report.ok('error log: no number in full, no key', ours.every((line) => !/4155550\d{3}/.test(line) && !line.includes(FAKE_KEY)), ours.find((line) => /4155550\d{3}/.test(line)));
  report.ok('error log: no PHP warning or notice from the extension', !ours.some((line) => /PHP (Warning|Notice|Deprecated|Fatal|Parse)|Exception:|Error:/.test(line) && !/SMSTunnel: /.test(line)), ours.find((line) => /PHP /.test(line)));

  // 11. Uninstall: events, settings and the table go.
  await uninstallModule();
  report.ok('uninstall removes the events', sql(project, "SELECT COUNT(*) FROM oc_event WHERE code LIKE 'smstunnel%'")[0][0] === '0');
  report.ok('uninstall removes the settings', sql(project, "SELECT COUNT(*) FROM oc_setting WHERE code = 'module_smstunnel'")[0][0] === '0');
  report.ok('uninstall drops the table of texts', sql(project, "SHOW TABLES LIKE 'oc_smstunnel_log'").length === 0);
  await fake.reset();
  const order7 = await placeOrder({ firstname: 'After', lastname: 'Uninstall', telephone: '(415) 555-0127' });
  report.ok('after uninstall: checkout works and sends nothing', order7.id > 0 && (await fake.sends()).length === 0);
  if (major === 3) {
    // OpenCart 3.0.3 keeps the permission to the page after uninstalling: opening it sets nothing up again.
    await admin.goto(adminUrl(R.settings));
    report.ok(
      'OpenCart 3: the SMSTunnel page after uninstall sets nothing up',
      sql(project, "SELECT COUNT(*) FROM oc_event WHERE code LIKE 'smstunnel%'")[0][0] === '0' && sql(project, "SHOW TABLES LIKE 'oc_smstunnel_log'").length === 0,
      admin.url().replace(/user_token=[^&]+/, 'user_token=…'),
    );
  }
} catch (error) {
  report.ok('run finished without an exception', false, String(error.stack).split('\n').slice(0, 4).join(' | '));
  if (admin) await admin.screenshot({ path: path.join(resultsDir, `${project}-${version}-admin-error.png`), fullPage: true }).catch(() => {});
  if (shopper) await shopper.screenshot({ path: path.join(resultsDir, `${project}-${version}-shop-error.png`), fullPage: true }).catch(() => {});
} finally {
  report.timings = timings;
  fs.writeFileSync(path.join(resultsDir, `${project}-${version}.json`), JSON.stringify(report, null, 2));
  console.log(report.summary());
  if (browser) await browser.close().catch(() => {});
  await fake.stop();
  if (!args.keep) {
    try {
      down();
      console.log(`${project}: removed (down -v)`);
    } catch (error) {
      console.log(`${project}: could not be removed: ${error.message}`);
    }
  }
  process.exitCode = report.failed ? 1 : 0;
}

// ---------------------------------------------------------------------------------------------------

async function login() {
  await admin.goto(`${base}/admin/`);
  await admin.fill('#input-username', ADMIN_USER);
  await admin.fill('#input-password', ADMIN_PASSWORD);
  await Promise.all([admin.waitForURL(/user_token=/, { timeout: 60000 }), admin.click('button[type=submit]')]);
  token = new URL(admin.url()).searchParams.get('user_token');
}

async function installPackage() {
  await admin.goto(adminUrl('marketplace/installer'));
  await admin.waitForSelector('#button-upload');
  const [chooser] = await Promise.all([admin.waitForEvent('filechooser'), admin.click('#button-upload')]);
  await chooser.setFiles(pkg);
  if (major === 3) {
    await admin.waitForSelector('#progress-text .text-success, #progress-text .text-danger', { timeout: 120000 });
    const text = (await admin.innerText('#progress-text')).trim();
    return { ok: (await admin.locator('#progress-text .text-success').count()) > 0, detail: text };
  }
  await admin.waitForSelector('#alert .alert', { timeout: 60000 });
  const uploaded = (await admin.innerText('#alert')).trim();
  if (!/uploaded/i.test(uploaded)) return { ok: false, detail: uploaded };
  await admin.waitForSelector('#extension tr:has-text("SMSTunnel") .btn-success', { timeout: 30000 });
  await admin.evaluate(() => document.querySelectorAll('#alert .alert').forEach((a) => a.remove()));
  await admin.click('#extension tr:has-text("SMSTunnel") .btn-success');
  await admin.waitForSelector('#extension tr:has-text("SMSTunnel") .btn-warning', { timeout: 120000 });
  return { ok: fs.existsSync(pkg), detail: (await admin.innerText('#alert').catch(() => '')).trim().replace(/\s+/g, ' ') || 'installed' };
}

async function installModule() {
  await admin.goto(adminUrl('marketplace/extension') + '&type=module');
  const row = admin.locator('#extension tr', { hasText: 'SMSTunnel' });
  await row.waitFor({ timeout: 30000 });
  await row.locator(major === 3 ? 'a.btn-success' : '.btn-success').click();
  await admin.locator('#extension tr', { hasText: 'SMSTunnel' }).locator(major === 3 ? 'a.btn-danger' : '.btn-danger').waitFor({ timeout: 30000 });
}

async function uninstallModule() {
  await admin.goto(adminUrl('marketplace/extension') + '&type=module');
  const row = admin.locator('#extension tr', { hasText: 'SMSTunnel' });
  await row.waitFor({ timeout: 30000 });
  await row.locator(major === 3 ? 'a.btn-danger' : '.btn-danger').click();
  await admin.locator('#extension tr', { hasText: 'SMSTunnel' }).locator(major === 3 ? 'a.btn-success' : '.btn-success').waitFor({ timeout: 30000 });
}

/** Fills the settings form (only the fields given) and saves it. */
async function saveSettings(fields) {
  await admin.goto(adminUrl(R.settings));
  for (const [name, value] of Object.entries(fields)) {
    const field = admin.locator(`#form-smstunnel [name="${name}"]`).last();
    const tag = await field.evaluate((el) => el.tagName);
    if (tag === 'SELECT') {
      const options = await field.locator('option').evaluateAll((els) => els.map((o) => o.value));
      if (!options.includes(value)) {
        // The phone list needs a key first; before that the field is a text box.
        throw new Error(`${name}: no option ${value}`);
      }
      await field.selectOption(value);
    } else {
      await field.fill(value);
    }
  }
  await Promise.all([admin.waitForLoadState('load'), admin.click('#button-save')]);
  await admin.waitForLoadState('domcontentloaded');
}

async function flash() {
  return (await admin.locator('#smstunnel-flash').innerText().catch(() => '')).replace(/\s+/g, ' ').replace('×', '').trim();
}

async function sendTestText(number) {
  await admin.goto(adminUrl(R.settings));
  await admin.fill('#input-test-number', number);
  await Promise.all([admin.waitForLoadState('load'), admin.click('#button-test')]);
  return flash();
}

async function clickSendAgain(logId) {
  await admin.goto(adminUrl(R.settings));
  const button = admin.locator(`#smstunnel-recent tr[data-log-id="${logId}"] .smstunnel-resend button`);
  await Promise.all([admin.waitForLoadState('load'), button.click()]);
}

/** Changes an order's status on its admin page, as the store owner does. */
async function setStatus(orderId, statusName, { comment = '', notify = false } = {}) {
  await admin.goto(adminUrl(R.order(orderId)));
  if (major === 3 && !apiIpAllowed) {
    // OpenCart 3.0 calls the store's API from the browser: the browser's address must be allowed once.
    // OpenCart says which address on the order page; a store owner adds it in System > Users > API.
    const warning = admin.locator('.alert-danger', { hasText: /IP/ });
    if (await warning.waitFor({ timeout: 8000 }).then(() => true, () => false)) {
      const ip = ((await warning.innerText()).match(/\b\d{1,3}(?:\.\d{1,3}){3}\b/) || [])[0];
      if (!ip) throw new Error(`no IP address in: ${await warning.innerText()}`);
      const apiId = sql(project, "SELECT value FROM oc_setting WHERE `key` = 'config_api_id' AND store_id = 0")[0][0];
      sql(project, `INSERT INTO oc_api_ip (api_id, ip) VALUES (${Number(apiId)}, '${ip}')`);
      await admin.goto(adminUrl(R.order(orderId)));
    }
    apiIpAllowed = true;
  }
  await admin.waitForSelector('#input-order-status', { timeout: 30000 });
  await admin.selectOption('#input-order-status', { label: statusName });
  if (notify) await admin.check('#input-notify');
  else await admin.uncheck('#input-notify');
  await admin.fill(R.comment, comment);
  await admin.evaluate(() => document.querySelectorAll('.alert-dismissible').forEach((a) => a.remove()));
  await admin.click('#button-history');
  const selector = major === 3 ? '.alert-success, .alert-danger' : '#alert .alert-success, #alert .alert-danger';
  await admin.waitForSelector(selector, { timeout: 60000 });
  const alert = (await admin.innerText(selector)).trim();
  if (!/success/i.test(alert)) throw new Error(`status ${statusName} on order ${orderId}: ${alert}`);
}

/** A guest order of an iPhone, shipped to San Francisco, cash on delivery. Returns { id, ms }. */
async function placeOrder({ firstname, lastname, telephone }) {
  await shopper.context().clearCookies();
  await shopper.goto(`${base}/index.php?route=product/product&product_id=40`);
  await shopper.click('#button-cart');
  await shopper.waitForSelector(major === 3 ? '.alert-success' : '#alert .alert-success', { timeout: 20000 });
  await shopper.goto(`${base}/index.php?route=checkout/checkout`);
  const email = `${firstname.toLowerCase()}.${Date.now()}@example.com`;
  if (major === 3) {
    await openStep('#collapse-checkout-option', 'input[name=account][value=guest]');
    await shopper.check('input[name=account][value=guest]');
    await shopper.click('#button-account');
    await openStep('#collapse-payment-address', '#input-payment-firstname');
    await shopper.fill('#input-payment-firstname', firstname);
    await shopper.fill('#input-payment-lastname', lastname);
    await shopper.fill('#input-payment-email', email);
    await shopper.fill('#input-payment-telephone', telephone);
    await fillAddress('payment');
    await shopper.click('#button-guest');
    await openStep('#collapse-shipping-method', '#button-shipping-method');
    await shopper.click('#button-shipping-method');
    await openStep('#collapse-payment-method', '#button-payment-method');
    await shopper.check('input[name=agree]');
    await shopper.click('#button-payment-method');
    await openStep('#collapse-checkout-confirm', '#button-confirm');
  } else {
    await shopper.waitForSelector('#input-guest', { timeout: 20000 });
    await shopper.check('#input-guest');
    await shopper.fill('#input-firstname', firstname);
    await shopper.fill('#input-lastname', lastname);
    await shopper.fill('#input-email', email);
    await shopper.fill('#input-telephone', telephone);
    const prefix = (await shopper.isVisible('#input-shipping-address-1')) ? 'shipping' : 'payment';
    if (await shopper.isVisible(`#input-${prefix}-firstname`)) {
      await shopper.fill(`#input-${prefix}-firstname`, firstname);
      await shopper.fill(`#input-${prefix}-lastname`, lastname);
    }
    await fillAddress(prefix);
    await shopper.click('#button-register');
    await shopper.waitForSelector('#alert .alert-success', { timeout: 20000 });
    // OpenCart 4.0 ticks no method in these dialogs, 4.1 ticks the first one: tick it either way.
    await shopper.click('#button-shipping-methods');
    await shopper.waitForSelector('#button-shipping-method', { state: 'visible', timeout: 20000 });
    await shopper.check('input[type=radio][name="shipping_method"] >> nth=0');
    await shopper.click('#button-shipping-method');
    await shopper.waitForFunction(() => document.querySelector('#input-shipping-method').value !== '', null, { timeout: 20000 });
    await shopper.click('#button-payment-methods');
    await shopper.waitForSelector('#button-payment-method', { state: 'visible', timeout: 20000 });
    await shopper.check('input[type=radio][name="payment_method"][value^="cod"] >> nth=0');
    await shopper.click('#button-payment-method');
    await shopper.waitForFunction(() => document.querySelector('#input-payment-method').value !== '', null, { timeout: 20000 });
    if (await shopper.isVisible('#input-checkout-agree')) await shopper.check('#input-checkout-agree');
    await shopper.waitForSelector('#checkout-payment #button-confirm', { state: 'visible', timeout: 20000 });
  }
  const confirm = major === 3 ? '#button-confirm' : '#checkout-payment #button-confirm';
  const t0 = Date.now();
  await Promise.all([shopper.waitForURL(/checkout\/success/, { timeout: 90000 }), shopper.click(confirm)]);
  const ms = Date.now() - t0;
  const id = Number(sql(project, `SELECT COALESCE(MAX(order_id), 0) FROM oc_order WHERE order_status_id > 0 AND email = '${email}'`)[0][0]);
  return { id, ms };
}

async function fillAddress(prefix) {
  await shopper.fill(`#input-${prefix}-address-1`, '1 Market Street');
  await shopper.fill(`#input-${prefix}-city`, 'San Francisco');
  await shopper.fill(`#input-${prefix}-postcode`, '94105');
  await shopper.selectOption(`#input-${prefix}-country`, { label: 'United States' });
  await shopper.waitForFunction((p) => [...document.querySelectorAll(`#input-${p}-zone option`)].some((o) => o.textContent.trim() === 'California'), prefix, { timeout: 20000 });
  await shopper.selectOption(`#input-${prefix}-zone`, { label: 'California' });
}

/** OpenCart 3.0's checkout steps are Bootstrap panels; headless, a panel sometimes stays closed: open it. */
async function openStep(panel, field) {
  await shopper.waitForSelector(`${panel} ${field}`, { state: 'attached', timeout: 30000 });
  for (let i = 0; i < 15 && !(await shopper.isVisible(`${panel} ${field}`)); i++) {
    await sleep(400);
    if (!(await shopper.isVisible(`${panel} ${field}`))) {
      await shopper.evaluate((id) => {
        if (!document.querySelector(id).classList.contains('in')) window.jQuery(`a[href='${id}']`).trigger('click');
      }, panel);
    }
  }
  await shopper.waitForSelector(`${panel} ${field}`, { state: 'visible', timeout: 10000 });
}

/**
 * Clean screenshots of the settings page and the recent texts, for the marketplace listing: a fresh list
 * with a test text and two orders (sample names, +1415555xxxx numbers), their texts delivered.
 */
async function listingShots() {
  fs.mkdirSync(shotsDir, { recursive: true });
  const tag = major === 3 ? 'oc3' : 'oc4';
  sql(project, 'DELETE FROM oc_smstunnel_log');
  await fake.reset();
  await sendTestText(OWNER[0]);
  const maria = await placeOrder({ firstname: 'Maria', lastname: 'Garcia', telephone: '(415) 555-0142' });
  const james = await placeOrder({ firstname: 'James', lastname: 'Chen', telephone: '(415) 555-0187' });
  await setStatus(maria.id, 'Shipped', { comment: 'Tracking: 1Z999AA10123456784', notify: true });
  await setStatus(james.id, 'Complete', { comment: '', notify: false });
  await fake.control({ allTextsStatus: 'delivered' });
  sql(project, 'UPDATE oc_smstunnel_log SET date_modified = DATE_SUB(date_modified, INTERVAL 5 MINUTE)');
  await admin.setViewportSize({ width: 1360, height: 900 });
  await admin.goto(adminUrl(R.settings));
  await admin.waitForFunction(() => !document.querySelector('#smstunnel-recent tr[data-awaiting]'), null, { timeout: 15000 }).catch(() => {});
  // Ask about the rest too, so every row shows what the phone did.
  sql(project, 'UPDATE oc_smstunnel_log SET date_modified = DATE_SUB(date_modified, INTERVAL 5 MINUTE)');
  await admin.goto(adminUrl(R.settings));
  await admin.waitForFunction(() => !document.querySelector('#smstunnel-recent tr[data-awaiting]'), null, { timeout: 15000 }).catch(() => {});
  // What a store sees: smstunnel.io as the server, no test-server notice, no failure from the tests above.
  await admin.evaluate(() => {
    document.querySelectorAll('#smstunnel-status .alert').forEach((el) => el.remove());
    const url = document.querySelector('#input-api-url');
    if (url) url.value = 'https://smstunnel.io';
  });
  const shot = async (selector, file) => {
    const box = await admin.$eval(selector, (el) => {
      const r = el.getBoundingClientRect();
      return { x: Math.max(0, r.left + window.scrollX - 12), y: Math.max(0, r.top + window.scrollY - 12), width: r.width + 24, height: r.height + 24 };
    });
    await admin.screenshot({ path: path.join(shotsDir, file), fullPage: true, clip: box });
  };
  await shot('#smstunnel-status', `${tag}-phones-and-test-text.png`);
  await shot('#form-smstunnel', `${tag}-settings.png`);
  await shot('#smstunnel-recent', `${tag}-recent-texts.png`);
  await admin.screenshot({ path: path.join(shotsDir, `${tag}-settings-page.png`), fullPage: false });
}
