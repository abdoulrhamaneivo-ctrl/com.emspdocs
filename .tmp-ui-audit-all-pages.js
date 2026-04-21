const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const baseUrl = 'http://127.0.0.1:8099';
const widths = [390, 1024];

const rootPages = fs.readdirSync('.').filter(f => f.endsWith('.php')).map(f => '/' + f);
const adminPages = fs.readdirSync('./admin').filter(f => f.endsWith('.php')).map(f => '/admin/' + f);
const routes = [...rootPages, ...adminPages];

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();
  const issues = [];

  for (const route of routes) {
    for (const width of widths) {
      await page.setViewportSize({ width, height: 900 });
      try {
        const response = await page.goto(baseUrl + route, { waitUntil: 'domcontentloaded', timeout: 45000 });
        const status = response ? response.status() : null;
        const finalUrl = page.url();
        await page.waitForTimeout(250);
        const audit = await page.evaluate(() => {
          const root = document.documentElement;
          const overflow = root.scrollWidth > root.clientWidth + 1;
          const tooSmallCount = (() => {
            let n = 0;
            document.querySelectorAll('body *').forEach(el => {
              if (n > 0) return;
              const cs = getComputedStyle(el);
              if (cs.display === 'none' || cs.visibility === 'hidden') return;
              const txt = (el.textContent || '').replace(/\s+/g, ' ').trim();
              if (txt.length < 2) return;
              const fs = parseFloat(cs.fontSize || '0');
              if (Number.isFinite(fs) && fs < 13) n += 1;
            });
            return n;
          })();
          const missingAltCount = document.querySelectorAll('img:not([alt])').length;
          return { overflow, tooSmallCount, missingAltCount, scrollW: root.scrollWidth, clientW: root.clientWidth };
        });

        if (audit.overflow || audit.tooSmallCount > 0 || audit.missingAltCount > 0) {
          issues.push({ route, width, status, finalUrl, ...audit });
        }
      } catch (err) {
        issues.push({ route, width, error: String(err.message || err) });
      }
    }
  }

  await browser.close();

  console.log(JSON.stringify({
    totalRoutes: routes.length,
    totalChecks: routes.length * widths.length,
    issueCount: issues.length,
    issues
  }, null, 2));
})();

