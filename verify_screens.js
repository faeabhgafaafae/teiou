const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();

  // analysis.php (未ログイン = Free 相当)
  await page.goto('https://2410049.moo.jp/analysis.php', { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(2500);
  await page.screenshot({ path: 'C:/Users/m1551/Desktop/verify_analysis_free.png', fullPage: true });
  console.log('analysis free done');

  // 会場別タブをクリックしてロック画面確認
  await page.click('[data-tab="venue"]');
  await page.waitForTimeout(500);
  await page.screenshot({ path: 'C:/Users/m1551/Desktop/verify_analysis_venue_tab.png' });
  console.log('venue tab done');

  // mypage.php
  await page.goto('https://2410049.moo.jp/mypage.php', { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(3000);
  await page.screenshot({ path: 'C:/Users/m1551/Desktop/verify_mypage_plan.png', fullPage: true });
  console.log('mypage done');

  await browser.close();
})().catch(e => { console.error(e.message); process.exit(1); });
