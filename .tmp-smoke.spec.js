const { test } = require('@playwright/test');

test('smoke', async ({ page }) => {
  await page.goto('http://127.0.0.1:8099/index.php');
});

