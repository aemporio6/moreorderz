// usage: php artisan serve --port=8123 (demo data only), a demo OWNER login uicheck@demo.test with its password in <dir>/pw → node tools/dev/guide-pictures.cjs <dir> public/guide
// Owner 27-11 08:00: a real picture of every screen for the user guide, the buttons marked 1, 2, 3 … (demo data only – never real data)
const { chromium } = require('playwright'); const fs = require('fs'); const S = process.argv[2], OUT = process.argv[3];
(async () => { const b = await chromium.launch(); const p = await (await b.newContext({ viewport: { width: 1280, height: 800 } })).newPage();
  await p.goto('http://127.0.0.1:8123/'); await p.fill('#login', 'uicheck@demo.test'); await p.fill('#password', fs.readFileSync(S + '/pw', 'utf8').trim()); await p.keyboard.press('Enter'); await p.waitForTimeout(4000);
  const routes = await p.evaluate(() => { const out = new Set(); (MO_BOOT().menu || []).forEach(s => s.items.forEach(i => { if (!i.soon) out.add(i.route); })); return [...out]; });
  let n = 0;
  for (const r of routes) {
    const slug = (r.split('?')[0].replace(/^#\//, '') || 'home').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    try { await p.evaluate(() => { document.querySelectorAll('.gmark').forEach(x => x.remove()); }); await p.goto('about:blank'); await p.goto('http://127.0.0.1:8123/' + r); await p.waitForTimeout(3000);
      await p.evaluate(() => { document.querySelectorAll('.gmark').forEach(x => x.remove()); document.activeElement && document.activeElement.blur && document.activeElement.blur(); });
      await p.evaluate(() => { document.querySelectorAll('.overlay,.toast,#gnBox,.gn-wrap').forEach(x => x.remove()); const x = document.querySelector('#gnX'); if (x) x.click(); });
      const marks = await p.evaluate(() => { const els = [...document.querySelectorAll('#main .page-head .actions > a.btn, #main .page-head .actions > button.btn, #main .list-bar > .btn, #main .list-bar > button')].filter(e => e.offsetParent && !e.classList.contains('lay-btn') && !e.classList.contains('gd-btn')).slice(0, 9);
        return els.map((e, i) => { const r = e.getBoundingClientRect(); const d = document.createElement('div'); d.textContent = i + 1; d.style.cssText = `position:fixed;left:${r.left - 8}px;top:${r.top - 10}px;z-index:9999;background:#e53935;color:#fff;font:bold 12px sans-serif;border-radius:50%;width:18px;height:18px;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 2px #fff`; d.className = 'gmark'; document.body.appendChild(d); e.style.outline = '2px solid #e53935'; return { n: i + 1, label: e.textContent.trim().replace(/\s+/g, ' ').slice(0, 60), title: (e.title || '').slice(0, 140) }; }); });
      await p.screenshot({ path: OUT + '/' + slug + '.jpg', type: 'jpeg', quality: 55 });
      fs.writeFileSync(OUT + '/' + slug + '.json', JSON.stringify({ route: r, marks })); n++;
    } catch (e) { console.log('skip', r, e.message.slice(0, 80)); }
  }
  console.log('pictures', n, 'of', routes.length); await b.close(); })();
