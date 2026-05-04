const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.EMSP_BASE || 'http://127.0.0.1/COMPO%20FINAL/emsp_docs';
const OUT = path.join(process.cwd(), 'test-results', 'platform-audit');
fs.mkdirSync(OUT, { recursive: true });

const defaultRoutes = [
  'index.php',
  'institution.php',
  'formations.php',
  'concours.php',
  'bibliotheque.php',
  'mediatheque.php',
  'news-blog.php',
  'faq.php',
  'login.php',
  'register.php',
  'forgot-password.php',
  'admin/index.php',
  'admin/stats.php',
  'admin/settings.php',
  'admin/pending-users.php',
  'admin/pending-documents.php',
  'admin/mediatheque.php',
];
const routes = (process.env.EMSP_ROUTES ? process.env.EMSP_ROUTES.split(',') : defaultRoutes)
  .map(route => route.trim())
  .filter(Boolean);

async function auditRoute(context, route, viewportName) {
  const page = await context.newPage();
  const consoleErrors = [];
  const pageErrors = [];
  page.on('console', msg => {
    if (msg.type() === 'error') consoleErrors.push(msg.text());
  });
  page.on('pageerror', err => pageErrors.push(String(err)));

  const url = `${BASE.replace(/\/$/, '')}/${route}`;
  let status = null;
  let navError = null;
  try {
    const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 8000 });
    status = response ? response.status() : null;
    await page.waitForTimeout(500);
  } catch (error) {
    navError = String(error);
  }

  const metrics = await page.evaluate(() => {
    const bodyText = document.body ? document.body.innerText : '';
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const all = Array.from(document.querySelectorAll('body *'));
    const visible = el => {
      const style = getComputedStyle(el);
      const rect = el.getBoundingClientRect();
      return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
    };
    const selectorFor = el => {
      const id = el.id ? `#${el.id}` : '';
      const cls = String(el.className || '').trim().split(/\s+/).filter(Boolean).slice(0, 3).map(c => `.${c}`).join('');
      return `${el.tagName.toLowerCase()}${id}${cls}`;
    };
    const overflows = all
      .filter(visible)
      .map(el => {
        const r = el.getBoundingClientRect();
        return { el, r };
      })
      .filter(({ el, r }) => !el.closest('.emsp-carousel-track') && (r.right > vw + 2 || r.left < -2))
      .slice(0, 25)
      .map(({ el, r }) => ({ selector: selectorFor(el), text: (el.innerText || '').trim().slice(0, 90), x: r.x, w: r.width, right: r.right }));
    const textOverflow = all
      .filter(el => visible(el) && el.scrollWidth > el.clientWidth + 2 && ['BUTTON', 'A', 'SPAN', 'H1', 'H2', 'H3', 'H4', 'P', 'TD', 'TH', 'LABEL'].includes(el.tagName))
      .slice(0, 25)
      .map(el => ({ selector: selectorFor(el), text: (el.innerText || el.value || '').trim().slice(0, 100), clientWidth: el.clientWidth, scrollWidth: el.scrollWidth }));
    const badLinks = Array.from(document.querySelectorAll('a[href="#"], a:not([href])'))
      .filter(visible)
      .slice(0, 25)
      .map(el => ({ selector: selectorFor(el), text: (el.innerText || '').trim().slice(0, 80) }));
    const emptyButtons = Array.from(document.querySelectorAll('button'))
      .filter(visible)
      .filter(el => !(el.innerText || '').trim() && !el.getAttribute('aria-label') && !el.getAttribute('title'))
      .slice(0, 25)
      .map(el => ({ selector: selectorFor(el) }));
    const brokenImages = Array.from(document.images)
      .filter(img => visible(img) && img.complete && img.naturalWidth === 0 && (img.getAttribute('src') || '').trim() !== '')
      .slice(0, 25)
      .map(img => ({ selector: selectorFor(img), src: img.getAttribute('src') || '' }));
    return {
      title: document.title,
      path: location.pathname,
      viewport: { width: vw, height: vh },
      scrollWidth: Math.max(document.documentElement.scrollWidth, document.body ? document.body.scrollWidth : 0),
      overflowX: Math.max(document.documentElement.scrollWidth, document.body ? document.body.scrollWidth : 0) > vw + 2,
      hasServerError: /Fatal error|Parse error|Warning:|Notice:|mysqli_|Uncaught/i.test(bodyText),
      hasMojibake: /Ã|Â©|Âè|Âé|â€™|â€œ|â€|ðŸ/i.test(bodyText),
      overflows,
      textOverflow,
      badLinks,
      emptyButtons,
      brokenImages,
    };
  }).catch(error => ({ evaluateError: String(error) }));

  const safe = `${viewportName}_${route.replace(/[\/.]/g, '_')}`;
  const screenshot = path.join(OUT, `${safe}.png`);
  await page.screenshot({ path: screenshot, fullPage: false }).catch(() => {});
  await page.close().catch(() => {});
  return { route, url, viewportName, status, navError, consoleErrors, pageErrors, metrics, screenshot };
}

(async () => {
  const launchOptions = { headless: true };
  if (process.env.EMSP_BROWSER_CHANNEL) {
    launchOptions.channel = process.env.EMSP_BROWSER_CHANNEL;
  }
  const browser = await chromium.launch(launchOptions);
  const results = [];
  for (const cfg of [
    { name: 'desktop', viewport: { width: 1440, height: 900 } },
    { name: 'mobile', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true },
  ]) {
    const context = await browser.newContext(cfg);
    for (const route of routes) {
      results.push(await auditRoute(context, route, cfg.name));
    }
    await context.close();
  }
  await browser.close();

  const report = {
    base: BASE,
    generatedAt: new Date().toISOString(),
    results,
    summary: results.map(r => ({
      route: r.route,
      viewport: r.viewportName,
      status: r.status,
      navError: r.navError,
      overflowX: !!r.metrics.overflowX,
      hasServerError: !!r.metrics.hasServerError,
      hasMojibake: !!r.metrics.hasMojibake,
      overflows: r.metrics.overflows ? r.metrics.overflows.length : 0,
      textOverflow: r.metrics.textOverflow ? r.metrics.textOverflow.length : 0,
      badLinks: r.metrics.badLinks ? r.metrics.badLinks.length : 0,
      emptyButtons: r.metrics.emptyButtons ? r.metrics.emptyButtons.length : 0,
      brokenImages: r.metrics.brokenImages ? r.metrics.brokenImages.length : 0,
      consoleErrors: r.consoleErrors.length,
      pageErrors: r.pageErrors.length,
    })),
  };
  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report.summary, null, 2));
})();
