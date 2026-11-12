/* MoreOrderz – screen shell. Same components and class names as the approved clickable design; data comes from the server. */
(function () {
  const MO = window.MO, ic = MO.ic;
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const csrf = $('meta[name=csrf-token]').content;
  const S = { boot: null, route: '#/home', hist: [], fwd: [] };
  MO.S = S;

  // ---------- server ----------
  async function api(path, opt = {}) {
    const res = await fetch('/api/' + path, { method: opt.method || 'GET', credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, ...(opt.body ? { 'Content-Type': 'application/json' } : {}) },
      body: opt.body ? JSON.stringify(opt.body) : undefined });
    if (res.status === 401 || res.status === 419) { location.href = '/login'; throw new Error('Signed out'); }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) { const e = new Error(data.message || 'Something went wrong. Nothing was saved.'); e.status = res.status; e.errors = data.errors || {}; throw e; }
    return data;
  }
  MO.api = api;
  const busy = on => { $('#loader').style.display = on ? 'flex' : 'none'; };
  const toast = (msg, kind = 'ok', link) => { const t = document.createElement('div'); t.className = 'toast ' + kind;
    t.innerHTML = `<span>${esc(msg)}</span>${link ? ` <a href="${esc(link)}">Open</a>` : ''}`; $('#toasts').appendChild(t); setTimeout(() => t.remove(), 6000); };
  MO.toast = toast;

  // ---------- router: every in-app link goes through here (no dependence on browser hash events) ----------
  function go(h, push = true) { if (push && S.route !== h) { S.hist.push(S.route); S.fwd = []; } S.route = h; try { history.replaceState(null, '', h); } catch (e) {}
    document.body.classList.remove('side-open'); render(); }
  MO.go = go;
  document.addEventListener('click', e => { if (e.defaultPrevented || e.button !== 0) return; const a = e.target.closest && e.target.closest('a[href^="#/"]'); if (!a) return; e.preventDefault(); go(a.getAttribute('href')); });
  document.addEventListener('click', e => { if (!document.body.classList.contains('side-open')) return; if (e.target.closest('#side') || e.target.closest('#btnSide')) return; document.body.classList.remove('side-open'); }, true);

  // ---------- top bar and menu ----------
  function drawTop() {
    const b = S.boot, ini = b.user.name.split(/\s+/).map(x => x[0]).join('').slice(0, 2).toUpperCase();
    $('#top').innerHTML = `
      <button class="tb-btn icon" id="btnSide" title="Menu">${ic('menu')}</button>
      <div class="brand" id="brand" title="Home">${MO.logo ? MO.logo(30) : ''}<div><div class="wm-text">More<b>Orderz</b></div></div></div>
      <select class="tb-sel hide-m" id="selEnt" title="Company">${b.companies.map(c => `<option value="${c.id}" ${c.id == b.ent ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select>
      <div class="searchbox" id="sbox">${ic('search')}<input id="q" placeholder="Search everything – SKU, order, PO, person, task…" autocomplete="off"><span class="kbd">/</span></div>
      <button class="tb-btn primary" id="btnCreate" title="Create (N)">${ic('plus')}<span class="hide-m">Create</span></button>
      <button class="tb-btn icon hide-m" id="btnBack" title="Back">${ic('chev')}</button>
      <button class="tb-btn icon" id="btnRefresh" title="Refresh data (keeps filters)">${ic('refresh')}</button>
      <button class="tb-btn icon bell" id="btnBell" title="My tasks">${ic('bell')}<span class="dot" id="bellDot" style="display:${b.tasks_open ? 'flex' : 'none'}">${b.tasks_open}</span></button>
      <div class="user" id="btnUser" title="My account: password, 2-step sign-in, sign out"><div class="avatar">${esc(ini)}</div><div class="hide-m"><div style="font-weight:600;font-size:13px">${esc(b.user.name)}</div><div class="small muted">${esc(b.user.role)}</div></div></div>`;
    $('#btnBack svg') && ($('#btnBack svg').style.transform = 'rotate(180deg)');
    $('#btnSide').onclick = () => document.body.classList.toggle('side-open');
    wireSearch();
    $('#brand').onclick = () => go('#/home');
    $('#btnCreate').onclick = () => openCreate();
    $('#btnRefresh').onclick = () => render();
    $('#btnBack').onclick = () => { if (S.hist.length) { S.fwd.push(S.route); go(S.hist.pop(), false); } };
    $('#btnBell').onclick = () => go('#/tasks');
    $('#btnUser').onclick = () => go('#/me');
    $('#selEnt').onchange = async e => { busy(true); try { await api('company', { method: 'POST', body: { ent: +e.target.value } }); S.boot = await api('boot'); drawTop(); drawSide(); render(); toast('Now working in ' + e.target.selectedOptions[0].text); } catch (x) { toast(x.message, 'err'); } busy(false); };
  }
  const ICONS = { Home: 'home', Research: 'search', Buying: 'buy', Selling: 'sell', Money: 'money', Dispatch: 'truck', Documents: 'docs', Reports: 'reports', Setup: 'setup', Work: 'work' };
  const secIcon = n => ICONS[n] || (/Warehouse/.test(n) ? 'stock' : /Products/.test(n) ? 'file' : /Returns/.test(n) ? 'undo' : /Month/.test(n) ? 'audit' : /Partners/.test(n) ? 'partners' : /People/.test(n) ? 'people' : /Import/.test(n) ? 'import' : 'file');
  function drawSide() {
    const open = S.openSec;
    $('#side').innerHTML = `<div class="nav-label">Menu <span class="faint" style="text-transform:none;letter-spacing:0">· in the order of the work</span></div>` +
      S.boot.menu.map(s => `<div class="nav-sec ${open === s.id ? 'open' : ''}" data-sec="${s.id}"><div class="nav-head has">${ic(secIcon(s.name))}${esc(s.name)}<span class="faint small" style="margin-left:6px">${s.items.length}</span>${ic('chev', 'ico chev')}</div>
        <div class="nav-items">${s.items.map(i => `<div class="nav-item lv1 ${S.route === i.route ? 'active' : ''}"><a class="lbl" href="${esc(i.route)}">${esc(i.name)}</a></div>`).join('')}</div></div>`).join('');
    $$('#side .nav-head').forEach(h => h.onclick = () => { const id = +h.parentNode.dataset.sec; S.openSec = S.openSec === id ? null : id; drawSide(); });
  }

  // ---------- pages ----------
  async function render() {
    const m = $('#main'), r = S.route; let mt;
    drawSide();
    try {
      if ((mt = r.match(/^#\/list\/([\w-]+)(?:\?(.*))?/))) { const f = {}; new URLSearchParams(mt[2] || '').forEach((v, k) => { const m2 = k.match(/^f\[(\w+)\]$/); if (m2) f[m2[1]] = v; }); return await pageList(mt[1], { filter: f }); }
      if ((mt = r.match(/^#\/rec\/([\w-]+)\/(\d+)/))) return await pageRecord(mt[1], +mt[2]);
      if (r.startsWith('#/tasks')) return await pageTasks(r.includes('scope=all') ? 'all' : 'mine');
      if ((mt = r.match(/^#\/stock\/product\/(\d+)/))) return await pageStockProduct(+mt[1]);
      if (r.startsWith('#/stock/ledger')) return await pageStockLedger({ act: (r.match(/act=(\w+)/) || [])[1], product: (r.match(/product=(\d+)/) || [])[1] });
      if (r.startsWith('#/stock/lots')) return await pageLots();
      if (r === '#/stock/pool') return await pagePool();
      if (r === '#/stock/held') return await pageHeld();
      if ((mt = r.match(/^#\/stock\/counts\/(\d+)/))) return await pageCount(+mt[1]);
      if (r === '#/stock/counts') return await pageCounts();
      if ((mt = r.match(/^#\/prices\/(\d+)/))) return await pagePrices(+mt[1]);
      if (r.startsWith('#/stock')) return await pageStock({ low: r.includes('low=1') });
      if (r === '#/users') return await pageUsers();
      if (r === '#/settings') return await pageSettings();
      if (r.startsWith('#/audit')) return await pageAudit();
      if (r === '#/health') return await pageHealth();
      if (r === '#/ledger') return await pageLedger();
      if (r === '#/me') return await pageMe();
      if ((mt = r.match(/^#\/screen\/([\d.]+)/))) return pageScreen(mt[1]);
      return await pageHome();
    } catch (e) { m.innerHTML = `<div class="card"><div class="empty">${ic('alert')}<div>${esc(e.message)}</div></div></div>`; }
  }
  function findItem(pred) { for (const s of S.boot.menu) for (const i of s.items) if (pred(i)) return [s, i]; return [null, null]; }

  async function pageHome() {
    const b = S.boot; let d = { cards: [], recent: [] };
    try { d = await api('home'); } catch (e) {}
    $('#main').innerHTML = `<div class="page-head"><div><h1>Good to see you, ${esc(b.user.name.split(' ')[0])}</h1><div class="sub">${esc((b.companies.find(c => c.id == b.ent) || {}).name || '')} · ${esc(b.user.role)} · ${esc(b.today)}</div></div>
      <div class="actions"><button class="btn primary" id="hCreate">${ic('plus')} Create</button></div></div>
      <div class="kpis">${d.cards.map(c => `<div class="card kpi ${esc(c.tone || '')}" data-go="${esc(c.go || '')}"><div class="bar"></div><div class="lbl">${esc(c.label)}</div><div class="val">${esc(c.value)}</div><div class="foot">${esc(c.foot || '')}</div></div>`).join('')}</div>
      ${d.recent.length ? `<div class="card" style="margin-top:16px"><div class="card-h"><h3>Where you worked last</h3></div><div class="card-b">${d.recent.map(r => `<a href="${esc(r.url)}" style="display:inline-block;margin:0 14px 8px 0">${esc(r.title)} <span class="small muted">${esc(r.subtitle)}</span></a>`).join('')}</div></div>` : ''}
      <div class="card" style="margin-top:16px"><div class="card-h"><h3>Menu · in the order of the work</h3></div><div class="card-b"><div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px">
      ${b.menu.filter(s => s.name !== 'Home').map(s => `<a class="card kpi" href="${esc(s.items[0].route)}" style="text-decoration:none;color:inherit"><div class="lbl">${ic(secIcon(s.name))} ${esc(s.name)}</div><div class="foot">${s.items.length} screens</div></a>`).join('')}</div></div></div>`;
    $('#hCreate').onclick = () => openCreate();
    $$('#main [data-go]').forEach(k => k.onclick = () => k.dataset.go && go(k.dataset.go));
  }
  function pageScreen(no) {
    const [, it] = findItem(i => i.no === no);
    $('#main').innerHTML = `<div class="page-head"><div><h1>${esc(it ? it.name : 'Screen ' + no)}</h1><div class="sub">Screen ${esc(no)} of the system map</div></div></div>
      <div class="card"><div class="empty">${ic('wand')}<div>This screen is built in a later step of the build plan (see PROGRESS.md). Its records can already be created from + Create.</div></div></div>`;
  }

  const fmtCell = (v, type, row, name) => { if (row[name + '__label'] !== undefined) return esc(row[name + '__label']); if (v === null || v === undefined || v === '') return '<span class="faint">—</span>';
    if (type === 'check') return +v ? ic('check') : '<span class="faint">—</span>'; if (type === 'money') return (+v).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (type === 'link') return `<a href="${esc(v)}" target="_blank" rel="noopener">link</a>`; return esc(String(v).length > 60 ? String(v).slice(0, 57) + '…' : v); };
  async function pageList(key, st = {}) {
    busy(true);
    const qs = new URLSearchParams({ page: st.page || 1, q: st.q || '', sort: st.sort || '', dir: st.dir || 'desc' });
    Object.entries(st.filter || {}).forEach(([k, v]) => { if (v !== '') qs.append(`filter[${k}]`, v); });
    let d; try { d = await api(`list/${key}?` + qs); } finally { busy(false); }
    const [, it] = findItem(i => i.route === '#/list/' + key);
    const pages = Math.max(1, Math.ceil(d.total / d.per_page));
    $('#main').innerHTML = `<div class="page-head"><div><h1>${esc(it ? it.name : key)}</h1><div class="sub">${d.total.toLocaleString('en-GB')} record${d.total === 1 ? '' : 's'}</div></div>
        <div class="actions"><button class="btn" id="lFilt">${ic('filter')} Filters${Object.values(st.filter || {}).filter(v => v !== '').length ? ' · ' + Object.values(st.filter).filter(v => v !== '').length : ''}</button><a class="btn" id="lExp" href="/api/export/${key}?${qs}" title="Download this list (with its filters) as a spreadsheet file">${ic('ext')} Export</a>${d.table === 'cat_inventory_locations' && ['OWNER', 'MANAGER'].includes(S.boot.user.role) ? `<button class="btn" id="lBins">Make bins</button>` : ''}<button class="btn primary" id="lNew">${ic('plus')} New</button></div></div>
      <div class="card"><div class="list-bar"><input class="q" id="lQ" placeholder="Search this list…" value="${esc(st.q || '')}">${Object.entries(st.filter || {}).filter(([, v]) => v !== '').map(([k, v]) => `<span class="chip accent plain">${esc((d.columns.find(c => c.name === k) || {}).label || k)}: ${esc(v)} <a href="#" data-unf="${esc(k)}" title="Remove this filter">✕</a></span>`).join('')}</div>
      <div class="list-bar" id="lFiltRow" ${st.showF ? '' : 'hidden'}>${d.columns.map(c => `<input class="q" style="min-width:130px;flex:1" data-fc="${esc(c.name)}" placeholder="${esc(c.label)}" value="${esc((st.filter || {})[c.name] || '')}">`).join('')}<button class="btn sm primary" id="lFiltGo">Apply</button><button class="btn sm" id="lFiltClr">Clear</button></div>
      <div class="tbl-wrap"><table class="t"><thead><tr>${d.columns.map(c => `<th data-sort="${esc(c.name)}" class="${['number', 'money'].includes(c.type) ? 'num' : ''}">${esc(c.label)}${st.sort === c.name ? (st.dir === 'asc' ? ' ↑' : ' ↓') : ''}</th>`).join('')}</tr></thead>
      <tbody>${d.rows.map(r => `<tr class="click" data-id="${r.id}">${d.columns.map(c => `<td class="${['number', 'money'].includes(c.type) ? 'num' : ''}">${fmtCell(r[c.name], c.type, r, c.name)}</td>`).join('')}</tr>`).join('')}</tbody></table>
      ${d.rows.length ? '' : `<div class="empty">${ic('file')}<div>Nothing here yet. Press <b>New</b> to add the first one.</div></div>`}</div>
      <div class="pager"><span>Page ${d.page} of ${pages}</span><button class="btn sm" id="pPrev" ${d.page <= 1 ? 'disabled' : ''}>Previous</button><button class="btn sm" id="pNext" ${d.page >= pages ? 'disabled' : ''}>Next</button></div></div>`;
    const again = x => pageList(key, { ...st, ...x });
    $('#lNew').onclick = () => openForm(key, null, { onSaved: () => again({}) });
    $('#lQ').onkeydown = e => { if (e.key === 'Enter') again({ q: e.target.value, page: 1 }); };
    $('#pPrev').onclick = () => again({ page: d.page - 1 }); $('#pNext').onclick = () => again({ page: d.page + 1 });
    $$('#main th[data-sort]').forEach(th => th.onclick = () => again({ sort: th.dataset.sort, dir: st.sort === th.dataset.sort && st.dir === 'asc' ? 'desc' : 'asc', page: 1 }));
    $$('#main tr[data-id]').forEach(tr => tr.onclick = () => go(`#/rec/${key}/${tr.dataset.id}`));
    if ($('#lBins')) $('#lBins').onclick = () => binGenerator(() => again({}));
    $('#lFilt').onclick = () => again({ showF: !st.showF });
    const applyF = () => { const f = {}; $$('#lFiltRow [data-fc]').forEach(i => { if (i.value.trim() !== '') f[i.dataset.fc] = i.value.trim(); }); again({ filter: f, page: 1, showF: true }); };
    $('#lFiltGo').onclick = applyF; $$('#lFiltRow [data-fc]').forEach(i => i.onkeydown = e => { if (e.key === 'Enter') applyF(); });
    $('#lFiltClr').onclick = () => again({ filter: {}, page: 1 });
    $$('#main [data-unf]').forEach(a => a.onclick = e => { e.preventDefault(); const f = { ...(st.filter || {}) }; delete f[a.dataset.unf]; again({ filter: f, page: 1 }); });
  }

  // ---------- record page: details, links both ways, tasks, history ----------
  const chipFor = s => ({ DONE: 'done', APPROVED: 'done', DOING: 'prog', STUCK: 'err', TODO: 'pend', REWORK: 'warn', CANCELLED: 'lock' }[s] || 'pend');
  const showVal = f => { const v = f.shown ?? f.value; if (v === null || v === undefined || v === '') return '<span class="faint">—</span>'; if (f.type === 'check') return +v ? 'Yes' : 'No';
    if (f.type === 'money') return (+v).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); if (f.type === 'link') return `<a href="${esc(v)}" target="_blank" rel="noopener">${esc(v)}</a>`; return esc(v); };
  async function pageRecord(key, id) {
    busy(true); let d; try { d = await api(`page/${key}/${id}`); } finally { busy(false); }
    const tab = S.recTab || 'details';
    const tabs = [['details', 'Details'], ['links', `Linked records · ${d.parents.length + d.children.length}`], ['tasks', `Tasks · ${d.tasks.length}`], ['history', `History · ${d.history.length}`]];
    const body = tab === 'details' ? Object.entries(d.sections).map(([sec, fs]) => `<div class="card" style="margin-bottom:12px"><div class="card-h"><h3>${esc(sec)}</h3></div><div class="card-b"><div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px 18px">${fs.map(f => `<div><div class="small muted">${esc(f.label)}</div><div>${showVal(f)}</div></div>`).join('')}</div></div></div>`).join('')
      : tab === 'links' ? `<div class="card"><div class="card-h"><h3>This record points to</h3></div><div class="card-b">${d.parents.map(p => `<div style="padding:5px 0"><span class="small muted">${esc(p.label)}:</span> <a href="${esc(p.url)}">${esc(p.title)}</a></div>`).join('') || '<span class="muted">Nothing.</span>'}</div></div>
          <div class="card" style="margin-top:12px"><div class="card-h"><h3>Records that point to this one</h3></div><div class="card-b">${d.children.map(c => `<div style="padding:5px 0"><a href="${esc(c.url)}">${esc(c.title)}</a> <span class="chip plain">${c.count}</span></div>`).join('') || '<span class="muted">Nothing yet.</span>'}</div></div>`
      : tab === 'tasks' ? `<div class="card"><div class="tbl-wrap"><table class="t"><thead><tr><th>Task</th><th>Status</th><th>Priority</th><th>Due</th></tr></thead><tbody>${d.tasks.map(t => `<tr><td>${esc(t.title)}${t.rule_code ? ` <span class="small muted">${esc(t.rule_code)}</span>` : ''}</td><td><span class="chip ${chipFor(t.status)}">${esc(t.status)}</span></td><td>${esc(t.priority)}</td><td>${esc(t.due_at)}</td></tr>`).join('')}</tbody></table>${d.tasks.length ? '' : '<div class="empty">No tasks on this record.</div>'}</div></div>`
      : `<div class="card"><div class="tbl-wrap"><table class="t"><thead><tr><th>When</th><th>Who</th><th>What</th><th>Change</th></tr></thead><tbody>${d.history.map(h => `<tr><td>${esc(h.at)}</td><td>${esc(h.by)}</td><td>${esc({ INSERT: 'Created', UPDATE: 'Changed', EXPORT: 'Exported' }[h.action] || h.action)}</td><td>${h.changes ? Object.entries(h.changes).map(([k, v]) => `<div><b>${esc(k)}</b>: ${esc(v[0] ?? '—')} → ${esc(v[1] ?? '—')}</div>`).join('') : ''}${h.reason ? `<div class="small muted">${esc(h.reason)}</div>` : ''}</td></tr>`).join('')}</tbody></table></div></div>`;
    $('#main').innerHTML = `<div class="page-head"><div><div class="small muted"><a href="#/list/${key}">${esc(d.form_title)}</a> ›</div><h1>${esc(d.title)}</h1></div>
      <div class="actions">${key === S.boot.product_form ? `<a class="btn" href="#/stock/product/${id}">Stock</a><a class="btn" href="#/prices/${id}">Prices</a>` : ''}<button class="btn primary" id="rEdit">${ic('wand')} Edit</button></div></div>
      <div class="seg" style="margin-bottom:14px">${tabs.map(([k, l]) => `<button type="button" class="${k === tab ? 'on' : ''}" data-tab="${k}">${esc(l)}</button>`).join('')}</div>${body}`;
    $('#rEdit').onclick = () => openForm(key, id, { onSaved: () => pageRecord(key, id) });
    $$('#main [data-tab]').forEach(b => b.onclick = () => { S.recTab = b.dataset.tab; pageRecord(key, id); });
  }

  // ---------- tasks ----------
  async function pageTasks(scope) {
    busy(true); let d; try { d = await api(`tasks?scope=${scope}`); } finally { busy(false); }
    const today = S.boot.today;
    $('#main').innerHTML = `<div class="page-head"><div><h1>${scope === 'all' ? 'Needs action' : 'My tasks'}</h1><div class="sub">Tasks opened by a rule close by themselves when the problem is fixed.</div></div>
      <div class="actions"><div class="seg"><button type="button" class="${scope === 'mine' ? 'on' : ''}" data-sc="mine">Mine</button><button type="button" class="${scope === 'all' ? 'on' : ''}" data-sc="all">Everyone</button></div></div></div>
      <div class="kpis"><div class="card kpi"><div class="bar"></div><div class="lbl">Open</div><div class="val">${d.counts.open}</div></div><div class="card kpi ${d.counts.late ? 'err' : 'done'}"><div class="bar"></div><div class="lbl">Late</div><div class="val">${d.counts.late}</div></div><div class="card kpi ${d.counts.urgent ? 'warn' : 'done'}"><div class="bar"></div><div class="lbl">Urgent</div><div class="val">${d.counts.urgent}</div></div></div>
      <div class="card" style="margin-top:14px"><div class="tbl-wrap"><table class="t"><thead><tr><th>Task</th><th>Area</th><th>Owner</th><th>Priority</th><th>Due</th><th>Status</th><th></th></tr></thead><tbody>
      ${d.tasks.map(t => `<tr><td>${esc(t.title)}${t.rule_code ? ` <span class="small muted">${esc(t.rule_code)}</span>` : ''}</td><td>${esc(t.category || '')}</td><td>${esc(t.owner || '—')}</td><td><span class="chip ${t.priority === 'URGENT' ? 'err' : t.priority === 'HIGH' ? 'warn' : 'pend'} plain">${esc(t.priority)}</span></td>
        <td class="${t.due_at < today ? 'late' : ''}" style="${t.due_at < today ? 'color:var(--err);font-weight:600' : ''}">${esc(t.due_at)}</td><td><span class="chip ${chipFor(t.status)}">${esc(t.status)}</span></td>
        <td style="white-space:nowrap">${t.fix_url ? `<a class="btn sm" href="${esc(t.fix_url)}">Fix</a> ` : ''}${t.status === 'TODO' ? `<button class="btn sm" data-ts="DOING" data-id="${t.id}">Start</button> ` : ''}${t.source !== 'RULE' ? `<button class="btn sm primary" data-ts="DONE" data-id="${t.id}">Done</button>` : ''}</td></tr>`).join('')}</tbody></table>
      ${d.tasks.length ? '' : `<div class="empty">${ic('check')}<div>Nothing waiting. </div></div>`}</div></div>`;
    $$('#main [data-sc]').forEach(b => b.onclick = () => go(b.dataset.sc === 'all' ? '#/tasks?scope=all' : '#/tasks'));
    $$('#main [data-ts]').forEach(b => b.onclick = async () => { try { await api(`tasks/${b.dataset.id}/status`, { method: 'POST', body: { status: b.dataset.ts } }); toast(b.dataset.ts === 'DONE' ? 'Task done' : 'Task started'); S.boot = await api('boot'); drawTop(); pageTasks(scope); } catch (e) { toast(e.message, 'err'); } });
  }

  // ---------- search everything ----------
  function wireSearch() {
    const inp = $('#q'); let box = null, timer;
    const hide = () => { box && box.remove(); box = null; };
    inp.oninput = () => { clearTimeout(timer); timer = setTimeout(async () => { const q = inp.value.trim(); if (q.length < 2) return hide();
      let r = []; try { r = (await api('search?q=' + encodeURIComponent(q))).results; } catch (e) {}
      hide(); box = document.createElement('div'); box.className = 'card'; box.id = 'sres';
      box.style.cssText = 'position:fixed;z-index:60;max-height:60vh;overflow:auto;box-shadow:0 12px 40px rgba(0,0,0,.18)'; const rc = $('#sbox').getBoundingClientRect(); box.style.left = rc.left + 'px'; box.style.top = (rc.bottom + 4) + 'px'; box.style.width = Math.max(rc.width, 300) + 'px';
      box.innerHTML = r.length ? r.map(x => `<a href="${esc(x.url)}" style="display:block;padding:9px 12px;border-bottom:1px solid var(--line);text-decoration:none;color:inherit"><div style="font-weight:600">${esc(x.title)}</div><div class="small muted">${esc(x.subtitle)}</div></a>`).join('') : `<div class="empty">Nothing found for “${esc(q)}”.</div>`;
      document.body.appendChild(box); box.onclick = () => { hide(); inp.value = ''; }; }, 220); };
    inp.onkeydown = e => { if (e.key === 'Escape') { hide(); inp.blur(); } if (e.key === 'Enter' && box) { const a = box.querySelector('a'); a && a.click(); } };
    document.addEventListener('click', e => { if (box && !e.target.closest('#sres') && e.target !== inp) hide(); });
  }

  // ---------- small form in a modal (users, password, settings) ----------
  function miniForm(title, sub, fields, onSave, saveLabel = 'Save') {
    const { ov, close } = modal(title, sub, `<div class="form">${fields.map(f => `<div class="fld ${f.required ? 'is-req' : ''}" data-w="${f.name}"><label for="mf_${f.name}"><span>${esc(f.label)}</span>${f.required ? ' <span class="req">*</span>' : ''}</label>
      ${f.options ? `<select id="mf_${f.name}" data-f="${f.name}">${f.options.map(([v, l]) => `<option value="${esc(v)}" ${String(v) === String(f.value ?? '') ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`
        : f.type === 'check' ? `<label class="chk"><input id="mf_${f.name}" data-f="${f.name}" type="checkbox" ${f.value ? 'checked' : ''}> Yes</label>`
        : `<input id="mf_${f.name}" data-f="${f.name}" type="${f.type || 'text'}" ${f.type === 'number' ? 'step="any"' : ''} value="${esc(f.value ?? '')}" autocomplete="${f.type === 'password' ? 'new-password' : 'off'}">`}${f.help ? `<div class="helptext" style="display:block">${esc(f.help)}</div>` : ''}</div>`).join('')}</div>${sub && sub.html ? sub.html : ''}`,
      `<span class="left small muted" id="mfMsg"></span><button class="btn" id="mfCancel">Cancel</button><button class="btn primary" id="mfSave">${esc(saveLabel)}</button>`, false);
    $('#mfCancel', ov).onclick = close;
    $('#mfSave', ov).onclick = async () => { const v = {}; $$('[data-f]', ov).forEach(el => { v[el.dataset.f] = el.type === 'checkbox' ? el.checked : el.value; });
      $$('.errtext', ov).forEach(e => e.remove()); $$('.has-err', ov).forEach(e => e.classList.remove('has-err')); busy(true);
      try { const r = await onSave(v); close(); toast((r && r.message) || 'Saved'); }
      catch (e) { Object.entries(e.errors || {}).forEach(([k, m]) => { const w = $(`[data-w="${k}"]`, ov); if (w) { w.classList.add('has-err'); w.insertAdjacentHTML('beforeend', `<div class="errtext">${esc(m[0] || m)}</div>`); } }); $('#mfMsg', ov).textContent = e.message; }
      busy(false); };
    return { ov, close };
  }

  // ---------- users and roles ----------
  async function pageUsers() {
    busy(true); let d; try { d = await api('users'); } finally { busy(false); }
    const owner = S.boot.user.role === 'OWNER';
    $('#main').innerHTML = `<div class="page-head"><div><h1>Users and roles</h1><div class="sub">The role decides which menu items, forms and buttons a person sees. ${d.users.length} users.</div></div>
      <div class="actions">${owner ? `<button class="btn primary" id="uNew">${ic('plus')} New user</button>` : ''}</div></div>
      <div class="card"><div class="tbl-wrap"><table class="t"><thead><tr><th>Name</th><th>Login</th><th>Role</th><th>Company</th><th>2-step</th><th>Last sign-in</th><th>Status</th></tr></thead><tbody>
      ${d.users.map(u => `<tr class="${owner ? 'click' : ''}" data-id="${u.id}"><td>${esc(u.display_name || '—')}</td><td>${esc(u.login_id)}</td><td><span class="chip accent plain">${esc(u.role_code)}</span></td><td>${esc(u.company || '—')}</td><td>${+u.two_step ? '<span class="chip done">On</span>' : '<span class="chip pend">Off</span>'}</td><td>${esc(u.last_login_at || 'never')}</td>
        <td>${!+u.has_password ? '<span class="chip warn">No password yet</span>' : +u.f_active ? '<span class="chip done">Active</span>' : '<span class="chip lock">Off</span>'}</td></tr>`).join('')}</tbody></table></div></div>`;
    const edit = u => miniForm(u ? 'Edit user' : 'New user', u ? 'Leave the password empty to keep the current one.' : 'The person signs in with this login and password.', [
      { name: 'display_name', label: 'Name', required: true, value: u && u.display_name }, { name: 'login_id', label: 'Login', required: true, value: u && u.login_id },
      { name: 'role_code', label: 'Role', required: true, value: u ? u.role_code : 'VA', options: d.roles.map(r => [r, r]) }, { name: 'fk_id_ent', label: 'Company', required: true, value: u ? u.fk_id_ent : S.boot.ent, options: d.companies.map(c => [c.id, c.name]) },
      { name: 'password', label: u ? 'New password' : 'Password', type: 'password', required: !u, help: 'At least 10 characters.' }, { name: 'f_active', label: 'Can sign in', type: 'check', value: u ? +u.f_active : 1 }],
      async v => { const r = await api('users' + (u ? '/' + u.id : ''), { method: 'POST', body: v }); pageUsers(); return r; });
    if (owner) { $('#uNew').onclick = () => edit(null); $$('#main tr[data-id]').forEach(tr => tr.onclick = () => edit(d.users.find(u => u.id == tr.dataset.id))); }
  }

  // ---------- my account: password, 2-step sign-in, sign out ----------
  async function pageMe() {
    $('#main').innerHTML = `<div class="page-head"><div><h1>My account</h1><div class="sub">${esc(S.boot.user.name)} · ${esc(S.boot.user.role)}</div></div><div class="actions"><button class="btn" id="mOut">Sign out</button></div></div>
      <div class="card"><div class="card-h"><h3>Password</h3></div><div class="card-b"><button class="btn" id="mPw">Change password</button></div></div>
      <div class="card" style="margin-top:12px"><div class="card-h"><h3>2-step sign-in</h3></div><div class="card-b"><p class="muted" style="margin-top:0">After the password, the tool asks for a 6-digit code from an authenticator app on your phone. A stolen password alone is then not enough.</p><div id="m2"></div></div></div>`;
    $('#mOut').onclick = () => $('#logoutForm').submit();
    $('#mPw').onclick = () => miniForm('Change password', '', [{ name: 'current', label: 'Current password', type: 'password', required: true }, { name: 'new', label: 'New password', type: 'password', required: true, help: 'At least 10 characters.' }], v => api('me/password', { method: 'POST', body: v }));
    const s = await api('me/two-step');
    $('#m2').innerHTML = s.on ? `<span class="chip done">On</span> <button class="btn sm" id="m2off" style="margin-left:10px">Switch off</button>`
      : `<div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start"><div style="background:#fff;padding:8px;border-radius:8px;width:216px">${s.qr_svg}</div><div style="flex:1;min-width:220px"><p style="margin-top:0">1. Open an authenticator app (Google Authenticator, Microsoft Authenticator, Authy) and scan this square.<br>2. Type the 6-digit code it shows.</p><p class="small muted">Cannot scan? Type this key into the app: <code>${esc(s.secret)}</code></p><input class="q" id="m2code" inputmode="numeric" placeholder="6-digit code" style="min-width:140px"> <button class="btn primary" id="m2on">Switch on</button></div></div>`;
    if (s.on) $('#m2off').onclick = async () => { await api('me/two-step-off', { method: 'POST' }); toast('2-step sign-in is off'); pageMe(); };
    else $('#m2on').onclick = async () => { try { await api('me/two-step', { method: 'POST', body: { code: $('#m2code').value } }); toast('2-step sign-in is on'); pageMe(); } catch (e) { toast(e.message, 'err'); } };
  }

  // ---------- settings ----------
  async function pageSettings() {
    busy(true); let d; try { d = await api('settings'); } finally { busy(false); }
    const ent = (S.boot.companies.find(c => c.id == S.boot.ent) || {}).name || 'this company';
    $('#main').innerHTML = `<div class="page-head"><div><h1>Settings</h1><div class="sub">A value for ${esc(ent)} wins over the value for all companies. ${d.settings.length} settings.</div></div></div>
      <div class="card"><div class="list-bar"><input class="q" id="sQ" placeholder="Find a setting…"></div><div class="tbl-wrap"><table class="t"><thead><tr><th>Setting</th><th>All companies</th><th>${esc(ent)}</th></tr></thead><tbody id="sBody"></tbody></table></div></div>`;
    const draw = () => { const q = $('#sQ').value.trim().toLowerCase(); $('#sBody').innerHTML = d.settings.filter(s => !q || s.key.toLowerCase().includes(q)).map(s => `<tr><td><code>${esc(s.key)}</code></td><td class="click" data-k="${esc(s.key)}" data-s="global" style="cursor:pointer">${s.global === null ? '<span class="faint">—</span>' : esc(s.global)}</td><td class="click" data-k="${esc(s.key)}" data-s="company" style="cursor:pointer">${s.company === null ? '<span class="faint">same</span>' : esc(s.company)}</td></tr>`).join('');
      $$('#sBody [data-k]').forEach(td => td.onclick = () => { const s = d.settings.find(x => x.key === td.dataset.k);
        miniForm(s.key, td.dataset.s === 'global' ? 'Value for all companies' : 'Value for ' + ent + ' only', [{ name: 'value', label: 'Value', value: (s[td.dataset.s] === '••••' ? '' : s[td.dataset.s]) ?? '' }], async v => { const r = await api('settings', { method: 'POST', body: { key: s.key, value: v.value, scope: td.dataset.s } }); pageSettings(); return r; }); }); };
    $('#sQ').oninput = draw; draw();
  }

  // ---------- audit log ----------
  async function pageAudit(st = {}) {
    busy(true); let d; try { d = await api('audit?' + new URLSearchParams({ page: st.page || 1, action: st.action || '', user: st.user || '', table: st.table || '' })); } finally { busy(false); }
    const pages = Math.max(1, Math.ceil(d.total / 50));
    $('#main').innerHTML = `<div class="page-head"><div><h1>Audit log</h1><div class="sub">Every change, sign-in and export. ${d.total.toLocaleString('en-GB')} rows. Nothing here can be edited or deleted.</div></div></div>
      <div class="card"><div class="list-bar"><input class="q" id="aU" placeholder="User login…" value="${esc(st.user || '')}"><input class="q" id="aT" placeholder="Table…" value="${esc(st.table || '')}"><select class="q" id="aA"><option value="">Every action</option>${['INSERT', 'UPDATE', 'DELETE', 'LOGIN', 'LOGIN_FAIL', 'LOGOUT', 'EXPORT', 'APPROVE', 'REJECT', 'LOCK', 'UNLOCK'].map(a => `<option ${st.action === a ? 'selected' : ''}>${a}</option>`).join('')}</select><button class="btn sm primary" id="aGo">Show</button></div>
      <div class="tbl-wrap"><table class="t"><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Where</th><th>Change</th><th>IP</th></tr></thead><tbody>${d.rows.map(r => { let ch = ''; try { const c = JSON.parse(r.changes_json || 'null'); if (c && r.action === 'UPDATE') ch = Object.entries(c).map(([k, v]) => `<b>${esc(k)}</b>: ${esc(Array.isArray(v) ? (v[0] ?? '—') + ' → ' + (v[1] ?? '—') : v)}`).join('<br>'); else if (c) ch = esc(JSON.stringify(c).slice(0, 140)); } catch (e) {}
        return `<tr><td style="white-space:nowrap">${esc(r.created_at)}</td><td>${esc(r.display_name || r.login_id || 'System')}</td><td><span class="chip ${r.action === 'LOGIN_FAIL' ? 'err' : r.action === 'EXPORT' ? 'warn' : 'pend'} plain">${esc(r.action)}</span></td><td>${esc(r.table_name)}${+r.record_id ? ' #' + r.record_id : ''}</td><td>${ch}${r.reason ? `<div class="small muted">${esc(r.reason)}</div>` : ''}</td><td>${esc(r.ip || '')}</td></tr>`; }).join('')}</tbody></table></div>
      <div class="pager"><span>Page ${d.page} of ${pages}</span><button class="btn sm" id="pPrev" ${d.page <= 1 ? 'disabled' : ''}>Previous</button><button class="btn sm" id="pNext" ${d.page >= pages ? 'disabled' : ''}>Next</button></div></div>`;
    const again = x => pageAudit({ ...st, ...x });
    $('#aGo').onclick = () => again({ page: 1, user: $('#aU').value, table: $('#aT').value, action: $('#aA').value });
    $('#pPrev').onclick = () => again({ page: d.page - 1 }); $('#pNext').onclick = () => again({ page: d.page + 1 });
  }

  // ---------- system health ----------
  async function pageHealth() {
    busy(true); let d; try { d = await api('health'); } finally { busy(false); }
    const ev = d.events || {}, failed = +(ev.FAILED || 0);
    $('#main').innerHTML = `<div class="page-head"><div><h1>System health</h1><div class="sub">Events connect the modules. A failed event is kept and tried again; nothing is lost.</div></div><div class="actions">${failed ? `<button class="btn primary" id="hRetry">${ic('refresh')} Try failed events again</button>` : ''}</div></div>
      <div class="kpis"><div class="card kpi ${failed ? 'err' : 'done'}"><div class="bar"></div><div class="lbl">Failed events</div><div class="val">${failed}</div></div><div class="card kpi"><div class="bar"></div><div class="lbl">Events done</div><div class="val">${+(ev.DONE || 0)}</div></div><div class="card kpi"><div class="bar"></div><div class="lbl">Open tasks</div><div class="val">${d.counts.open_tasks}</div></div><div class="card kpi"><div class="bar"></div><div class="lbl">Records in search</div><div class="val">${d.counts.records_indexed}</div></div><div class="card kpi"><div class="bar"></div><div class="lbl">Active users</div><div class="val">${d.counts.users}</div></div></div>
      ${failed ? `<div class="card" style="margin-top:14px"><div class="card-h"><h3>Failed</h3></div><div class="tbl-wrap"><table class="t"><thead><tr><th>Event</th><th>Record</th><th>Tries</th><th>Why</th><th>When</th></tr></thead><tbody>${d.failed.map(e => `<tr><td><code>${esc(e.event_code)}</code></td><td>${esc(e.entity)} #${e.entity_id}</td><td>${e.attempts}</td><td>${esc(e.last_error || '')}</td><td>${esc(e.created_at)}</td></tr>`).join('')}</tbody></table></div></div>` : ''}
      <div class="card" style="margin-top:14px"><div class="card-h"><h3>Last events</h3></div><div class="tbl-wrap"><table class="t"><thead><tr><th>Event</th><th>Record</th><th>Status</th><th>When</th></tr></thead><tbody>${d.recent.map(e => `<tr><td><code>${esc(e.event_code)}</code></td><td>${esc(e.entity)} #${e.entity_id}</td><td><span class="chip ${e.status === 'DONE' ? 'done' : e.status === 'FAILED' ? 'err' : 'pend'}">${esc(e.status)}</span></td><td>${esc(e.created_at)}</td></tr>`).join('')}</tbody></table>${d.recent.length ? '' : '<div class="empty">No events yet.</div>'}</div></div>`;
    if (failed) $('#hRetry').onclick = async () => { const r = await api('health/retry', { method: 'POST' }); toast(r.message); pageHealth(); };
  }

  // ---------- ledger (double entry) ----------
  const money = v => (+v).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  async function pageLedger() {
    busy(true); let d; try { d = await api('ledger'); } finally { busy(false); }
    $('#main').innerHTML = `<div class="page-head"><div><h1>Ledger</h1><div class="sub">Every money movement is one journal: debits = credits. Mistakes are reversed, never edited.${d.locked_up_to ? ' Locked up to ' + esc(d.locked_up_to) + '.' : ''}</div></div></div>
      <div class="kpis"><div class="card kpi ${d.trial.balanced ? 'done' : 'err'}"><div class="bar"></div><div class="lbl">Trial balance</div><div class="val">${d.trial.balanced ? 'Balanced' : 'NOT balanced'}</div><div class="foot">${money(d.trial.debit)} = ${money(d.trial.credit)}</div></div>
        <div class="card kpi"><div class="bar"></div><div class="lbl">Income · ${esc(d.from)} to ${esc(d.to)}</div><div class="val">${money(d.pl.income)}</div></div><div class="card kpi"><div class="bar"></div><div class="lbl">Costs</div><div class="val">${money(d.pl.expense)}</div></div><div class="card kpi ${d.pl.profit < 0 ? 'err' : 'done'}"><div class="bar"></div><div class="lbl">Profit</div><div class="val">${money(d.pl.profit)}</div></div></div>
      <div class="card" style="margin-top:14px"><div class="card-h"><h3>Balances by account</h3></div><div class="tbl-wrap"><table class="t"><thead><tr><th>Code</th><th>Account</th><th>Kind</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>${d.trial.rows.map(r => `<tr><td>${esc(r.code)}</td><td>${esc(r.name)}</td><td>${esc(r.kind)}</td><td class="num">${r.debit ? money(r.debit) : ''}</td><td class="num">${r.credit ? money(r.credit) : ''}</td></tr>`).join('')}</tbody><tfoot><tr><td colspan="3">Total</td><td class="num">${money(d.trial.debit)}</td><td class="num">${money(d.trial.credit)}</td></tr></tfoot></table>${d.trial.rows.length ? '' : '<div class="empty">No journals yet.</div>'}</div></div>
      <div class="card" style="margin-top:14px"><div class="card-h"><h3>Last journals</h3></div><div class="tbl-wrap"><table class="t"><thead><tr><th>No.</th><th>Date</th><th>From</th><th>Note</th><th></th></tr></thead><tbody>${d.journals.map(j => `<tr><td>${esc(j.journal_no)}</td><td>${esc(j.journal_date)}</td><td>${esc(j.source_type)}</td><td>${esc(j.memo || '')}</td><td>${j.reversed_by_id ? '<span class="chip lock">Reversed</span>' : j.reverses_id ? '<span class="chip warn">Reversal</span>' : ''}</td></tr>`).join('')}</tbody></table></div></div>`;
  }

  // ---------- stock: on hand, ledger, lots; every change goes through the stock service ----------
  let PICK = null;
  const pickData = async () => PICK = PICK || await api('stock/pick');
  const opt = (rows, f) => [['', 'Choose…'], ...rows.map(f)];
  async function stockAction(kind, preset = {}, done) {
    busy(true); let p; try { p = await pickData(); } finally { busy(false); }
    const prod = { name: 'product', label: 'Product', required: true, value: preset.product, options: opt(p.products, x => [x.id, x.sku + ' · ' + x.name]) };
    const locs = (name, label, value) => ({ name, label, required: true, value, options: opt(p.locations, x => [x.id, x.name + (x.kind ? ' (' + x.kind.toLowerCase().replace(/_/g, ' ') + ')' : '')]) });
    const send = path => async v => { const r = await api(path, { method: 'POST', body: v }); done && done(); return r; };
    if (kind === 'opening') return miniForm('Opening stock', 'Units you already have. They become a lot with their cost, so sales can be costed.', [prod, locs('location', 'Location'), { name: 'qty', label: 'Units', type: 'number', required: true },
      { name: 'unit_cost', label: 'Cost per unit (' + S.boot.base_currency + ')', type: 'number', required: true, help: 'Landed cost of one unit.' }, { name: 'received_date', label: 'Received on', type: 'date', required: true, value: S.boot.today },
      { name: 'owner', label: 'Owner', options: opt(p.owners, x => [x.id, x.name]), help: 'Leave empty for company stock.' }], send('stock/opening'));
    if (kind === 'transfer') return miniForm('Move stock', 'Oldest lots move first. The lot and its cost travel with the units.', [prod, locs('from', 'From', preset.from), locs('to', 'To'), { name: 'qty', label: 'Units', type: 'number', required: true }, { name: 'reason', label: 'Note' }], send('stock/transfer'), 'Move');
    if (kind === 'adjust') return miniForm('Adjust stock', 'Use minus to take units out (broken, lost) and plus to add found units. The value goes to the ledger.', [prod, locs('location', 'Location', preset.location), { name: 'qty', label: 'Change (+ or −)', type: 'number', required: true, help: 'Example: -2 or 3' },
      { name: 'reason', label: 'Reason', required: true }], send('stock/adjust'), 'Adjust');
    if (kind === 'ownership') return miniForm('Give units to another owner', 'The lot is split. Cost and received date stay the same, so the selling order does not change.', [{ name: 'lot', label: 'Lot', required: true, value: preset.lot, options: [[preset.lot, preset.lot_code]] }, { name: 'qty', label: 'Units', type: 'number', required: true },
      { name: 'to_owner', label: 'New owner', required: true, options: opt(p.owners, x => [x.id, x.name]) }], send('stock/ownership'), 'Give');
  }
  async function pageStock(st = {}) {
    busy(true); let d; try { d = await api('stock/on-hand?q=' + encodeURIComponent(st.q || '')); } finally { busy(false); }
    const rows = st.low ? d.rows.filter(r => r.low) : d.rows;
    $('#main').innerHTML = `<div class="page-head"><div><h1>Stock on hand</h1><div class="sub">Units come from the stock ledger and its lots. Nobody types a stock number.</div></div>
      <div class="actions">${d.can_change ? `<button class="btn" data-sa="opening">${ic('plus')} Opening stock</button><button class="btn" data-sa="transfer">${ic('truck')} Move</button><button class="btn" data-sa="adjust">${ic('wand')} Adjust</button>` : ''}<button class="btn" id="sChk" title="Compare the product numbers with the ledger">${ic('check')} Check</button></div></div>
      <div class="kpis"><div class="card kpi"><div class="bar"></div><div class="lbl">Units in stock</div><div class="val">${d.total_units.toLocaleString('en-GB')}</div></div>
        ${d.show_value ? `<div class="card kpi"><div class="bar"></div><div class="lbl">Value at cost</div><div class="val">${money(d.total_value)}</div><div class="foot">equals ledger account 1200</div></div>` : ''}
        <div class="card kpi ${d.low ? 'warn' : 'done'}" data-low="1" style="cursor:pointer"><div class="bar"></div><div class="lbl">Low stock</div><div class="val">${d.low}</div><div class="foot">${st.low ? 'showing only these · click to show all' : 'click to show only these'}</div></div></div>
      <div class="card" style="margin-top:14px"><div class="list-bar"><input class="q" id="sQ" placeholder="SKU or product name…" value="${esc(st.q || '')}"></div><div class="tbl-wrap"><table class="t"><thead><tr><th>SKU</th><th>Product</th><th class="num">Units</th><th class="num">Can be sold</th>${d.show_value ? '<th class="num">Value</th>' : ''}<th></th></tr></thead>
      <tbody>${rows.map(r => `<tr class="click" data-id="${r.id}"><td>${esc(r.sku)}</td><td>${esc(r.name)}</td><td class="num">${r.units}</td><td class="num">${r.available}</td>${d.show_value ? `<td class="num">${money(r.value)}</td>` : ''}<td>${r.low ? '<span class="chip warn">Low</span> ' : ''}${r.mismatch ? '<span class="chip err">Differs from ledger</span>' : ''}</td></tr>`).join('')}</tbody></table>
      ${rows.length ? '' : `<div class="empty">${ic('file')}<div>${st.low ? 'No product is low.' : 'No products yet. Create a product, then add its opening stock.'}</div></div>`}</div></div>`;
    $$('#main [data-sa]').forEach(b => b.onclick = () => stockAction(b.dataset.sa, {}, () => pageStock(st)));
    $('#sQ').onkeydown = e => { if (e.key === 'Enter') pageStock({ ...st, q: e.target.value }); };
    $('#main [data-low]').onclick = () => pageStock({ ...st, low: !st.low });
    $('#sChk').onclick = async () => { try { const c = await api('stock/check'); toast(c.mismatch || c.negative_balances || !c.value_matches_ledger ? `Check found a problem: ${c.mismatch} product(s) differ; value matches ledger: ${c.value_matches_ledger ? 'yes' : 'NO'}. Tasks were opened.` : 'Check passed: every product equals the ledger, and the stock value equals the accounts.', c.mismatch || !c.value_matches_ledger ? 'err' : 'ok'); pageStock(st); } catch (e) { toast(e.message, 'err'); } };
    $$('#main tr[data-id]').forEach(tr => tr.onclick = () => go('#/stock/product/' + tr.dataset.id));
  }
  async function pageStockProduct(id) {
    busy(true); let d, o; try { d = await api('stock/product/' + id); o = (await api('stock/on-hand')).rows.find(r => r.id === id) || {}; } finally { busy(false); }
    const cost = d.rows.length && d.rows[0].unit_cost_gbp !== undefined;
    $('#main').innerHTML = `<div class="page-head"><div><div class="small muted"><a href="#/stock">Stock on hand</a> ›</div><h1>${esc(o.sku || 'Product ' + id)}</h1><div class="sub">${esc(o.name || '')}</div></div>
      <div class="actions"><a class="btn" href="#/rec/${esc(S.boot.product_form)}/${id}">Product record</a><a class="btn" href="#/stock/ledger?product=${id}">Ledger of this product</a><button class="btn" data-sa="transfer">${ic('truck')} Move</button><button class="btn" data-sa="adjust">${ic('wand')} Adjust</button></div></div>
      <div class="kpis"><div class="card kpi"><div class="bar"></div><div class="lbl">Units in stock</div><div class="val">${d.units}</div></div><div class="card kpi"><div class="bar"></div><div class="lbl">Can be sold</div><div class="val">${d.available}</div><div class="foot">sellable places, minus reserved</div></div></div>
      <div class="card" style="margin-top:14px"><div class="card-h"><h3>Where the units are · oldest lot first (sold first)</h3></div><div class="tbl-wrap"><table class="t"><thead><tr><th>Location</th><th>Lot</th><th>Received</th><th>Owner</th><th>Pool / private</th>${cost ? '<th class="num">Cost</th>' : ''}<th class="num">Units</th></tr></thead>
      <tbody>${d.rows.map(r => `<tr><td>${esc(r.location)}${r.kind ? ` <span class="small muted">${esc(r.kind.toLowerCase().replace(/_/g, ' '))}</span>` : ''}</td><td>${esc(r.lot_code)}</td><td>${esc(r.received_date)}</td><td>${esc(r.owner || 'Company')}</td><td>${esc(r.visibility || '')}</td>${cost ? `<td class="num">${(+r.unit_cost_gbp).toFixed(4)}${r.landed_status === 'PROVISIONAL' ? ' <span class="chip warn plain" title="Landed cost is not final yet">prov.</span>' : ''}</td>` : ''}<td class="num">${r.qty}</td></tr>`).join('')}</tbody></table>${d.rows.length ? '' : '<div class="empty">No units in stock.</div>'}</div></div>`;
    $$('#main [data-sa]').forEach(b => b.onclick = () => stockAction(b.dataset.sa, { product: id }, () => pageStockProduct(id)));
  }
  const MOVE = { opening: 'Opening', purchase_in: 'Received', sale_out: 'Sold', adjustment: 'Adjusted', return_in: 'Returned', transfer_out: 'Moved out', transfer_in: 'Moved in', ownership_out: 'Owner out', ownership_in: 'Owner in', damaged: 'Damaged', count_adjust: 'Count', written_off: 'Written off' };
  async function pageStockLedger(st = {}) {
    busy(true); let d; try { d = await api('stock/ledger?' + new URLSearchParams({ page: st.page || 1, q: st.q || '', type: st.type || '', product: st.product || '' })); } finally { busy(false); }
    const pages = Math.max(1, Math.ceil(d.total / 50)), cost = d.rows.length && d.rows[0].unit_cost_gbp !== undefined;
    $('#main').innerHTML = `<div class="page-head"><div><h1>Stock ledger</h1><div class="sub">Every change of stock, with its lot, cost and source. ${d.total.toLocaleString('en-GB')} lines. Lines are never edited; a mistake is corrected by a new line.</div></div>
      <div class="actions"><button class="btn" data-sa="transfer">${ic('truck')} Move</button><button class="btn" data-sa="adjust">${ic('wand')} Adjust</button></div></div>
      <div class="card"><div class="list-bar"><input class="q" id="gQ" placeholder="SKU or lot…" value="${esc(st.q || '')}"><select class="q" id="gT"><option value="">Every kind</option>${Object.entries(MOVE).map(([k, l]) => `<option value="${k}" ${st.type === k ? 'selected' : ''}>${l}</option>`).join('')}</select>${st.product ? `<span class="chip accent plain">One product <a href="#/stock/ledger" title="Show all">✕</a></span>` : ''}</div>
      <div class="tbl-wrap"><table class="t"><thead><tr><th>When</th><th>SKU</th><th>What</th><th class="num">Change</th><th class="num">After</th><th>Location</th><th>Lot</th>${cost ? '<th class="num">Cost</th>' : ''}<th>Source</th><th>By</th></tr></thead>
      <tbody>${d.rows.map(r => `<tr><td style="white-space:nowrap">${esc(r.created_at)}</td><td><a href="#/stock/product/${r.product_id}">${esc(r.sku)}</a></td><td>${esc(MOVE[r.movement_type] || r.movement_type)}</td><td class="num" style="color:${r.qty_change < 0 ? 'var(--err)' : 'var(--done)'};font-weight:600">${r.qty_change > 0 ? '+' : ''}${r.qty_change}</td><td class="num">${r.stock_after ?? ''}</td><td>${esc(r.location || '')}</td><td>${esc(r.lot_code || '')}</td>${cost ? `<td class="num">${r.unit_cost_gbp === null ? '' : (+r.unit_cost_gbp).toFixed(4)}</td>` : ''}<td>${esc(r.source_type || '')}${r.source_id ? ' #' + r.source_id : ''}${r.notes ? `<div class="small muted">${esc(r.notes)}</div>` : ''}</td><td>${esc(r.by || 'System')}</td></tr>`).join('')}</tbody></table>${d.rows.length ? '' : '<div class="empty">No stock lines yet.</div>'}</div>
      <div class="pager"><span>Page ${d.page} of ${pages}</span><button class="btn sm" id="pPrev" ${d.page <= 1 ? 'disabled' : ''}>Previous</button><button class="btn sm" id="pNext" ${d.page >= pages ? 'disabled' : ''}>Next</button></div></div>`;
    const again = x => pageStockLedger({ ...st, act: null, ...x });
    $$('#main [data-sa]').forEach(b => b.onclick = () => stockAction(b.dataset.sa, { product: st.product }, () => again({})));
    $('#gQ').onkeydown = e => { if (e.key === 'Enter') again({ q: e.target.value, page: 1 }); }; $('#gT').onchange = e => again({ type: e.target.value, page: 1 });
    $('#pPrev').onclick = () => again({ page: d.page - 1 }); $('#pNext').onclick = () => again({ page: d.page + 1 });
    if (st.act) stockAction(st.act, { product: st.product }, () => again({}));
  }
  async function pageLots(st = {}) {
    busy(true); let d; try { d = await api('stock/lots?' + new URLSearchParams({ q: st.q || '', left: st.all ? '0' : '1' })); } finally { busy(false); }
    $('#main').innerHTML = `<div class="page-head"><div><h1>Lots and owners</h1><div class="sub">A lot = units received together, with their cost and owner. The oldest lot is sold first.</div></div>
      <div class="actions"><div class="seg"><button type="button" class="${st.all ? '' : 'on'}" data-all="0">With stock</button><button type="button" class="${st.all ? 'on' : ''}" data-all="1">All</button></div></div></div>
      <div class="card"><div class="list-bar"><input class="q" id="lq" placeholder="SKU or lot…" value="${esc(st.q || '')}"></div><div class="tbl-wrap"><table class="t"><thead><tr><th>Lot</th><th>SKU</th><th>Received</th><th>From</th><th>Owner</th><th>Pool / private</th>${d.show_value ? '<th class="num">Cost</th>' : ''}<th class="num">Received</th><th class="num">Left</th><th></th></tr></thead>
      <tbody>${d.rows.map(r => `<tr><td>${esc(r.lot_code)}</td><td><a href="#/stock/product/${r.product_id}">${esc(r.sku)}</a></td><td>${esc(r.received_date)}</td><td>${esc(r.source)}</td><td>${esc(r.owner || 'Company')}</td><td>${esc(r.visibility || '')}</td>${d.show_value ? `<td class="num">${(+r.unit_cost_gbp).toFixed(4)}${r.landed_status === 'PROVISIONAL' ? ' <span class="chip warn plain">prov.</span>' : ''}</td>` : ''}<td class="num">${r.qty}</td><td class="num">${r.qty_left}</td><td>${r.qty_left > 0 && ['OWNER', 'MANAGER'].includes(S.boot.user.role) ? `<button class="btn sm" data-own="${r.id}" data-code="${esc(r.lot_code)}">Give to owner</button>` : ''}</td></tr>`).join('')}</tbody></table>${d.rows.length ? '' : '<div class="empty">No lots yet.</div>'}</div></div>`;
    $$('#main [data-all]').forEach(b => b.onclick = () => pageLots({ ...st, all: b.dataset.all === '1' }));
    $('#lq').onkeydown = e => { if (e.key === 'Enter') pageLots({ ...st, q: e.target.value }); };
    $$('#main [data-own]').forEach(b => b.onclick = () => stockAction('ownership', { lot: b.dataset.own, lot_code: b.dataset.code }, () => pageLots(st)));
  }

  // ---------- pool, held stock, counts, prices, bins ----------
  async function pagePool() {
    busy(true); let d; try { d = await api('stock/pool'); } finally { busy(false); }
    const tot = d.rows.reduce((a, r) => a + +r.value, 0);
    $('#main').innerHTML = `<div class="page-head"><div><h1>Pool</h1><div class="sub">Who owns the units in stock, per product: pool and private. Total value ${money(tot)}.</div></div></div>
      <div class="card"><div class="tbl-wrap"><table class="t"><thead><tr><th>SKU</th><th>Product</th><th>Owner</th><th>Pool / private</th><th class="num">Units</th><th class="num">Value</th></tr></thead><tbody>
      ${d.rows.map(r => `<tr class="click" data-id="${r.product_id}"><td>${esc(r.sku)}</td><td>${esc(r.name)}</td><td>${esc(r.owner || 'Company')}</td><td><span class="chip ${r.visibility === 'POOL' ? 'accent' : 'pend'} plain">${esc(r.visibility || '')}</span></td><td class="num">${r.units}</td><td class="num">${money(r.value)}</td></tr>`).join('')}</tbody></table>${d.rows.length ? '' : '<div class="empty">No stock yet.</div>'}</div></div>`;
    $$('#main tr[data-id]').forEach(tr => tr.onclick = () => go('#/stock/product/' + tr.dataset.id));
  }
  async function pageHeld() {
    busy(true); let d; try { d = await api('stock/held'); } finally { busy(false); }
    $('#main').innerHTML = `<div class="page-head"><div><h1>Held stock</h1><div class="sub">Units that are in stock but cannot be sold: returns, quarantine, damaged, missing. Decide: move back to a shelf, or write off.</div></div></div>
      <div class="card"><div class="tbl-wrap"><table class="t"><thead><tr><th>SKU</th><th>Product</th><th>Where</th><th>Lot</th><th class="num">Units</th>${d.rows.length && d.rows[0].value !== undefined ? '<th class="num">Value</th>' : ''}<th class="num">Days</th><th></th></tr></thead><tbody>
      ${d.rows.map(r => `<tr><td><a href="#/stock/product/${r.product_id}">${esc(r.sku)}</a></td><td>${esc(r.name)}</td><td>${esc(r.location)} <span class="small muted">${esc((r.kind || '').toLowerCase())}</span></td><td>${esc(r.lot_code)}</td><td class="num">${r.qty}</td>${r.value !== undefined ? `<td class="num">${money(r.value)}</td>` : ''}<td class="num" style="${r.days > 14 ? 'color:var(--err);font-weight:600' : ''}">${r.days}</td>
        <td style="white-space:nowrap">${d.can_change ? `<button class="btn sm" data-mv="${r.product_id}" data-from="${r.location_id}">Move</button> <button class="btn sm" data-wo="${r.product_id}" data-loc="${r.location_id}">Write off</button>` : ''}</td></tr>`).join('')}</tbody></table>${d.rows.length ? '' : `<div class="empty">${ic('check')}<div>Nothing is held.</div></div>`}</div></div>`;
    $$('#main [data-mv]').forEach(b => b.onclick = () => stockAction('transfer', { product: b.dataset.mv, from: b.dataset.from }, pageHeld));
    $$('#main [data-wo]').forEach(b => b.onclick = () => stockAction('adjust', { product: b.dataset.wo, location: b.dataset.loc }, pageHeld));
  }
  const cChip = s => ({ OPEN: 'prog', SUBMITTED: 'warn', APPROVED: 'accent', POSTED: 'done', CANCELLED: 'lock' }[s] || 'pend');
  async function pageCounts() {
    busy(true); let d; try { d = await api('stock/counts'); } finally { busy(false); }
    $('#main').innerHTML = `<div class="page-head"><div><h1>Stock counts</h1><div class="sub">Count → submit → someone else approves → only the differences are posted. A count never overwrites stock.</div></div><div class="actions">${d.can_change ? `<button class="btn primary" id="cNew">${ic('plus')} New count</button>` : ''}</div></div>
      <div class="card"><div class="tbl-wrap"><table class="t"><thead><tr><th>Count</th><th>Date</th><th>Location</th><th>Counted by</th><th class="num">Difference value</th><th>Status</th></tr></thead><tbody>
      ${d.rows.map(c => `<tr class="click" data-id="${c.id}"><td>${esc(c.count_code)}</td><td>${esc(c.d_count)}</td><td>${esc(c.location || '')}</td><td>${esc(c.counted_by || '')}</td><td class="num">${c.variance_value === null ? '' : money(c.variance_value)}</td><td><span class="chip ${cChip(c.status)}">${esc(c.status)}</span></td></tr>`).join('')}</tbody></table>${d.rows.length ? '' : '<div class="empty">No counts yet.</div>'}</div></div>`;
    $$('#main tr[data-id]').forEach(tr => tr.onclick = () => go('#/stock/counts/' + tr.dataset.id));
    if (d.can_change) $('#cNew').onclick = async () => { const p = await pickData(); miniForm('New stock count', 'Every product that has stock at this location gets a line.', [{ name: 'location', label: 'Location', required: true, options: opt(p.locations, x => [x.id, x.name]) }],
      async v => { const r = await api('stock/counts', { method: 'POST', body: v }); go('#/stock/counts/' + r.id); return r; }, 'Open count'); };
  }
  async function pageCount(id) {
    busy(true); let d; try { d = await api('stock/counts/' + id); } finally { busy(false); }
    const c = d.count, open = c.status === 'OPEN', boss = ['OWNER', 'MANAGER'].includes(S.boot.user.role);
    $('#main').innerHTML = `<div class="page-head"><div><div class="small muted"><a href="#/stock/counts">Stock counts</a> ›</div><h1>${esc(c.code)} <span class="chip ${cChip(c.status)}">${esc(c.status)}</span></h1><div class="sub">${esc(c.location || '')} · ${esc(c.date)}${c.blind ? ' · blind count: the system number is hidden until you submit' : ''}</div></div>
      <div class="actions">${open ? `<button class="btn" id="kSave">${ic('save')} Save</button><button class="btn primary" id="kSubmit">Submit</button>` : ''}${c.status === 'SUBMITTED' && boss ? `<button class="btn primary" data-step="approve" ${c.mine ? 'disabled title="You counted this yourself. Somebody else has to approve it."' : ''}>Approve</button>` : ''}${c.status === 'APPROVED' && boss ? `<button class="btn primary" data-step="post">Post differences</button>` : ''}${['OPEN', 'SUBMITTED', 'APPROVED'].includes(c.status) ? `<button class="btn" data-step="cancel">Cancel count</button>` : ''}</div></div>
      <div class="card"><div class="tbl-wrap"><table class="t"><thead><tr><th>SKU</th><th>Product</th>${c.blind ? '' : '<th class="num">System</th>'}<th class="num">Counted</th>${c.blind ? '' : '<th class="num">Difference</th>'}<th>Note</th></tr></thead><tbody>
      ${d.lines.map(l => { const diff = l.qty_counted === null || l.qty_system === null ? null : l.qty_counted - l.qty_system; return `<tr data-p="${l.product_id}"><td>${esc(l.sku)}</td><td>${esc(l.name)}</td>${c.blind ? '' : `<td class="num">${l.qty_system}</td>`}<td class="num">${open ? `<input class="q" style="min-width:80px;width:90px;text-align:right" type="number" min="0" data-q value="${l.qty_counted ?? ''}">` : (l.qty_counted ?? '')}</td>${c.blind ? '' : `<td class="num" style="font-weight:600;color:${diff ? 'var(--err)' : 'inherit'}">${diff === null ? '' : (diff > 0 ? '+' : '') + diff}</td>`}<td>${open ? `<input class="q" data-n value="${esc(l.note || '')}" placeholder="Note">` : esc(l.note || '')}</td></tr>`; }).join('')}</tbody></table>${d.lines.length ? '' : '<div class="empty">No stock at this location to count.</div>'}</div></div>`;
    const save = async () => { const lines = $$('#main tr[data-p]').filter(tr => $('[data-q]', tr).value !== '').map(tr => ({ product: +tr.dataset.p, qty: +$('[data-q]', tr).value, note: $('[data-n]', tr).value || null })); if (lines.length) await api(`stock/counts/${id}/lines`, { method: 'POST', body: { lines } }); };
    const step = async s => { try { const r = await api(`stock/counts/${id}/${s}`, { method: 'POST' }); toast(r.message); pageCount(id); } catch (e) { toast(e.message, 'err'); } };
    if (open) { $('#kSave').onclick = async () => { try { await save(); toast('Count saved'); } catch (e) { toast(e.message, 'err'); } }; $('#kSubmit').onclick = async () => { try { await save(); await step('submit'); } catch (e) { toast(e.message, 'err'); } }; }
    $$('#main [data-step]').forEach(b => b.onclick = () => step(b.dataset.step));
  }
  async function pagePrices(pid) {
    busy(true); let d, o; try { d = await api('prices/' + pid); o = (await api('stock/on-hand')).rows.find(r => r.id === pid) || {}; } finally { busy(false); }
    const can = ['OWNER', 'MANAGER', 'TL'].includes(S.boot.user.role);
    $('#main').innerHTML = `<div class="page-head"><div><div class="small muted"><a href="#/rec/${esc(S.boot.product_form)}/${pid}">Product</a> ›</div><h1>Prices · ${esc(o.sku || '')}</h1><div class="sub">A price holds from its start date until the next one. Each sale is matched to the price of its own date.</div></div><div class="actions">${can ? `<button class="btn primary" id="prNew">${ic('plus')} New price</button>` : ''}</div></div>
      <div class="card"><div class="tbl-wrap"><table class="t"><thead><tr><th>Marketplace</th><th>From</th><th>To</th><th class="num">Raw</th><th class="num">Sale</th><th>Reason</th></tr></thead><tbody>${d.timeline.map(p => `<tr><td>${esc(p.marketplace)}</td><td>${esc(p.from_date)}</td><td>${p.to_date ? esc(p.to_date) : '<span class="chip done">now</span>'}</td><td class="num">${money(p.raw_price)}</td><td class="num">${money(p.sale_price)}</td><td>${esc(p.reason || '')}</td></tr>`).join('')}</tbody></table>${d.timeline.length ? '' : '<div class="empty">No price yet.</div>'}</div></div>`;
    if (can) $('#prNew').onclick = () => miniForm('New price', 'The price before it ends the day before the start date.', [{ name: 'marketplace', label: 'Marketplace', required: true, options: opt(d.marketplaces, x => [x.id, x.name]) }, { name: 'raw_price', label: 'Raw price', type: 'number', required: true }, { name: 'sale_price', label: 'Sale price', type: 'number', required: true },
      { name: 'from_date', label: 'From', type: 'date', required: true, value: S.boot.today }, { name: 'reason', label: 'Reason', options: opt(d.reasons, x => [x.id, x.name]) }], async v => { const r = await api('prices/' + pid, { method: 'POST', body: v }); pagePrices(pid); return r; });
  }
  async function binGenerator(done) {
    const p = await pickData();
    miniForm('Make bins', 'Creates one bin per level and position, coded by your location format. Bins that already exist are skipped.', [{ name: 'parent', label: 'Inside (warehouse or zone)', required: true, options: opt(p.locations.filter(l => l.kind !== 'BIN'), x => [x.id, x.name]) }, { name: 'zone', label: 'Zone code', required: true, help: 'Example: A' },
      { name: 'levels', label: 'Levels', type: 'number', required: true, value: 4 }, { name: 'positions', label: 'Positions per level', type: 'number', required: true, value: 10 }],
      async v => { const pre = await api('stock/bins', { method: 'POST', body: { ...v, create: false } }); const r = await api('stock/bins', { method: 'POST', body: { ...v, create: true } }); PICK = null; done && done(); return { message: `${r.created} bins created (${pre.codes[0]} … ${pre.codes[pre.codes.length - 1]}), ${r.skipped} already there` }; }, 'Create bins');
  }

  // ---------- modal ----------
  function modal(title, sub, body, foot, wide = true) {
    const ov = document.createElement('div'); ov.className = 'overlay';
    ov.innerHTML = `<div class="modal ${wide ? 'wide' : ''}"><div class="modal-h"><div><h2>${esc(title)}</h2>${sub ? `<div class="small muted">${esc(sub)}</div>` : ''}</div><button class="btn ghost sm x" title="Close">${ic('x')}</button></div><div class="modal-b">${body}</div>${foot ? `<div class="modal-f">${foot}</div>` : ''}</div>`;
    document.body.appendChild(ov);
    const close = () => ov.remove(); $('.x', ov).onclick = close; ov.addEventListener('mousedown', e => { if (e.target === ov) close(); });
    return { ov, close };
  }
  document.addEventListener('keydown', e => { const typing = /INPUT|TEXTAREA|SELECT/.test((e.target.tagName || ''));
    if (e.key === 'Escape') { const o = $$('.overlay').pop(); if (o) o.remove(); }
    else if (!typing && (e.key === 'n' || e.key === 'N') && !$('.overlay')) openCreate();
    else if (!typing && e.key === '/') { e.preventDefault(); $('#q') && $('#q').focus(); } });

  // ---------- + Create: everything that can be created, grouped by department; new modules appear by themselves ----------
  let lastCreateQ = '';
  function openCreate(q = '') {
    const list = S.boot.create, depts = [...new Set(list.map(c => c.dept))];
    const { ov, close } = modal('Create', `Everything you can create, from anywhere – ${list.length} entries. Each one opens its form and is saved in its own module.`,
      `<input class="q crq" placeholder="Type what you want to create… (e.g. research, printer, sample, expense, carrier)" autofocus><div class="cr-grid" id="crGrid"></div>`,
      `<span class="left">Tip: press <span class="kbd">N</span> on any screen.</span>`);
    const draw = () => { const t = $('.crq', ov).value.trim().toLowerCase();
      $('#crGrid', ov).innerHTML = depts.map(d => { const its = list.filter(c => c.dept === d && (!t || c.title.toLowerCase().includes(t) || c.key.includes(t))); if (!its.length) return '';
        return `<div class="cr-dept"><h4>${esc(d)} <span class="faint" style="font-weight:400">${its.length}</span></h4>${its.map(c => `<div class="cr-item" data-k="${esc(c.key)}">${ic('plus')}${esc(c.title)}${c.quick ? '<span class="q">quick</span>' : ''}</div>`).join('')}</div>`; }).join('') || `<div class="empty">Nothing matches “${esc(t)}”.</div>`; };
    $('.crq', ov).value = q; draw(); $('.crq', ov).oninput = draw; setTimeout(() => $('.crq', ov).focus(), 30);
    $('#crGrid', ov).onclick = e => { const it = e.target.closest('.cr-item'); if (!it) return; lastCreateQ = $('.crq', ov).value; close(); openForm(it.dataset.k, null, { fromCreate: true }); };
  }
  MO.openCreate = openCreate;

  // ---------- form: built from the registry; Quick / Full; Back, Undo, Redo, Fill from last, suggestions ----------
  const lastSaved = (() => { try { return JSON.parse(localStorage.getItem('mo_last_forms') || '{}'); } catch (e) { return {}; } })();
  async function openForm(key, id, opt = {}) {
    busy(true); let def, rec;
    try { def = await api('form/' + key); rec = id ? (await api(`record/${key}/${id}`)).values : def.blank; } catch (e) { busy(false); return toast(e.message, 'err'); }
    busy(false);
    const flds = def.sections.flatMap(s => s.fields), hasQuick = flds.some(f => f.quick) && !id;
    let mode = hasQuick ? 'quick' : 'full';
    const uid = 'f' + Math.random().toString(36).slice(2, 8), val = {}, undo = [], redo = [];
    flds.forEach(f => { val[f.name] = (opt.preset || {})[f.col] ?? (opt.preset || {})[f.name] ?? rec[f.name] ?? rec[f.col] ?? ''; });
    const { ov, close } = modal((id ? 'Edit · ' : 'New · ') + def.title, id ? 'Changes are kept in the history of this record.' : 'Saved in its own module; the list opens where it went.',
      `<div class="fhost"><div class="fmbar">${hasQuick ? `<div class="seg"><button type="button" data-m="full">Full</button><button type="button" data-m="quick">Quick</button></div>` : ''}<span class="small muted" id="fInfo"></span></div><div class="fbody" id="fBody"></div></div>`,
      `<span class="left small muted" id="fMsg"></span><button class="btn" id="fmCancel">Cancel</button><button class="btn primary" id="fmSave">${ic('save')} Save</button>`);
    const bar = document.createElement('div'); bar.className = 'mo-assist';
    bar.innerHTML = (opt.fromCreate ? `<button type="button" class="btn sm" data-a="back" title="Back to the Create list (keeps your search)">← Back</button>` : '') +
      `<button type="button" class="btn sm" data-a="undo" title="Undo the last change">↶ Undo</button><button type="button" class="btn sm" data-a="redo" title="Redo">↷ Redo</button>` +
      (id ? '' : `<button type="button" class="btn sm" data-a="last" title="Fill the form with the values of the last saved one">⟲ Fill from last</button>`) + `<button type="button" class="btn sm ghost" data-a="clear" title="Empty all fields (can be undone)">Clear</button>`;
    $('.modal-h', ov).insertBefore(bar, $('.x', ov));
    const shown = f => (mode === 'full' || f.quick || f.required) && (!f.show_if || cond(f.show_if));
    const cond = ex => { const m = ex.match(/^([\w.]+)\s*(!=|=|>|<)\s*(.*)$/); if (!m) return true; const h = String(val[m[1].replace(/^parent\./, '')] ?? ''); return m[2] === '=' ? h === m[3] : m[2] === '!=' ? h !== m[3] : m[2] === '>' ? +h > +m[3] : +h < +m[3]; };
    const input = f => { const idn = `${uid}_${f.name}`, v = val[f.name] ?? '', ro = f.readonly ? 'disabled' : '';
      if (f.numbered && !id && !v) return `<input id="${idn}" data-f="${f.name}" type="text" value="" placeholder="Given by the system when saved" autocomplete="off">`;
      if (f.has_options) return `<div class="selwrap"><select id="${idn}" data-f="${f.name}" ${ro} ${f.type === 'multiselect' ? 'multiple' : ''}><option value="">${v !== '' ? esc(v) : 'Choose…'}</option></select>${f.add_form && !f.readonly ? `<button type="button" class="addbtn" data-add="${esc(f.name)}" title="Add a new one – opens its form, saves it to the list and selects it here">${ic('plus')}<span>Add</span></button>` : ''}</div>`;
      if (f.type === 'textarea') return `<textarea id="${idn}" data-f="${f.name}" rows="3" ${ro}>${esc(v)}</textarea>`;
      if (f.type === 'check') return `<label class="chk"><input id="${idn}" data-f="${f.name}" type="checkbox" ${+v ? 'checked' : ''} ${ro}> Yes</label>`;
      const t = { number: 'number', money: 'number', date: 'date', email: 'email', phone: 'tel', link: 'url', file: 'text' }[f.type] || 'text';
      return `<input id="${idn}" data-f="${f.name}" type="${f.secret ? 'password' : t}" value="${esc(v)}" ${t === 'number' ? `step="${f.step || (f.type === 'money' ? '0.01' : 'any')}"` : ''} ${ro} autocomplete="off">`; };
    function draw(errors = {}) {
      $('#fBody', ov).innerHTML = def.sections.map(s => { const fs = s.fields.filter(shown); if (!fs.length) return '';
        return `<div class="fsec"><div class="fsec-h"><b>${esc(s.title)}</b><span class="small muted">${fs.length} field${fs.length === 1 ? '' : 's'}</span></div><div class="form">${fs.map(f => `<div class="fld ${f.required ? 'is-req' : ''} ${errors[f.name] ? 'has-err' : ''}" data-w="${f.name}">
          <label for="${uid}_${f.name}"><span>${esc(f.label)}</span>${f.required ? ' <span class="req">*</span>' : ''}${f.help ? `<span class="help" title="${esc(f.help)}">?</span>` : ''}</label>${input(f)}${errors[f.name] ? `<div class="errtext">${esc(errors[f.name][0] || errors[f.name])}</div>` : ''}</div>`).join('')}</div></div>`; }).join('');
      $$('.seg button', ov).forEach(b => b.classList.toggle('on', b.dataset.m === mode));
      const vis = flds.filter(shown); $('#fInfo', ov).innerHTML = `<b>${vis.length}</b> fields · <b>${vis.filter(f => f.required).length}</b> required · marked <span style="color:var(--err)">*</span>`;
      vis.filter(f => f.has_options).forEach(loadOptions); paint();
    }
    const optCache = {};
    async function loadOptions(f) { const el = $(`[data-f="${f.name}"]`, ov); if (!el) return;
      try { optCache[f.name] = optCache[f.name] || (await api(`options/${key}/${f.name}`)).options; } catch (e) { optCache[f.name] = []; }
      const cur = String(val[f.name] ?? ''), multi = f.type === 'multiselect', picked = multi ? cur.split(',') : [cur];
      el.innerHTML = (multi ? '' : `<option value="">Choose…</option>`) + optCache[f.name].map(([v, l]) => `<option value="${esc(v)}" ${picked.includes(v) ? 'selected' : ''}>${esc(l)}</option>`).join(''); if (multi) return;
      if (cur && !optCache[f.name].some(o => o[0] === cur)) el.insertAdjacentHTML('beforeend', `<option value="${esc(cur)}" selected>${esc(cur)}</option>`); }
    const read = el => el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.multiple ? [...el.selectedOptions].map(o => o.value).join(',') : el.value;
    const setVal = (name, v) => { val[name] = v; const el = $(`[data-f="${name}"]`, ov); if (el) { if (el.type === 'checkbox') el.checked = !!+v; else el.value = v; } };
    const change = (name, nw) => { const old = val[name] ?? ''; if (String(old) === String(nw)) return; undo.push({ name, old, nw }); redo.length = 0; val[name] = nw; if (flds.some(f => f.show_if && f.show_if.startsWith(name))) draw(); paint(); };
    const paint = () => { const q = a => $(`[data-a=${a}]`, bar); q('undo').disabled = !undo.length; q('redo').disabled = !redo.length; if (q('last')) q('last').disabled = !lastSaved[key]; };
    $('#fBody', ov).addEventListener('change', e => { const el = e.target; if (!el.dataset.f) return; change(el.dataset.f, read(el)); const w = el.closest('.fld'); if (w && w.classList.contains('has-err')) { w.classList.remove('has-err'); const m = w.querySelector('.errtext'); m && m.remove(); } });
    // suggestions: the values used most on this form, as quick-pick chips under an empty text field
    $('#fBody', ov).addEventListener('focusin', async e => { const el = e.target, name = el.dataset.f; $$('.mo-chips', ov).forEach(c => c.remove());
      if (!name || el.tagName !== 'INPUT' || !['text', 'tel', 'email'].includes(el.type) || el.value || id) return;
      let vals = []; try { vals = (await api(`suggest/${key}/${name}`)).values; } catch (x) {}
      if (!vals.length || document.activeElement !== el) return; const box = document.createElement('div'); box.className = 'mo-chips';
      box.innerHTML = '<span class="faint">Suggested:</span>' + vals.slice(0, 4).map(v => `<button type="button" class="mo-chip">${esc(v)}</button>`).join(''); el.insertAdjacentElement('afterend', box);
      box.onmousedown = ev => { const b = ev.target.closest('.mo-chip'); if (!b) return; ev.preventDefault(); el.value = b.textContent; change(name, b.textContent); box.remove(); }; });
    bar.onclick = e => { const a = (e.target.closest('[data-a]') || {}).dataset; if (!a) return;
      if (a.a === 'back') { close(); return openCreate(lastCreateQ); }
      if (a.a === 'undo' && undo.length) { const c = undo.pop(); setVal(c.name, c.old); redo.push(c); }
      if (a.a === 'redo' && redo.length) { const c = redo.pop(); setVal(c.name, c.nw); undo.push(c); }
      if (a.a === 'last' && lastSaved[key]) { Object.entries(lastSaved[key]).forEach(([n, v]) => { if (String(val[n] ?? '') !== String(v)) { undo.push({ name: n, old: val[n] ?? '', nw: v }); setVal(n, v); } }); redo.length = 0; toast('Filled from the last saved one – check and change what is different'); }
      if (a.a === 'clear') { flds.filter(f => !f.readonly).forEach(f => { if ((val[f.name] ?? '') !== '') { undo.push({ name: f.name, old: val[f.name], nw: '' }); setVal(f.name, ''); } }); redo.length = 0; }
      paint(); };
    $('#fBody', ov).addEventListener('click', e => { const b = e.target.closest('[data-add]'); if (!b) return; const f = flds.find(x => x.name === b.dataset.add);
      $$('[data-f]', ov).forEach(el => { val[el.dataset.f] = read(el); });
      openForm(f.add_form, null, { preset: f.add_preset, onSaved: async r => { delete optCache[f.name]; change(f.name, String(r.id)); await loadOptions(f); toast('Added and selected'); } }); });
    $$('.seg button', ov).forEach(b => b.onclick = () => { mode = b.dataset.m; draw(); });
    $('#fmCancel', ov).onclick = close;
    $('#fmSave', ov).onclick = async () => { $$('[data-f]', ov).forEach(el => { val[el.dataset.f] = read(el); });
      const values = {}; flds.filter(f => !f.readonly && (shown(f) || id)).forEach(f => { values[f.name] = val[f.name]; });
      busy(true);
      try { const r = await api(`record/${key}` + (id ? '/' + id : ''), { method: 'POST', body: { values, quick: mode === 'quick' } });
        lastSaved[key] = values; try { localStorage.setItem('mo_last_forms', JSON.stringify(lastSaved)); } catch (x) {}
        close(); toast(r.message, 'ok', r.open); opt.onSaved ? opt.onSaved(r) : go(r.open); }
      catch (e) { if (e.status === 422) { draw(e.errors); $('#fMsg', ov).textContent = e.errors._form ? (e.errors._form[0] || e.errors._form) : Object.keys(e.errors).length + ' field(s) need attention'; if (e.errors._form) toast(e.errors._form[0] || e.errors._form, 'err'); const first = $('.fld.has-err [data-f]', ov); first && first.focus(); } else toast(e.message, 'err'); }
      busy(false); };
    draw();
  }
  MO.openForm = openForm;

  // ---------- start ----------
  (async function start() {
    try { S.boot = await api('boot'); } catch (e) { return; }
    try { const h = location.hash; if (h && h.startsWith('#/')) S.route = h; } catch (e) {}
    drawTop(); render();
    window.addEventListener('popstate', () => { let h = ''; try { h = location.hash; } catch (e) {} if (h && h !== S.route) go(h, false); });
  })();
})();
