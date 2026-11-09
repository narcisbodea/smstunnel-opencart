// Renders the OpenCart Marketplace images from HTML: the thumbnail (260 x 152) and the banner (710 x 380),
// in SMSTunnel's colours with the site's logo. Writes listing/marketplace-thumb.png and
// listing/marketplace-banner.png.
//
//   node tools/listing-images.mjs [path/to/logo.svg]
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { CHROME, playwright } from '../tests/e2e/lib.mjs';

const REPO = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const logoFile = process.argv[2] || 'D:/ANDROID/smstunnel-landing/public/logo.svg';
const logo = `data:image/svg+xml;base64,${fs.readFileSync(logoFile).toString('base64')}`;

const css = `
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', Arial, Helvetica, sans-serif; background: #2240ae; color: #ffffff; overflow: hidden; }
  .light { color: #a9c8ff; }
`;

const thumb = `<!doctype html><html><head><meta charset="utf-8"><style>${css}
  .wrap { width: 260px; height: 152px; display: flex; align-items: center; gap: 12px; padding: 0 16px; }
  img { width: 74px; height: 74px; flex: none; }
  h1 { font-size: 21px; line-height: 1.1; font-weight: 700; }
  p { font-size: 12.5px; line-height: 1.3; margin-top: 6px; }
</style></head><body><div class="wrap"><img src="${logo}"><div>
  <h1>SMSTunnel<br><span class="light">for OpenCart</span></h1>
  <p>Order texts from your own phone</p>
</div></div></body></html>`;

const banner = `<!doctype html><html><head><meta charset="utf-8"><style>${css}
  .wrap { width: 710px; height: 380px; padding: 38px 44px; display: flex; flex-direction: column; }
  .top { display: flex; align-items: center; gap: 22px; }
  img { width: 108px; height: 108px; flex: none; }
  h1 { font-size: 40px; line-height: 1.05; font-weight: 700; }
  .sub { font-size: 17px; margin-top: 8px; white-space: nowrap; }
  ul { list-style: none; margin-top: 30px; font-size: 18px; line-height: 1.55; }
  li::before { content: '\\2713'; color: #a9c8ff; font-weight: 700; margin-right: 10px; }
  .foot { margin-top: auto; font-size: 14px; color: #c9dbff; }
</style></head><body><div class="wrap">
  <div class="top"><img src="${logo}"><div>
    <h1>SMSTunnel <span class="light">for OpenCart</span></h1>
    <div class="sub">Order texts from your own Android phone and your own number</div>
  </div></div>
  <ul>
    <li>New order: a text to you, on up to 5 numbers</li>
    <li>Shipped, complete, canceled: a text to the customer</li>
    <li>Checkout never waits: short timeouts, Send again</li>
  </ul>
  <div class="foot">OpenCart 3.0, 4.0.2 and 4.1 &middot; Free &middot; GPL</div>
</div></body></html>`;

const { chromium } = playwright();
const browser = await chromium.launch({ executablePath: CHROME, headless: true });
try {
  for (const [html, width, height, file] of [
    [thumb, 260, 152, 'marketplace-thumb.png'],
    [banner, 710, 380, 'marketplace-banner.png'],
  ]) {
    const page = await browser.newPage({ viewport: { width, height }, deviceScaleFactor: 1 });
    await page.setContent(html, { waitUntil: 'load' });
    await page.screenshot({ path: path.join(REPO, 'listing', file), clip: { x: 0, y: 0, width, height } });
    console.log(`listing/${file} ${width}x${height}`);
    await page.close();
  }
} finally {
  await browser.close();
}
