// `make brand-png` (docs/brand.md): renders the PNGs and the ICO of public/brand/ from their SVG sources with the Chromium of
// the tools image. Tooling only, never runtime. The repo is mounted at /repo; the default share card's SVG comes from
// `app:brand:og-card` (var/brand/og-default.svg), written by the make target before this runs.
import { createRequire } from 'module';
import fs from 'fs';

const require = createRequire('/usr/local/lib/node_modules/lighthouse/');
const puppeteer = require('puppeteer-core');

const BRAND = '/repo/public/brand';
const FONT = 'file:///repo/assets/fonts/hanken-grotesk-latin-wght-normal.woff2';
const PRIMARY = '#3a3fb0';

// name => [width, height, html body]; `ground` null = transparent
const img = (src, size, ground, inset = 0) =>
    [size, size, ground, `<img src="file://${BRAND}/${src}" style="position:absolute;left:${inset}px;top:${inset}px;width:${size - 2 * inset}px;height:${size - 2 * inset}px">`];
const jobs = {
    'favicon-16.png': img('favicon.svg', 16, null),
    'favicon-32.png': img('favicon.svg', 32, null),
    'favicon-48.png': img('favicon.svg', 48, null),
    // iOS rounds the corners itself and shows transparency as black: the mark on a full ground.
    'apple-touch-icon.png': img('favicon.svg', 180, PRIMARY),
    'icon-192.png': img('favicon.svg', 192, null),
    'icon-512.png': img('favicon.svg', 512, null),
    // Maskable: a full ground, the pin inside the central 80 % circle that every mask keeps.
    'icon-maskable-192.png': img('favicon.svg', 192, PRIMARY, 19.2),
    'icon-maskable-512.png': img('favicon.svg', 512, PRIMARY, 51.2),
    // Mails do not show SVG: the dark lockup at twice its 215 × 24 px, for the primary band of the mail header.
    'mail-logo.png': [430, 48, null, `<img src="file://${BRAND}/logo-dark.svg" style="position:absolute;left:0;top:0;width:430px;height:48px">`],
    'og-default.png': [1200, 630, PRIMARY, fs.readFileSync('/repo/var/brand/og-default.svg', 'utf8')],
};

const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', args: ['--no-sandbox', '--allow-file-access-from-files', '--force-color-profile=srgb', '--font-render-hinting=none'] });
const page = await browser.newPage();
const png = {};
for (const [name, [w, h, ground, body]] of Object.entries(jobs)) {
    const html = `<!doctype html><style>@font-face{font-family:'Hanken Grotesk';src:url(${FONT}) format('woff2');font-weight:100 900}
html,body{margin:0;width:${w}px;height:${h}px;overflow:hidden;background:${ground ?? 'transparent'}}svg{display:block}</style>
<body><div style="position:relative;width:${w}px;height:${h}px">${body}</div></body>`;
    fs.writeFileSync('/tmp/brand.html', html);
    await page.setViewport({ width: w, height: h, deviceScaleFactor: 1 });
    await page.goto('file:///tmp/brand.html', { waitUntil: 'networkidle0' });
    await page.evaluate(() => document.fonts.ready);
    png[name] = await page.screenshot({ type: 'png', omitBackground: ground === null, clip: { x: 0, y: 0, width: w, height: h } });
    if (!name.startsWith('favicon-')) {
        fs.writeFileSync(`${BRAND}/${name}`, png[name]);
        console.log(`public/brand/${name} ${w}×${h}`);
    }
}
await browser.close();

// favicon.ico: the 16, 32 and 48 px PNGs in one ICO (PNG entries, which every current browser reads).
const sizes = [16, 32, 48];
const head = Buffer.alloc(6 + 16 * sizes.length);
head.writeUInt16LE(0, 0); head.writeUInt16LE(1, 2); head.writeUInt16LE(sizes.length, 4);
let offset = head.length;
sizes.forEach((s, i) => {
    const data = png[`favicon-${s}.png`]; const e = 6 + 16 * i;
    head.writeUInt8(s, e); head.writeUInt8(s, e + 1); head.writeUInt8(0, e + 2); head.writeUInt8(0, e + 3);
    head.writeUInt16LE(1, e + 4); head.writeUInt16LE(32, e + 6); head.writeUInt32LE(data.length, e + 8); head.writeUInt32LE(offset, e + 12);
    offset += data.length;
});
fs.writeFileSync(`${BRAND}/favicon.ico`, Buffer.concat([head, ...sizes.map((s) => png[`favicon-${s}.png`])]));
console.log('public/brand/favicon.ico 16, 32, 48');
