const { chromium } = require('playwright');

const baseUrl = 'http://127.0.0.1:8099';
const routes = [
  '/index.php',
  '/login.php',
  '/register.php',
  '/forgot-password.php',
  '/reset-password.php',
  '/bibliotheque.php',
  '/concours.php',
  '/dashboard.php',
  '/document.php?id=1',
  '/faq.php',
  '/formations.php',
  '/historique.php',
  '/institution.php',
  '/mediatheque.php',
  '/mes-favoris.php',
  '/message.php',
  '/mon-profil.php',
  '/news-blog.php',
  '/news-article.php?id=1',
  '/pending-status.php',
  '/profil-public.php?id=1',
  '/upload.php',
  '/verify-email.php'
];

const widths = [360, 390, 480, 768, 1024, 1280, 1440];

function selectorFor(el) {
  if (!el || !(el instanceof Element)) return '';
  const parts = [];
  let node = el;
  let depth = 0;
  while (node && depth < 4 && node.nodeType === 1) {
    let part = node.tagName.toLowerCase();
    if (node.id) {
      part += `#${node.id}`;
      parts.unshift(part);
      break;
    }
    const cls = (node.className || '').toString().trim().split(/\s+/).filter(Boolean).slice(0, 2);
    if (cls.length) part += '.' + cls.join('.');
    parts.unshift(part);
    node = node.parentElement;
    depth += 1;
  }
  return parts.join(' > ');
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();

  const issues = [];

  for (const route of routes) {
    for (const width of widths) {
      await page.setViewportSize({ width, height: 900 });
      let status = null;
      let finalUrl = '';
      try {
        const response = await page.goto(baseUrl + route, { waitUntil: 'domcontentloaded', timeout: 45000 });
        status = response ? response.status() : null;
        finalUrl = page.url();
        await page.waitForTimeout(350);

        const audit = await page.evaluate(() => {
          const root = document.documentElement;
          const overflow = root.scrollWidth > root.clientWidth + 1;

          const tooSmall = [];
          const all = document.querySelectorAll('body *');
          for (const el of all) {
            if (tooSmall.length >= 10) break;
            const cs = window.getComputedStyle(el);
            if (cs.display === 'none' || cs.visibility === 'hidden') continue;
            if (parseFloat(cs.opacity || '1') === 0) continue;
            const txt = (el.textContent || '').replace(/\s+/g, ' ').trim();
            if (txt.length < 2) continue;
            const fs = parseFloat(cs.fontSize || '0');
            if (!Number.isFinite(fs) || fs >= 13) continue;
            // Skip script/style/non-visual container false positives
            const tag = el.tagName.toLowerCase();
            if (['script', 'style', 'meta', 'link'].includes(tag)) continue;
            tooSmall.push({
              fs,
              text: txt.slice(0, 80),
              selector: (function selectorFor(el) {
                if (!el || !(el instanceof Element)) return '';
                const parts = [];
                let node = el;
                let depth = 0;
                while (node && depth < 4 && node.nodeType === 1) {
                  let part = node.tagName.toLowerCase();
                  if (node.id) {
                    part += `#${node.id}`;
                    parts.unshift(part);
                    break;
                  }
                  const cls = (node.className || '').toString().trim().split(/\s+/).filter(Boolean).slice(0, 2);
                  if (cls.length) part += '.' + cls.join('.');
                  parts.unshift(part);
                  node = node.parentElement;
                  depth += 1;
                }
                return parts.join(' > ');
              })(el)
            });
          }

          const missingAlt = Array.from(document.querySelectorAll('img:not([alt])')).slice(0, 10).map((img) => ({
            src: img.getAttribute('src') || '',
            selector: (function selectorFor(el) {
              if (!el || !(el instanceof Element)) return '';
              const parts = [];
              let node = el;
              let depth = 0;
              while (node && depth < 4 && node.nodeType === 1) {
                let part = node.tagName.toLowerCase();
                if (node.id) {
                  part += `#${node.id}`;
                  parts.unshift(part);
                  break;
                }
                const cls = (node.className || '').toString().trim().split(/\s+/).filter(Boolean).slice(0, 2);
                if (cls.length) part += '.' + cls.join('.');
                parts.unshift(part);
                node = node.parentElement;
                depth += 1;
              }
              return parts.join(' > ');
            })(img)
          }));

          return {
            overflow,
            tooSmall,
            missingAlt,
            scrollW: root.scrollWidth,
            clientW: root.clientWidth
          };
        });

        if (audit.overflow) {
          issues.push({ type: 'overflow', route, width, status, finalUrl, detail: `${audit.scrollW} > ${audit.clientW}` });
        }
        if (audit.tooSmall.length) {
          issues.push({ type: 'font_lt_13', route, width, status, finalUrl, detail: audit.tooSmall });
        }
        if (audit.missingAlt.length) {
          issues.push({ type: 'missing_alt', route, width, status, finalUrl, detail: audit.missingAlt });
        }
      } catch (err) {
        issues.push({ type: 'navigation_error', route, width, status, finalUrl, detail: String(err.message || err) });
      }
    }
  }

  // Mobile nav behavior check on home
  try {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(baseUrl + '/index.php', { waitUntil: 'domcontentloaded', timeout: 45000 });
    await page.waitForTimeout(500);
    const toggler = page.locator('.emsp-navbar-toggler');
    const hasToggler = await toggler.count();
    if (hasToggler > 0) {
      await toggler.first().click();
      await page.waitForTimeout(400);
      const openState = await page.evaluate(() => {
        const offcanvas = document.getElementById('emspMainOffcanvas');
        const shown = !!offcanvas && offcanvas.classList.contains('show');
        const backdrop = document.querySelector('.offcanvas-backdrop.show');
        const backdropOk = !!backdrop && getComputedStyle(backdrop).opacity >= '0.5';
        return { shown, backdropOk };
      });
      if (!openState.shown) {
        issues.push({ type: 'mobile_nav_open_fail', route: '/index.php', width: 390, detail: openState });
      }

      const acc = page.locator('.emsp-offcanvas-accordion-toggle').first();
      if (await acc.count()) {
        const before = await acc.getAttribute('aria-expanded');
        await acc.click();
        await page.waitForTimeout(300);
        const after = await acc.getAttribute('aria-expanded');
        if (before === after) {
          issues.push({ type: 'mobile_nav_accordion_no_toggle', route: '/index.php', width: 390, detail: { before, after } });
        }
      }

      await page.keyboard.press('Escape');
      await page.waitForTimeout(350);
      const closed = await page.evaluate(() => {
        const offcanvas = document.getElementById('emspMainOffcanvas');
        return !!offcanvas && !offcanvas.classList.contains('show');
      });
      if (!closed) {
        issues.push({ type: 'mobile_nav_escape_fail', route: '/index.php', width: 390 });
      }
    } else {
      issues.push({ type: 'mobile_nav_toggler_missing', route: '/index.php', width: 390 });
    }
  } catch (err) {
    issues.push({ type: 'mobile_nav_test_error', route: '/index.php', width: 390, detail: String(err.message || err) });
  }

  await browser.close();

  const summary = {
    totalChecks: routes.length * widths.length,
    totalIssues: issues.length,
    byType: issues.reduce((acc, it) => {
      acc[it.type] = (acc[it.type] || 0) + 1;
      return acc;
    }, {}),
    issues,
    sample: issues.slice(0, 80)
  };

  console.log(JSON.stringify(summary, null, 2));
})();


