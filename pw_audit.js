const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = 'https://emspdocs.unaux.com';
const EMAIL = 'abdoulrhamane.ivo@gmail.com';
const PASSWORD = 'ivoabdoul';
const outDir = path.join(process.env.TEMP, 'emsp-ui-audit');
fs.mkdirSync(outDir, { recursive: true });

async function auditPage(page, label, url) {
  const consoleErrors = [];
  const pageErrors = [];
  const consoleHandler = msg => {
    if (msg.type() === 'error') consoleErrors.push(msg.text());
  };
  const pageHandler = err => pageErrors.push(String(err));
  page.on('console', consoleHandler);
  page.on('pageerror', pageHandler);
  let status = 'NO_RESPONSE';
  try {
    const response = await page.goto(url, { waitUntil: 'networkidle', timeout: 45000 });
    status = response ? response.status() : 'NO_RESPONSE';
  } catch (e) {
    status = 'NAV_ERROR';
    consoleErrors.push(String(e));
  }
  const metrics = await page.evaluate(() => {
    const root = document.documentElement;
    const body = document.body;
    const q = sel => {
      const el = document.querySelector(sel);
      if (!el) return null;
      const r = el.getBoundingClientRect();
      return { x: r.x, y: r.y, w: r.width, h: r.height, visible: !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length) };
    };
    return {
      title: document.title,
      vw: window.innerWidth,
      rootScroll: root.scrollWidth,
      bodyScroll: body ? body.scrollWidth : 0,
      overflowX: Math.max(root.scrollWidth, body ? body.scrollWidth : 0) > window.innerWidth + 1,
      hasWarning: /Warning:|Fatal error:|Parse error:|Uncaught|mysqli_/i.test(document.body ? document.body.innerText : ''),
      searchVisible: !!document.querySelector('.emsp-nav-search input, .emsp-mobile-search input, #emsp-mobile-nav-search'),
      desktopNavHeight: document.querySelector('.emsp-nav-desktop')?.getBoundingClientRect().height || 0,
      desktopNavFlexDirection: document.querySelector('.emsp-navbar .navbar-nav') ? getComputedStyle(document.querySelector('.emsp-navbar .navbar-nav')).flexDirection : null,
      brand: q('.emsp-brand'),
      desktopNav: q('.emsp-nav-desktop'),
      quick: q('.emsp-nav-quick')
    };
  });
  const shot = path.join(outDir, `${label}.png`);
  await page.screenshot({ path: shot, fullPage: true });
  page.off('console', consoleHandler);
  page.off('pageerror', pageHandler);
  return { label, url, status, metrics, consoleErrors, pageErrors, screenshot: shot };
}

async function detectLoginObstruction(page) {
  return page.evaluate(() => {
    const btn = document.querySelector('button[type="submit"], input[type="submit"]');
    if (!btn) return null;
    const r = btn.getBoundingClientRect();
    const cx = r.left + r.width / 2;
    const cy = r.top + r.height / 2;
    const topEl = document.elementFromPoint(cx, cy);
    const obstructed = !!topEl && topEl !== btn && !btn.contains(topEl);
    return {
      obstructed,
      topTag: topEl ? topEl.tagName : null,
      topClass: topEl ? topEl.className : null,
      btnText: btn.innerText || btn.value || null
    };
  });
}

async function login(page) {
  await page.goto(`${BASE}/login.php`, { waitUntil: 'networkidle', timeout: 45000 });
  const obstruction = await detectLoginObstruction(page);
  await page.locator('input[type="email"]').first().fill(EMAIL);
  await page.locator('input[type="password"]').first().fill(PASSWORD);
  await page.locator('form').first().evaluate(form => form.requestSubmit());
  await page.waitForLoadState('networkidle', { timeout: 45000 });
  return { ok: !page.url().includes('/login.php'), obstruction, url: page.url() };
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const results = [];

  const desktop = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const pageD = await desktop.newPage();
  for (const p of ['index.php','register.php','login.php','bibliotheque.php','mediatheque.php','news-blog.php']) {
    results.push(await auditPage(pageD, `desktop_public_${p.replace('.php','')}`, `${BASE}/${p}`));
  }
  const loggedDesktop = await login(pageD);
  results.push({ label: 'desktop_login', ...loggedDesktop });
  if (loggedDesktop.ok) {
    results.push(await auditPage(pageD, 'desktop_auth_biblio', `${BASE}/bibliotheque.php`));
    let avatarDropdown = false;
    if (await pageD.locator('.emsp-avatar-btn').isVisible().catch(() => false)) {
      await pageD.locator('.emsp-avatar-btn').click();
      avatarDropdown = await pageD.locator('.dropdown-menu.show').first().isVisible().catch(() => false);
      await pageD.keyboard.press('Escape').catch(() => {});
    }
    results.push({ label: 'desktop_avatar_dropdown', ok: avatarDropdown });
    results.push(await auditPage(pageD, 'desktop_admin_index', `${BASE}/admin/index.php`));
    results.push(await auditPage(pageD, 'desktop_admin_stats', `${BASE}/admin/stats.php`));
  }
  await desktop.close();

  const mobile = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  const pageM = await mobile.newPage();
  for (const p of ['index.php','register.php','login.php','bibliotheque.php','mediatheque.php','news-blog.php']) {
    results.push(await auditPage(pageM, `mobile_public_${p.replace('.php','')}`, `${BASE}/${p}`));
  }
  const loggedMobile = await login(pageM);
  results.push({ label: 'mobile_login', ...loggedMobile });
  if (loggedMobile.ok) {
    results.push(await auditPage(pageM, 'mobile_auth_biblio', `${BASE}/bibliotheque.php`));
    const toggler = pageM.locator('.navbar-toggler').first();
    const togglerVisible = await toggler.isVisible().catch(() => false);
    let offcanvasOpen = false;
    if (togglerVisible) {
      await toggler.click();
      offcanvasOpen = await pageM.locator('#emspMainOffcanvas.show').isVisible().catch(() => false);
      await pageM.keyboard.press('Escape').catch(() => {});
    }
    results.push({ label: 'mobile_offcanvas', togglerVisible, ok: offcanvasOpen });
    results.push(await auditPage(pageM, 'mobile_admin_index', `${BASE}/admin/index.php`));
  }
  await mobile.close();
  await browser.close();

  console.log(JSON.stringify(results, null, 2));
})();
