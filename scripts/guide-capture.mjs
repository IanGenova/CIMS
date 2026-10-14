/**
 * Screenshots for the user guide.
 *
 * The in-app browser pane returns 800px JPEGs whatever viewport you ask for,
 * which is about 0.56x and the wrong format: a guide prints each capture at a
 * fixed physical width and needs roughly 192 DPI there, so a 1x JPEG looks soft
 * beside 10.5pt text and damages thin lines and small labels. This drives the
 * Chrome already installed on the machine at deviceScaleFactor 2 and writes PNG.
 *
 * Two frames, fixed for every shot so pages do not disagree with each other:
 *   desktop  1440 CSS px  ->  2880px file
 *   mobile    375 CSS px  ->   750px file
 *
 * Signing in is the user's job, not this script's. It uses a persistent Chrome
 * profile: once a person has signed in once in that window, the session is in
 * the profile and every later run reuses it without a password going anywhere
 * near this file.
 *
 *   node scripts/guide-capture.mjs signin     open the window to sign in
 *   node scripts/guide-capture.mjs probe      prove the frame and format
 *   node scripts/guide-capture.mjs shots x.json
 */
import { createRequire } from 'node:module';
import { mkdirSync, existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const require = createRequire(
  'file:///C:/Users/User/AppData/Local/Temp/claude/D--Genova-PROJECTS-CIMS-FIXED-SOURCE-CIMS/e74e6929-16a2-4904-a2df-862ae2a4424f/scratchpad/capture/package.json'
);
const puppeteer = require('puppeteer-core');

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PROFILE = 'C:/Users/User/AppData/Local/Temp/claude/D--Genova-PROJECTS-CIMS-FIXED-SOURCE-CIMS/e74e6929-16a2-4904-a2df-862ae2a4424f/scratchpad/capture/profile';
const OUT = resolve('docs/guide/images');
const BASE = 'http://localhost:8000';

const FRAMES = {
  desktop: { width: 1440, height: 900 },
  mobile: { width: 375, height: 812, isMobile: true, hasTouch: true },
};

mkdirSync(PROFILE, { recursive: true });
mkdirSync(OUT, { recursive: true });

async function browser({ headless }) {
  // Two flags that must not be set when capturing:
  //
  // --force-device-scale-factor scales what is drawn on the physical screen, so
  // the sign-in window opened at double size with the form off the bottom edge.
  // The 2x that matters is per-page, from setViewport({ deviceScaleFactor: 2 }),
  // which changes the captured pixels and not the window a person has to use.
  //
  // --window-size fights setViewport: with it set, a page asked to lay out at
  // 1440 reported innerWidth 960 while still writing a 2880px file, so the
  // capture was a narrow layout blown up rather than a desktop one. It is only
  // useful for the window a human looks at.
  const args = ['--hide-scrollbars', '--no-first-run'];
  if (!headless) {
    args.push('--window-size=1280,900');
  }

  return puppeteer.launch({
    executablePath: CHROME,
    userDataDir: PROFILE,
    headless,
    defaultViewport: null,
    args,
  });
}

/** Is there a session in the profile, or does the app bounce us to /login? */
async function signedIn(page) {
  await page.goto(`${BASE}/app/dashboard`, { waitUntil: 'networkidle2', timeout: 60000 });
  return !page.url().includes('/login');
}

const mode = process.argv[2] || 'probe';

if (mode === 'signin') {
  const b = await browser({ headless: false });
  const page = (await b.pages())[0] ?? (await b.newPage());
  await page.setViewport({ ...FRAMES.desktop, deviceScaleFactor: 2 });
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle2' });
  console.log('Chrome is open. Sign in there, then leave the window open.');
  console.log('Waiting for the dashboard (10 minutes)...');
  try {
    await page.waitForFunction(() => !location.pathname.includes('/login'),
      { timeout: 600000, polling: 2000 });
    console.log('Signed in. The session is saved in the profile; this window can be closed.');
  } catch {
    console.log('No sign-in detected within 10 minutes.');
  }
  await b.close();
  process.exit(0);
}

if (mode === 'probe') {
  // The §0 check: one capture, and prove the file is twice the CSS width and a PNG.
  const b = await browser({ headless: 'new' });
  const page = await b.newPage();
  await page.setViewport({ ...FRAMES.desktop, deviceScaleFactor: 2 });
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle2' });
  // Measure the layout before the shot, not after, and report both so the
  // ratio can be checked rather than assumed.
  const m = await page.evaluate(() => ({ css: innerWidth, dpr: devicePixelRatio }));
  const file = `${OUT}/_probe-signin.png`;
  const buf = Buffer.from(await page.screenshot({ path: file, type: 'png', encoding: 'binary' }));
  const px = buf.readUInt32BE(16);
  console.log(JSON.stringify({
    file, cssWidth: m.css, devicePixelRatio: m.dpr, filePixelWidth: px,
    ratio: +(px / m.css).toFixed(2),
    verdict: px === m.css * 2 ? 'PASS - 2x' : 'FAIL - not 2x',
  }, null, 2));
  await b.close();
  process.exit(0);
}

if (mode === 'shots') {
  const planPath = process.argv[3];
  if (!planPath || !existsSync(planPath)) {
    console.error('usage: shots <plan.json>');
    process.exit(2);
  }
  const plan = JSON.parse(readFileSync(planPath, 'utf8'));
  const b = await browser({ headless: 'new' });
  const page = await b.newPage();

  await page.setViewport({ ...FRAMES.desktop, deviceScaleFactor: 2 });
  if (!(await signedIn(page))) {
    console.error('Not signed in. Run: node scripts/guide-capture.mjs signin');
    await b.close();
    process.exit(3);
  }

  const taken = [];
  for (const shot of plan) {
    for (const frameName of shot.frames ?? ['desktop']) {
      await page.setViewport({ ...FRAMES[frameName], deviceScaleFactor: 2 });
      await page.goto(BASE + shot.path, { waitUntil: 'networkidle2', timeout: 60000 });
      // The SPA paints after its own fetches settle; a fixed settle beats a
      // guess at which selector means "ready" on forty different screens.
      await new Promise((r) => setTimeout(r, shot.settle ?? 3500));
      if (shot.script) {
        await page.evaluate(shot.script);
        await new Promise((r) => setTimeout(r, shot.after ?? 2500));
      }
      const name = `${shot.name}-${frameName}.png`;
      await page.screenshot({ path: `${OUT}/${name}`, type: 'png', fullPage: !!shot.fullPage });
      taken.push(name);
      console.log('captured', name);
    }
  }
  console.log(JSON.stringify({ taken: taken.length }, null, 2));
  await b.close();
  process.exit(0);
}
