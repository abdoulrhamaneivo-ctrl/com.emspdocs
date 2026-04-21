const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  try {
    const resp = await page.goto('http://127.0.0.1/COMPO%20FINAL/emsp_docs/news-blog.php', { waitUntil: 'networkidle', timeout: 45000 });
    const data = await page.evaluate(() => ({
      statusText: document.title,
      flexDirection: document.querySelector('.emsp-navbar .navbar-nav') ? getComputedStyle(document.querySelector('.emsp-navbar .navbar-nav')).flexDirection : null,
      navHeight: document.querySelector('.emsp-nav-desktop')?.getBoundingClientRect().height || 0,
      overflowX: Math.max(document.documentElement.scrollWidth, document.body?.scrollWidth || 0) > window.innerWidth + 1,
      hasWarning: /Warning:|Fatal error:|Parse error:|mysqli_|Uncaught/i.test(document.body ? document.body.innerText : '')
    }));
    console.log(JSON.stringify({ status: resp ? resp.status() : null, ...data }, null, 2));
  } catch (e) {
    console.log(JSON.stringify({ error: String(e) }, null, 2));
  }
  await browser.close();
})();
