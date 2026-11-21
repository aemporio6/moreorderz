const { chromium, devices } = require('playwright'); const fs = require('fs'); const S = process.argv[2];
(async () => { const b = await chromium.launch(); const errs = [];
  for (const [tag, opt] of [['desktop', { viewport: { width: 1400, height: 950 } }], ['phone', devices['iPhone 13']]]) {
    const c = await b.newContext(opt); const p = await c.newPage(); p.on('pageerror', e => errs.push(tag + ': ' + e.message)); p.on('dialog', d => d.dismiss());
    await p.goto('http://127.0.0.1:8123/'); await p.fill('#login', 'uicheck@demo.test'); await p.fill('#password', fs.readFileSync(S + '/pw', 'utf8').trim()); await p.keyboard.press('Enter'); await p.waitForTimeout(3500);
    await p.evaluate(() => MO.go('#/choices')); await p.waitForTimeout(2500);
    console.log(tag, 'choices:', await p.evaluate(() => ['TASK_PROOF_DEFAULT', 'TASK_IMPORT_TELL', 'CHANNELS_SELF_EDIT'].map(k => k + '=' + (document.querySelector(`[data-chs="${k}"]`) || {}).value)), 'proof-off roles:', await p.$$eval('[data-chm="TASK_PROOF_OFF_ROLES"] input:checked', x => x.map(e => e.value)), 'switch roles:', await p.$$eval('[data-chm="CHANNEL_SWITCH_ROLES"] input:checked', x => x.map(e => e.value)));
    await p.evaluate(() => MO.go('#/tasks')); await p.waitForTimeout(2500);
    const nb = await p.$('#tkNew'); if (nb) { await nb.click(); await p.waitForTimeout(1500); console.log(tag, 'new task proof box:', await p.$eval('#tnEv', x => [x.checked, x.disabled])); await p.keyboard.press('Escape'); }
    console.log(tag, 'width', await p.evaluate(() => document.documentElement.scrollWidth), '/', await p.evaluate(() => innerWidth));
    await c.close(); }
  console.log('errors', JSON.stringify(errs)); await b.close(); })();
