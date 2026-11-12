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
      if (r === '#/buying') return await pageBuying();
      if ((mt = r.match(/^#\/research\/(\d+)/))) return await pageResearch(+mt[1]);
      if ((mt = r.match(/^#\/cycle\/(\d+)/))) return await pageCycle(+mt[1]);
      if ((mt = r.match(/^#\/po\/(\d+)/))) return await pagePo(+mt[1]);
      if (r === '#/fx') return await pageFx();
      if ((mt = r.match(/^#\/pick\/(\d+)/))) return await pagePick(+mt[1]);
      if (r.startsWith('#/orders')) return await pageOrders();
      if (r.startsWith('#/work')) return await pageWork((r.match(/tab=(\w+)/) || [])[1]);
      if (r.startsWith('#/confirm')) return await pageConfirm();
      if (r === '#/returns') return await pageReturns();
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
    const WS = { src_research: '#/research/', t_src_cycles: '#/cycle/', t_src_po: '#/po/' };
    $$('#main tr[data-id]').forEach(tr => tr.onclick = () => go(WS[d.table] ? WS[d.table] + tr.dataset.id : `#/rec/${key}/${tr.dataset.id}`));
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

  // ---------- buying: dashboard, research workspace, cycle workspace, PO page ----------
  let BPICK = null;
  const bpick = async () => BPICK = BPICK || await api('buying/pick');
  const stepBar = steps => `<div style="display:flex;gap:6px;flex-wrap:wrap">${steps.map((s, i) => `<span class="chip ${s.state}" title="${s.state === 'done' ? 'Done' : s.state === 'prog' ? 'In progress' : 'Not started'}">${i + 1}. ${esc(s.name)}</span>`).join('')}</div>`;
  const stChip = s => `<span class="chip ${({ DRAFT: 'pend', CONFIRMED: 'prog', SHIPPED: 'prog', RECEIVED: 'done', PAID: 'done', PART_PAID: 'warn', DUE: 'warn', POSTED: 'done', ACCEPTED: 'done', QUOTED: 'pend', IN_TRANSIT: 'prog', ARRIVED: 'done', FINAL: 'done', PROVISIONAL: 'warn', GO: 'done', NO_GO: 'err', PARKED: 'lock', IDEA: 'pend', RESEARCHING: 'prog', SHORTLIST: 'accent' })[s] || 'pend'}">${esc((s || '').replace(/_/g, ' '))}</span>`;
  const act = async (path, body, after, okMsg) => { busy(true); try { const r = await api(path, { method: 'POST', body: body || {} }); toast(okMsg || r.message || 'Done'); after && after(r); } catch (e) { toast(e.message, 'err'); } busy(false); };

  async function pageBuying() {
    busy(true); let d; try { d = await api('buying'); } finally { busy(false); }
    const k = d.kpi, card = (lbl, val, foot, tone, goto) => `<div class="card kpi ${tone || ''}" ${goto ? `data-go="${goto}" style="cursor:pointer"` : ''}><div class="bar"></div><div class="lbl">${lbl}</div><div class="val">${val}</div><div class="foot">${foot || ''}</div></div>`;
    $('#main').innerHTML = `<div class="page-head"><div><h1>Buying</h1><div class="sub">From an idea to stock on the shelf. Each open cycle shows where it stands.</div></div>
      <div class="actions">${d.rights.buy ? `<button class="btn" id="bRes">${ic('plus')} New research</button><button class="btn" id="bPo">${ic('plus')} Direct PO</button>` : ''}</div></div>
      <div class="kpis">${card('Research open', k.research_open, 'ideas not decided yet', '', '#/list/src_research')}${card('Cycles open', k.cycles_open, '', '', '#/list/t_src_cycles')}${card('POs to confirm', k.po_draft, 'drafts', k.po_draft ? 'warn' : 'done', '#/list/t_src_po')}
        ${card('Payments due', k.pay_due_count, k.pay_due_base !== undefined ? money(k.pay_due_base) + ' open on confirmed POs' : '', k.pay_due_count ? 'err' : 'done')}${card('On the way', k.in_transit, k.overdue ? k.overdue + ' overdue' : 'none overdue', k.overdue ? 'err' : '')}
        ${card('GRN to post', k.grn_to_post, 'received but not in stock yet', k.grn_to_post ? 'warn' : 'done')}${card('Lots with provisional cost', k.lots_provisional, 'run the landed cost', k.lots_provisional ? 'warn' : 'done')}
        ${k.supplier_advances !== undefined ? card('Paid ahead to suppliers', money(k.supplier_advances), 'goods not received yet') + card('Owed to suppliers', money(k.supplier_payables), 'goods received, not paid') : ''}</div>
      <div class="card" style="margin-top:14px"><div class="card-h"><h3>Open cycles</h3></div><div class="tbl-wrap"><table class="t"><thead><tr><th>Cycle</th><th>Product</th><th>Started</th><th>Where it stands</th></tr></thead><tbody>
      ${d.cycles.map(c => `<tr class="click" data-id="${c.id}"><td>${esc(c.code)}</td><td>${esc(c.name || '')}</td><td>${esc(c.date || '')}</td><td>${stepBar(c.steps)}</td></tr>`).join('')}</tbody></table>${d.cycles.length ? '' : `<div class="empty">${ic('search')}<div>No open cycle. Start with a research idea and press GO, or make a Direct PO.</div></div>`}</div></div>`;
    $$('#main [data-go]').forEach(x => x.onclick = () => go(x.dataset.go));
    $$('#main tr[data-id]').forEach(tr => tr.onclick = () => go('#/cycle/' + tr.dataset.id));
    if (d.rights.buy) { $('#bRes').onclick = () => openForm('src_research', null, { onSaved: r => go('#/research/' + r.id) }); $('#bPo').onclick = () => directPo(); }
  }
  async function directPo() {
    const p = await bpick();
    miniForm('Direct PO', 'A purchase order without research: for a repeat order or a known supplier.', [{ name: 'supplier', label: 'Supplier', required: true, options: opt(p.suppliers, x => [x.id, x.name]) }, { name: 'product', label: 'Product', required: true, options: opt(p.products, x => [x.id, x.name]) },
      { name: 'qty', label: 'Units', type: 'number', required: true }, { name: 'unit_price', label: 'Price per unit', type: 'number', required: true }, { name: 'currency', label: 'Currency', required: true, value: p.base, help: '3 letters, e.g. USD' },
      { name: 'date', label: 'PO date', type: 'date', required: true, value: S.boot.today }, { name: 'deposit_pct', label: 'Deposit %', type: 'number', value: 30 }], async v => { const r = await api('po', { method: 'POST', body: v }); go('#/po/' + r.id); return r; }, 'Make PO');
  }

  async function pageResearch(id) {
    busy(true); let d; try { d = await api('research/' + id); } finally { busy(false); }
    const x = d.research, R = d.rights, locked = x.status === 'GO', m = x.margin, tone = m === null ? '' : m < 0 ? 'err' : m < d.min_margin ? 'warn' : 'done';
    const again = () => pageResearch(id);
    $('#main').innerHTML = `<div class="page-head"><div><div class="small muted"><a href="#/list/src_research">Research board</a> › ${esc(x.code)}</div><h1>${esc(x.title)} ${stChip(x.status)}</h1>${d.cycle ? `<div class="sub">Started cycle <a href="#/cycle/${d.cycle.id}">${esc(d.cycle.cycle_code)}</a></div>` : ''}</div>
      <div class="actions">${!locked && R.buy ? `<button class="btn" data-st="RESEARCHING">Researching</button><button class="btn" data-st="SHORTLIST">Shortlist</button><button class="btn" data-st="PARKED">Park</button><button class="btn" data-st="NO_GO">No go</button>` : ''}${!locked && R.go ? `<button class="btn primary" id="rGo">${ic('play')} GO – start sourcing</button>` : ''}</div></div>
      <div class="kpis"><div class="card kpi ${tone}"><div class="bar"></div><div class="lbl">Estimated margin</div><div class="val">${m === null ? '—' : (+m).toFixed(1) + ' %'}</div><div class="foot">minimum ${d.min_margin} % · (sell − cost − freight − fees − ads) ÷ sell</div></div>
        <div class="card kpi"><div class="bar"></div><div class="lbl">Sell price</div><div class="val">${x.sell === null ? '—' : money(x.sell)}</div><div class="foot">${esc(x.currency)}</div></div><div class="card kpi"><div class="bar"></div><div class="lbl">Unit cost + freight</div><div class="val">${money((+x.cost || 0) + (+x.freight || 0))}</div><div class="foot">cost ${money(x.cost || 0)} · freight ${money(x.freight || 0)}</div></div>
        <div class="card kpi"><div class="bar"></div><div class="lbl">Fees + ads</div><div class="val">${(+x.fee_pct || 0) + (+x.ad_pct || 0)} %</div><div class="foot">fee ${+x.fee_pct || 0} % · ads ${+x.ad_pct || 0} %</div></div></div>
      ${!locked && R.buy ? `<div style="margin:10px 0"><button class="btn sm" id="rNum">${ic('wand')} Change the estimate</button></div>` : ''}
      <div class="card" style="margin-top:8px"><div class="card-h"><h3>Competitor listings · ${d.competitors.length}</h3>${!locked && R.buy ? `<button class="btn sm more" id="rComp">${ic('plus')} Add</button>` : ''}</div><div class="tbl-wrap"><table class="t"><thead><tr><th>Seller</th><th>Link</th><th class="num">Price</th><th class="num">Sold / month</th><th class="num">Reviews</th><th class="num">Rating</th></tr></thead><tbody>
        ${d.competitors.map(c => `<tr><td>${esc(c.seller_name || '')}</td><td><a href="${esc(c.listing_url)}" target="_blank" rel="noopener">open</a></td><td class="num">${c.price === null ? '' : money(c.price)}</td><td class="num">${c.est_monthly_sold ?? ''}</td><td class="num">${c.review_count ?? ''}</td><td class="num">${c.rating ?? ''}</td></tr>`).join('')}</tbody></table>${d.competitors.length ? '' : '<div class="empty">No competitor yet.</div>'}</div></div>
      <div class="card" style="margin-top:12px"><div class="card-h"><h3>Supplier candidates · ${d.suppliers.length}</h3>${!locked && R.buy ? `<button class="btn sm more" id="rSup">${ic('plus')} Add</button>` : ''}</div><div class="tbl-wrap"><table class="t"><thead><tr><th>Supplier</th><th class="num">Unit price</th><th>Currency</th><th class="num">MOQ</th><th class="num">Lead days</th></tr></thead><tbody>
        ${d.suppliers.map(s => `<tr><td>${esc(s.supplier_name_text || 'Supplier #' + s.fk_id_supplier)}${s.source_url ? ` <a href="${esc(s.source_url)}" target="_blank" rel="noopener">link</a>` : ''}</td><td class="num">${s.unit_price === null ? '' : (+s.unit_price).toFixed(2)}</td><td>${esc(s.currency || '')}</td><td class="num">${s.moq ?? ''}</td><td class="num">${s.lead_days ?? ''}</td></tr>`).join('')}</tbody></table>${d.suppliers.length ? '' : '<div class="empty">No supplier candidate yet. Each one becomes a quote when you press GO.</div>'}</div></div>`;
    $$('#main [data-st]').forEach(b => b.onclick = () => ['PARKED', 'NO_GO'].includes(b.dataset.st) ? miniForm(b.dataset.st === 'PARKED' ? 'Park this research' : 'No go', 'The reason is kept with the research.', [{ name: 'reason', label: 'Reason', required: true }], async v => { const r = await api(`research/${id}/status`, { method: 'POST', body: { status: b.dataset.st, reason: v.reason } }); again(); return r; }) : act(`research/${id}/status`, { status: b.dataset.st }, again));
    if ($('#rNum')) $('#rNum').onclick = () => miniForm('Estimate', 'The margin is worked out from these numbers.', [{ name: 'sell', label: 'Target sell price', type: 'number', required: true, value: x.sell }, { name: 'cost', label: 'Unit cost', type: 'number', required: true, value: x.cost }, { name: 'freight', label: 'Freight per unit', type: 'number', value: x.freight },
      { name: 'fee_pct', label: 'Marketplace fee %', type: 'number', value: x.fee_pct }, { name: 'ad_pct', label: 'Advertising %', type: 'number', value: x.ad_pct }, { name: 'units', label: 'Units per month (estimate)', type: 'number', value: x.units }], async v => { const r = await api(`research/${id}/numbers`, { method: 'POST', body: v }); again(); return r; });
    if ($('#rComp')) $('#rComp').onclick = () => miniForm('Competitor listing', '', [{ name: 'listing_url', label: 'Link', required: true, type: 'url' }, { name: 'seller_name', label: 'Seller' }, { name: 'price', label: 'Price', type: 'number' }, { name: 'est_monthly_sold', label: 'Sold per month (estimate)', type: 'number' }, { name: 'review_count', label: 'Reviews', type: 'number' }, { name: 'rating', label: 'Rating (0–5)', type: 'number' }],
      async v => { const r = await api(`research/${id}/competitor`, { method: 'POST', body: v }); again(); return r; }, 'Add');
    if ($('#rSup')) $('#rSup').onclick = async () => { const p = await bpick(); miniForm('Supplier candidate', 'Pick a supplier you already have, or type a new name.', [{ name: 'fk_id_supplier', label: 'Existing supplier', options: opt(p.suppliers, s => [s.id, s.name]) }, { name: 'supplier_name_text', label: 'Or a new name' }, { name: 'source_url', label: 'Link', type: 'url' },
      { name: 'unit_price', label: 'Unit price', type: 'number', required: true }, { name: 'currency', label: 'Currency', required: true, value: 'USD', help: '3 letters' }, { name: 'moq', label: 'MOQ', type: 'number' }, { name: 'lead_days', label: 'Lead days', type: 'number' }],
      async v => { const r = await api(`research/${id}/supplier`, { method: 'POST', body: v }); BPICK = null; again(); return r; }, 'Add'); };
    if ($('#rGo')) $('#rGo').onclick = () => miniForm('GO – start sourcing', `In one step the tool makes the draft product, the sourcing cycle and ${d.suppliers.length} quote(s) from the supplier candidates.` + (m !== null && m < d.min_margin ? ` The margin (${(+m).toFixed(1)} %) is under the minimum: write why it is still a GO.` : ''),
      [{ name: 'qty', label: 'Units to buy', type: 'number', required: true, value: x.units || '' }, { name: 'reason', label: 'Reason' + (m !== null && m < d.min_margin || x.risk_flags ? ' (needed)' : ' (optional)') }], async v => { const r = await api(`research/${id}/go`, { method: 'POST', body: v }); go('#/cycle/' + r.cycle); return r; }, 'GO');
  }

  async function pageCycle(id) {
    busy(true); let d; try { d = await api('cycle/' + id); } finally { busy(false); }
    const c = d.cycle, R = d.rights, again = () => pageCycle(id), poOf = pid => d.pos.find(p => p.id === pid) || {};
    const sec = (title, n, body, btn) => `<div class="card" style="margin-top:12px"><div class="card-h"><h3>${title}${n !== null ? ' · ' + n : ''}</h3>${btn || ''}</div>${body}</div>`;
    const tbl = (heads, rows, empty) => `<div class="tbl-wrap"><table class="t"><thead><tr>${heads.map(h => `<th${h.startsWith('#') ? ' class="num"' : ''}>${h.replace(/^#/, '')}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table>${rows.length ? '' : `<div class="empty">${empty}</div>`}</div>`;
    $('#main').innerHTML = `<div class="page-head"><div><div class="small muted"><a href="#/buying">Buying</a> › ${esc(c.code)}</div><h1>${esc(c.name || c.code)}</h1><div class="sub">${d.research ? `From research <a href="#/research/${d.research.id}">${esc(d.research.research_code)}</a> · estimated margin ${d.research.est_margin_pct ?? '—'} % · ` : ''}${d.products.map(p => esc(p.sku || p.name)).join(', ')} · target ${c.target_qty} units</div></div></div>
      <div class="card"><div class="card-b">${stepBar(d.steps)}</div></div>
      ${sec('1. Quotes', d.rfqs.length, tbl(['Quote', 'Supplier', '#Qty', '#MOQ', '#Price', 'Currency', '#Lead days', 'Status', ''], d.rfqs.map(q => `<tr><td>${esc(q.rfq_code)}</td><td>${esc(q.supplier || '')}</td><td class="num">${+q.qty}</td><td class="num">${q.moq === null ? '' : +q.moq}</td><td class="num">${q.price === undefined || q.price === null ? '' : (+q.price).toFixed(2)}</td><td>${esc(q.currency || '')}</td><td class="num">${q.lead_days === null ? '' : +q.lead_days}</td><td>${stChip(q.status)}</td>
        <td style="white-space:nowrap">${R.buy && q.status !== 'ACCEPTED' ? `<button class="btn sm" data-acc="${q.id}">Accept</button>` : ''}${R.buy && q.status === 'ACCEPTED' && !d.pos.length ? `<button class="btn sm primary" data-mkpo="${q.id}" data-qty="${+q.qty}">Make PO</button>` : ''}</td></tr>`), 'No quote on this cycle.'))}
      ${sec('2. Purchase orders', d.pos.length, tbl(['PO', 'Supplier', 'Date', '#Total', 'Currency', 'Status', ''], d.pos.map(p => `<tr><td><a href="#/po/${p.id}">${esc(p.po_number)}</a></td><td>${esc(p.supplier || '')}</td><td>${esc(p.po_date)}</td><td class="num">${p.po_total === undefined ? '' : money(p.po_total)}</td><td>${esc(p.currency || '')}</td><td>${stChip(p.status)}${+p.f_paid_in_full ? ' <span class="chip done plain">paid in full</span>' : ''}</td>
        <td style="white-space:nowrap">${R.buy && p.status === 'DRAFT' ? `<button class="btn sm primary" data-conf="${p.id}">Confirm</button>` : ''}${R.buy && ['CONFIRMED', 'SHIPPED'].includes(p.status) ? `<button class="btn sm" data-ship="${p.id}">Add shipment</button>` : ''}${R.cost && ['SHIPPED', 'RECEIVED'].includes(p.status) ? ` <button class="btn sm" data-lc="${p.id}">Landed cost</button>` : ''}</td></tr>`), 'Accept a quote, then make the PO from it.'))}
      ${R.money ? sec('3. Payments', d.terms.length, tbl(['PO', 'What', '#Planned', '#Paid', '#Open', 'Due', 'Status', ''], d.terms.map(t => `<tr><td>${esc(poOf(t.fk_id_po).po_number || '')}</td><td>${esc(t.milestone)}</td><td class="num">${money(t.planned_amount)}</td><td class="num">${money(t.paid_amount)}</td><td class="num">${money(t.remaining_amount)}</td><td style="${t.status !== 'PAID' && t.due_date <= S.boot.today ? 'color:var(--err);font-weight:600' : ''}">${esc(t.due_date || '')}</td><td>${stChip(t.status)}</td>
        <td>${R.pay && t.status !== 'PAID' ? `<button class="btn sm primary" data-pay="${t.id}" data-open="${+t.remaining_amount}" data-cur="${esc(poOf(t.fk_id_po).currency || '')}">Pay</button>` : ''}</td></tr>`), 'The payment schedule is made when the PO is confirmed.')) : ''}
      ${sec('4. Shipments', d.shipments.length, tbl(['Shipment', 'PO', '#Units', 'ETD', 'ETA', 'Arrived', 'Status', ''], d.shipments.map(s => `<tr><td>${esc(s.shipment_code)}</td><td>${esc(poOf(s.fk_id_po).po_number || '')}</td><td class="num">${+s.planned_qty}</td><td>${esc(s.etd || '')}</td><td style="${!s.actual_arrival && s.eta && s.eta < S.boot.today ? 'color:var(--err);font-weight:600' : ''}">${esc(s.eta || '')}</td><td>${esc(s.actual_arrival || '')}</td><td>${stChip(s.status)}</td>
        <td>${R.receive && !d.grns.some(g => g.fk_id_shipment === s.id) ? `<button class="btn sm primary" data-rcv="${s.id}">Receive</button>` : ''}</td></tr>`), 'Add a shipment when the supplier ships.'))}
      ${sec('5. Receiving (GRN)', d.grns.length, tbl(['GRN', 'Date', '#Received', '#Damaged', '#Missing', 'Status', ''], d.grns.map(g => `<tr><td>${esc(g.grn_number)}</td><td>${esc(g.grn_date)}</td><td class="num">${+g.units_received}</td><td class="num">${+g.damaged_units || ''}</td><td class="num">${+g.missing_units || ''}</td><td>${stChip(g.posting_status)}</td>
        <td>${R.receive && g.posting_status !== 'POSTED' ? `<button class="btn sm primary" data-post="${g.id}">Post to stock</button>` : ''}</td></tr>`), 'When the goods arrive, press Receive on the shipment.'))}
      ${R.money ? sec('6. Landed cost', d.landed.length, tbl(['Run', 'PO', '#Freight', '#Duty', '#Other', '#Total', '#Per unit', 'Status'], d.landed.map(l => `<tr style="${l.superseded_by ? 'opacity:.5' : ''}"><td>${esc(l.lc_code)} v${l.run_version}</td><td>${esc(poOf(l.fk_id_po).po_number || '')}</td><td class="num">${money(l.freight_cost)}</td><td class="num">${money(l.customs_duty)}</td><td class="num">${money(l.other_cost)}</td><td class="num">${money(l.total_cost)}</td><td class="num">${(+l.landed_cost_per_unit).toFixed(4)}</td><td>${stChip(l.landed_status)}${l.superseded_by ? ' <span class="small muted">replaced</span>' : ''}</td></tr>`), 'After receiving, add freight and duty so the lots carry their full cost.')) : ''}`;
    buyButtons(d.pos, again);
  }
  // one place for the step buttons, used by the cycle workspace and the PO page
  function buyButtons(pos, again) {
    $$('#main [data-acc]').forEach(b => b.onclick = () => act(`rfq/${b.dataset.acc}/accept`, {}, again));
    $$('#main [data-mkpo]').forEach(b => b.onclick = () => miniForm('Make PO from this quote', 'Supplier, currency and price come from the accepted quote.', [{ name: 'qty', label: 'Units', type: 'number', required: true, value: b.dataset.qty }, { name: 'date', label: 'PO date', type: 'date', required: true, value: S.boot.today }, { name: 'deposit_pct', label: 'Deposit %', type: 'number', value: 30 }],
      async v => { const r = await api('po', { method: 'POST', body: { ...v, rfq: +b.dataset.mkpo } }); again(); return r; }, 'Make PO'));
    $$('#main [data-conf]').forEach(b => b.onclick = () => act(`po/${b.dataset.conf}/confirm`, {}, again));
    $$('#main [data-pay]').forEach(b => b.onclick = async () => { const p = await bpick(); if (!p.banks.length) return toast('Add a bank account first (Create › Bank account).', 'err');
      miniForm('Pay the supplier', `Open on this term: ${money(b.dataset.open)} ${b.dataset.cur}. The payment is posted to the ledger at the exchange rate of its date.`, [{ name: 'amount', label: 'Amount (' + b.dataset.cur + ')', type: 'number', required: true, value: b.dataset.open }, { name: 'date', label: 'Paid on', type: 'date', required: true, value: S.boot.today },
        { name: 'bank', label: 'From bank account', required: true, options: opt(p.banks, x => [x.id, x.name]) }, { name: 'txn_ref', label: 'Bank reference' }], async v => { const r = await api('pay', { method: 'POST', body: { ...v, term: +b.dataset.pay } }); again(); return r; }, 'Pay'); });
    $$('#main [data-ship]').forEach(b => b.onclick = () => miniForm('Add shipment', 'Everything still to ship on this PO goes on the shipment.', [{ name: 'etd', label: 'Leaves (ETD)', type: 'date' }, { name: 'eta', label: 'Arrives (ETA)', type: 'date', required: true }, { name: 'mode', label: 'Mode', options: [['', 'Choose…'], ['SEA', 'Sea'], ['AIR', 'Air'], ['RAIL', 'Rail'], ['ROAD', 'Road'], ['COURIER', 'Courier']] }],
      async v => { const r = await api('shipment', { method: 'POST', body: { ...v, po: +b.dataset.ship } }); again(); return r; }));
    $$('#main [data-rcv]').forEach(b => b.onclick = () => receiveForm(+b.dataset.rcv, again));
    $$('#main [data-post]').forEach(b => b.onclick = () => act(`grn/${b.dataset.post}/post`, {}, again));
    $$('#main [data-lc]').forEach(b => b.onclick = () => miniForm('Landed cost', 'Freight and other costs are shared by quantity, duty by value. Units already sold get their cost corrected.', [{ name: 'freight', label: 'Freight (' + S.boot.base_currency + ')', type: 'number', value: 0 }, { name: 'duty', label: 'Customs duty', type: 'number', value: 0 }, { name: 'other', label: 'Other costs', type: 'number', value: 0 },
      { name: 'final', label: 'This is final (cannot be changed after)', type: 'check' }], async v => { const r = await api(`po/${b.dataset.lc}/landed`, { method: 'POST', body: v }); again(); return r; }));
  }
  async function receiveForm(shipmentId, again) {
    busy(true); let L, p; try { L = (await api(`shipment/${shipmentId}/lines`)).lines; p = await bpick(); } finally { busy(false); }
    const { ov, close } = modal('Receive goods', 'Count what arrived. The GRN is saved as a draft; stock moves only when you post it.',
      `<div class="form"><div class="fld is-req"><label for="gLoc"><span>Put into location</span> <span class="req">*</span></label><select id="gLoc">${opt(p.locations, x => [x.id, x.name]).map(([v, l]) => `<option value="${v}">${esc(l)}</option>`).join('')}</select></div><div class="fld is-req"><label for="gDate"><span>Received on</span> <span class="req">*</span></label><input id="gDate" type="date" value="${S.boot.today}"></div></div>
       <div class="tbl-wrap" style="margin-top:10px"><table class="t"><thead><tr><th>SKU</th><th>Product</th><th class="num">Expected</th><th class="num">Received</th><th class="num">Of these damaged</th></tr></thead><tbody>${L.map(l => `<tr data-l="${l.po_line}"><td>${esc(l.sku || '')}</td><td>${esc(l.name || '')}</td><td class="num">${+l.qty}</td><td class="num"><input class="q" data-r type="number" min="0" style="min-width:80px;width:100px;text-align:right" value="${+l.qty}"></td><td class="num"><input class="q" data-d type="number" min="0" style="min-width:80px;width:100px;text-align:right" value="0"></td></tr>`).join('')}</tbody></table></div>`,
      `<span class="left small muted" id="gMsg"></span><button class="btn" id="gCancel">Cancel</button><button class="btn primary" id="gSave">Save GRN</button>`);
    $('#gCancel', ov).onclick = close;
    $('#gSave', ov).onclick = async () => { const lines = $$('tr[data-l]', ov).map(tr => ({ po_line: +tr.dataset.l, received: +$('[data-r]', tr).value || 0, damaged: +$('[data-d]', tr).value || 0 }));
      busy(true); try { const r = await api('grn', { method: 'POST', body: { shipment: shipmentId, location: +$('#gLoc', ov).value, date: $('#gDate', ov).value, lines } }); close(); toast(r.message); again(); } catch (e) { $('#gMsg', ov).textContent = e.message; } busy(false); };
  }
  async function pagePo(id) {
    busy(true); let d; try { d = await api('po/' + id); } finally { busy(false); }
    const p = d.po, R = d.rights, again = () => pagePo(id), M = R.money;
    $('#main').innerHTML = `<div class="page-head"><div><div class="small muted"><a href="#/list/t_src_po">Purchase orders</a>${p.fk_id_cycle ? ` · <a href="#/cycle/${p.fk_id_cycle}">cycle</a>` : ''}</div><h1>${esc(p.po_number)} ${stChip(p.status)}</h1><div class="sub">${esc(p.supplier || '')} · ${esc(p.po_date)}${M ? ` · ${money(p.po_total)} ${esc(p.currency || '')}${p.exchange_rate ? ` · rate ${(+p.exchange_rate).toFixed(4)} = ${money(p.po_total * p.exchange_rate)} ${esc(d.base)}` : ''}` : ''}</div></div>
      <div class="actions">${R.buy && p.status === 'DRAFT' ? `<button class="btn primary" data-conf="${p.id}">Confirm PO</button>` : ''}${R.buy && ['CONFIRMED', 'SHIPPED'].includes(p.status) ? `<button class="btn" data-ship="${p.id}">Add shipment</button>` : ''}${R.cost && ['SHIPPED', 'RECEIVED'].includes(p.status) ? `<button class="btn" data-lc="${p.id}">Landed cost</button>` : ''}${R.buy && p.status !== 'DRAFT' ? `<button class="btn" id="poRep">Repeat this order</button>` : ''}</div></div>
      <div class="card"><div class="card-h"><h3>Lines</h3></div><div class="tbl-wrap"><table class="t"><thead><tr><th>SKU</th><th>Product</th><th class="num">Ordered</th>${M ? '<th class="num">Price</th><th class="num">Total</th>' : ''}<th class="num">Shipped</th><th class="num">Received</th></tr></thead><tbody>${d.lines.map(l => `<tr><td><a href="#/stock/product/${l.product_id}">${esc(l.sku || '')}</a></td><td>${esc(l.name || '')}</td><td class="num">${+l.qty}</td>${M ? `<td class="num">${(+l.unit_price).toFixed(4)}</td><td class="num">${money(l.line_total)}</td>` : ''}<td class="num">${+l.shipped}</td><td class="num">${+l.received}</td></tr>`).join('')}</tbody></table></div></div>
      ${M ? `<div class="card" style="margin-top:12px"><div class="card-h"><h3>Payments</h3></div><div class="tbl-wrap"><table class="t"><thead><tr><th>What</th><th class="num">Planned</th><th class="num">Paid</th><th class="num">Open</th><th>Due</th><th>Status</th><th></th></tr></thead><tbody>${d.terms.map(t => `<tr><td>${esc(t.milestone)} (${+t.pct} %)</td><td class="num">${money(t.planned_amount)}</td><td class="num">${money(t.paid_amount)}</td><td class="num">${money(t.remaining_amount)}</td><td>${esc(t.due_date || '')}</td><td>${stChip(t.status)}</td><td>${R.pay && t.status !== 'PAID' ? `<button class="btn sm primary" data-pay="${t.id}" data-open="${+t.remaining_amount}" data-cur="${esc(p.currency || '')}">Pay</button>` : ''}</td></tr>`).join('')}</tbody></table>${d.terms.length ? '' : '<div class="empty">Confirm the PO to make the payment schedule.</div>'}</div>
        ${d.payments.length ? `<div class="tbl-wrap"><table class="t"><thead><tr><th>Payment</th><th>Date</th><th class="num">Amount</th><th class="num">Rate</th><th class="num">${esc(d.base)}</th></tr></thead><tbody>${d.payments.map(x => `<tr><td>${esc(x.payment_code)}</td><td>${esc(x.payment_date)}</td><td class="num">${money(x.payment_amount)}</td><td class="num">${(+x.exchange_rate).toFixed(4)}</td><td class="num">${money(x.base_amount)}</td></tr>`).join('')}</tbody></table></div>` : ''}</div>` : ''}
      <div class="card" style="margin-top:12px"><div class="card-h"><h3>Shipments and receiving</h3></div><div class="tbl-wrap"><table class="t"><thead><tr><th>Shipment</th><th class="num">Units</th><th>ETA</th><th>Arrived</th><th>GRN</th><th></th></tr></thead><tbody>${d.shipments.map(s => { const g = d.grns.find(x => x.fk_id_shipment === s.id); return `<tr><td>${esc(s.shipment_code)}</td><td class="num">${+s.planned_qty}</td><td>${esc(s.eta || '')}</td><td>${esc(s.actual_arrival || '')}</td><td>${g ? `${esc(g.grn_number)} ${stChip(g.posting_status)} · ${+g.units_received} received${+g.damaged_units ? ', ' + +g.damaged_units + ' damaged' : ''}${+g.missing_units ? ', ' + +g.missing_units + ' missing' : ''}` : '<span class="faint">not received</span>'}</td>
        <td>${R.receive && !g ? `<button class="btn sm primary" data-rcv="${s.id}">Receive</button>` : ''}${R.receive && g && g.posting_status !== 'POSTED' ? `<button class="btn sm primary" data-post="${g.id}">Post to stock</button>` : ''}</td></tr>`; }).join('')}</tbody></table>${d.shipments.length ? '' : '<div class="empty">No shipment yet.</div>'}</div></div>
      <div class="card" style="margin-top:12px"><div class="card-h"><h3>Lots made from this PO</h3></div><div class="tbl-wrap"><table class="t"><thead><tr><th>Lot</th><th>SKU</th><th class="num">Units</th><th class="num">Left</th>${M ? '<th class="num">Unit cost</th>' : ''}<th>Cost</th></tr></thead><tbody>${d.lots.map(l => `<tr><td>${esc(l.lot_code)}</td><td>${esc(l.sku)}</td><td class="num">${l.qty}</td><td class="num">${l.qty_left}</td>${M ? `<td class="num">${(+l.unit_cost_gbp).toFixed(4)}</td>` : ''}<td>${stChip(l.landed_status)}</td></tr>`).join('')}</tbody></table>${d.lots.length ? '' : '<div class="empty">Lots appear when the GRN is posted.</div>'}</div></div>`;
    buyButtons([p], again);
    if ($('#poRep')) $('#poRep').onclick = () => miniForm('Repeat this order', 'A new draft PO for the same supplier at the last price.', [{ name: 'qty', label: 'Units', type: 'number', required: true }, { name: 'date', label: 'PO date', type: 'date', required: true, value: S.boot.today }], async v => { const r = await api('po', { method: 'POST', body: { ...v, repeat_from: id } }); go('#/po/' + r.id); return r; }, 'Make PO');
  }
  async function pageFx() {
    busy(true); let d; try { d = await api('fx'); } finally { busy(false); }
    $('#main').innerHTML = `<div class="page-head"><div><h1>Exchange rates</h1><div class="sub">How many ${esc(d.base)} one unit of a currency is worth, by date. A payment or PO uses the latest rate on or before its date.</div></div><div class="actions"><button class="btn primary" id="fxNew">${ic('plus')} Add rate</button></div></div>
      <div class="card"><div class="tbl-wrap"><table class="t"><thead><tr><th>Date</th><th>Currency</th><th class="num">1 unit = ${esc(d.base)}</th><th>Source</th></tr></thead><tbody>${d.rates.map(r => `<tr><td>${esc(r.rate_date)}</td><td>${esc(r.currency)}</td><td class="num">${(+r.rate_to_gbp).toFixed(6)}</td><td>${esc(r.source || '')}</td></tr>`).join('')}</tbody></table>${d.rates.length ? '' : '<div class="empty">No rate yet. Add one before confirming a PO in another currency.</div>'}</div></div>`;
    $('#fxNew').onclick = () => miniForm('Exchange rate', '', [{ name: 'currency', label: 'Currency', required: true, help: '3 letters, e.g. USD' }, { name: 'date', label: 'Date', type: 'date', required: true, value: S.boot.today }, { name: 'rate', label: '1 unit = ' + d.base, type: 'number', required: true }], async v => { const r = await api('fx', { method: 'POST', body: v }); pageFx(); return r; });
  }

  // ---------- orders: board, pick, pack, handover, month confirm, returns ----------
  const whChip = s => `<span class="chip ${({ RESERVED: 'accent', SHORT: 'err', PICKING: 'prog', PICKED: 'prog', PACKED: 'warn', SHIPPED: 'done', CONFIRMED: 'done', RELEASED: 'lock' })[s] || 'pend'}">${esc(({ RESERVED: 'To pick', SHORT: 'Short', PICKING: 'On a pick list', PICKED: 'Picked', PACKED: 'Packed', SHIPPED: 'Shipped' })[s] || s || 'New')}</span>`;
  const opId = () => (crypto.randomUUID ? crypto.randomUUID() : Date.now() + '-' + Math.random().toString(16).slice(2)).slice(0, 36);
  function orderEntry(d, done) {
    miniForm('New order', 'The SKU can be your product SKU or the shop\'s own listing SKU. Stock is reserved at once.', [{ name: 'branch', label: 'Shop', required: true, options: opt(d.shops, x => [x.id, x.name]), value: d.shops.length === 1 ? d.shops[0].id : '' }, { name: 'order_no', label: 'Order number', required: true },
      { name: 'd_order', label: 'Order date', type: 'date', required: true, value: S.boot.today }, { name: 'sku', label: 'SKU', required: true }, { name: 'qty', label: 'Units', type: 'number', required: true, value: 1 }, { name: 'sale_rate', label: 'Price per unit', type: 'number', required: true },
      { name: 'cust_name', label: 'Customer name' }, { name: 'ship_to_address', label: 'Address' }, { name: 'ship_to_city', label: 'City' }, { name: 'ship_to_postcode', label: 'Postcode' }], async v => { const r = await api('orders', { method: 'POST', body: v }); done && done(); return r; }, 'Save order');
  }
  function packForm(d, row, done) {
    miniForm('Pack order ' + row.order_no, 'Every line of the order goes into one parcel. It is ready for the courier at once.', [{ name: 'carrier', label: 'Carrier', options: opt(d.carriers, x => [x.id, x.name]), help: 'Empty = the shop\'s usual carrier.' }, { name: 'box', label: 'Box', options: opt(d.boxes, x => [x.id, x.name]) },
      { name: 'weight_g', label: 'Weight (grams)', type: 'number' }, { name: 'tracking_no', label: 'Tracking number', help: 'Scan or type the number on the shipping label.' }], async v => { const r = await api('pack', { method: 'POST', body: { ...v, order_no: row.order_no, branch: row.fk_id_branch } }); done && done(); return r; }, 'Pack');
  }
  async function pageOrders() {
    busy(true); let d; try { d = await api('orders'); } finally { busy(false); }
    const c = d.counts, R = d.rights, again = () => pageOrders();
    const kpi = (l, v, tone, foot) => `<div class="card kpi ${tone || ''}"><div class="bar"></div><div class="lbl">${l}</div><div class="val">${v}</div><div class="foot">${foot || ''}</div></div>`;
    const packable = new Set(); const byOrder = {}; d.rows.forEach(r => { (byOrder[r.fk_id_branch + '|' + r.order_no] = byOrder[r.fk_id_branch + '|' + r.order_no] || []).push(r); });
    Object.entries(byOrder).forEach(([k, ls]) => { if (ls.every(l => l.wh_status === 'PICKED')) packable.add(k); });
    $('#main').innerHTML = `<div class="page-head"><div><h1>Orders to dispatch</h1><div class="sub">Reserve → pick → pack → hand over to the courier. The month's cost is booked at Month Confirm.</div></div>
      <div class="actions">${R.enter ? `<button class="btn" id="oNew">${ic('plus')} New order</button>` : ''}${R.warehouse ? `<button class="btn primary" id="oPick" ${c.to_pick ? '' : 'disabled'}>Make pick list${c.to_pick ? ' · ' + c.to_pick : ''}</button>` : ''}<a class="btn" href="#/work">Work screen</a></div></div>
      <div class="kpis">${kpi('To pick', c.to_pick, c.to_pick ? 'warn' : 'done')}${kpi('Short', c.short, c.short ? 'err' : 'done', 'not enough stock')}${kpi('On pick lists', c.picking)}${kpi('Picked, to pack', c.picked, c.picked ? 'warn' : '')}${kpi('Ready for courier', d.ready.length, d.ready.length ? 'prog' : '')}</div>
      ${d.pick_lists.length ? `<div class="card" style="margin-top:12px"><div class="card-h"><h3>Open pick lists</h3></div><div class="card-b">${d.pick_lists.map(p => `<a class="btn" href="#/pick/${p.id}" style="margin:0 8px 6px 0">${esc(p.task_code)} · ${p.open_lines} line(s) left</a>`).join('')}</div></div>` : ''}
      ${d.ready.length || d.handovers.length ? `<div class="card" style="margin-top:12px"><div class="card-h"><h3>Courier handover</h3></div><div class="card-b">${[...new Map(d.ready.map(p => [p.fk_id_carrier, p.carrier])).entries()].map(([id, n]) => { const ses = d.handovers.find(h => h.carrier === n); return `<div style="padding:4px 0">${esc(n || 'No carrier')} · ${d.ready.filter(p => p.fk_id_carrier === id).length} ready ${R.warehouse && id ? `<a class="btn sm primary" href="#/work?tab=handover" data-ho="${id}" style="margin-left:8px">${ses ? 'Continue handover' : 'Start handover'}</a>` : ''}</div>`; }).join('')}</div></div>` : ''}
      <div class="card" style="margin-top:12px"><div class="tbl-wrap"><table class="t"><thead><tr><th>Order</th><th>Shop</th><th>Date</th><th>SKU</th><th class="num">Units</th><th>To</th><th>Status</th><th></th></tr></thead><tbody>
      ${d.rows.map(r => `<tr><td>${esc(r.order_no)}</td><td>${esc(r.shop || '')}</td><td>${esc(r.d_order)}</td><td>${esc(r.sku)}</td><td class="num">${r.qty}${+r.qty_short ? ` <span class="small" style="color:var(--err)">(${r.qty_short} short)</span>` : ''}</td><td>${esc(r.ship_to_name || '')}</td><td>${whChip(r.wh_status)}</td>
        <td style="white-space:nowrap">${R.warehouse && packable.has(r.fk_id_branch + '|' + r.order_no) ? `<button class="btn sm primary" data-pack="${r.id}">Pack</button> ` : ''}${R.enter && ['RESERVED', 'SHORT', 'PICKING', null, ''].includes(r.wh_status) ? `<button class="btn sm" data-cancel="${r.id}">Cancel</button>` : ''}</td></tr>`).join('')}</tbody></table>${d.rows.length ? '' : `<div class="empty">${ic('check')}<div>No open orders.</div></div>`}</div></div>`;
    if ($('#oNew')) $('#oNew').onclick = () => orderEntry(d, again);
    if ($('#oPick')) $('#oPick').onclick = () => act('pick-lists', {}, r => go('#/pick/' + r.id));
    $$('#main [data-pack]').forEach(b => b.onclick = () => packForm(d, d.rows.find(r => r.id == b.dataset.pack), again));
    $$('#main [data-cancel]').forEach(b => b.onclick = () => miniForm('Cancel this order line', 'Its reserved stock becomes free again.', [{ name: 'reason', label: 'Reason', required: true }], async v => { const r = await api(`orders/${b.dataset.cancel}/cancel`, { method: 'POST', body: v }); again(); return r; }, 'Cancel line'));
    $$('#main [data-ho]').forEach(b => b.addEventListener('click', async e => { e.preventDefault(); e.stopPropagation(); try { await api('handover', { method: 'POST', body: { carrier: +b.dataset.ho } }); go('#/work?tab=handover'); } catch (x) { toast(x.message, 'err'); } }));
  }
  async function pagePick(id) {
    busy(true); let d; try { d = await api('pick-lists/' + id); } finally { busy(false); }
    const next = d.lines.find(l => l.line_status === 'OPEN'), W = d.rights.warehouse, again = () => pagePick(id);
    $('#main').innerHTML = `<div class="page-head"><div><div class="small muted"><a href="#/orders">Orders</a> ›</div><h1>Pick list ${esc(d.task.task_code)}</h1><div class="sub">${d.lines.filter(l => l.line_status !== 'OPEN').length} of ${d.lines.length} lines done · bins in walking order</div></div></div>
      ${next ? `<div class="card" style="border-color:var(--accent)"><div class="card-b" style="text-align:center;padding:22px 14px"><div class="small muted">Next · go to bin</div><div style="font-size:44px;font-weight:700;letter-spacing:.02em;line-height:1.1">${esc(next.bin || '—')}</div><div style="font-size:18px;margin-top:8px"><b>${esc(next.sku)}</b> · ${esc(next.name)}</div><div style="font-size:30px;font-weight:700;margin:10px 0">× ${next.qty}</div><div class="small muted">order ${esc(next.order_no || '')}</div>
        ${W ? `<div style="display:flex;gap:10px;justify-content:center;margin-top:14px;flex-wrap:wrap"><button class="btn primary" id="pkAll" style="height:48px;padding:0 26px;font-size:16px">Picked ${next.qty}</button><button class="btn" id="pkShort" style="height:48px">Short…</button></div>` : ''}</div></div>` : `<div class="card"><div class="empty">${ic('check')}<div>This pick list is finished. Pack the orders next.</div><a class="btn primary" href="#/orders" style="margin-top:10px">Back to orders</a></div></div>`}
      <div class="card" style="margin-top:12px"><div class="tbl-wrap"><table class="t"><thead><tr><th>#</th><th>Bin</th><th>SKU</th><th>Order</th><th class="num">Units</th><th>Status</th></tr></thead><tbody>${d.lines.map(l => `<tr style="${l.line_status !== 'OPEN' ? 'opacity:.55' : ''}"><td>${l.line_no}</td><td><b>${esc(l.bin || '')}</b></td><td>${esc(l.sku)}</td><td>${esc(l.order_no || '')}</td><td class="num">${l.line_status === 'OPEN' ? l.qty : l.qty_done + ' / ' + l.qty}</td><td><span class="chip ${l.line_status === 'DONE' ? 'done' : l.line_status === 'SHORT' ? 'err' : l.line_status === 'CANCELLED' ? 'lock' : 'pend'}">${esc(l.line_status)}</span></td></tr>`).join('')}</tbody></table></div></div>`;
    if (next && W) { $('#pkAll').onclick = () => act('pick-lines/' + next.id, {}, again, 'Picked ' + next.qty + ' × ' + next.sku);
      $('#pkShort').onclick = () => miniForm('Short pick', `The bin should hold ${next.qty}. Type how many you really found; the rest goes back in the queue and the bin gets a count task.`, [{ name: 'qty', label: 'Units found', type: 'number', required: true, value: 0 }], async v => { const r = await api('pick-lines/' + next.id, { method: 'POST', body: { qty: +v.qty } }); again(); return { message: `Picked ${r.picked}, ${r.short} short` }; }, 'Save'); }
  }
  // one page for the phone: Pick · Pack · Handover · Returns
  async function pageWork(tab) {
    tab = tab || S.workTab || 'pick'; S.workTab = tab;
    busy(true); let d; try { d = await api('orders'); } finally { busy(false); }
    const W = d.rights.warehouse, again = () => pageWork(tab);
    const tabs = [['pick', 'Pick', d.pick_lists.reduce((a, p) => a + +p.open_lines, 0) + d.counts.to_pick], ['pack', 'Pack', d.counts.picked], ['handover', 'Handover', d.ready.length], ['returns', 'Returns', '']];
    const byOrder = {}; d.rows.filter(r => r.wh_status === 'PICKED').forEach(r => { (byOrder[r.fk_id_branch + '|' + r.order_no] = byOrder[r.fk_id_branch + '|' + r.order_no] || []).push(r); });
    let body = '';
    if (tab === 'pick') body = `${d.pick_lists.map(p => `<a class="card kpi" href="#/pick/${p.id}" style="display:block;text-decoration:none;color:inherit;margin-bottom:10px"><div class="lbl">Pick list</div><div class="val">${esc(p.task_code)}</div><div class="foot">${p.open_lines} line(s) left · tap to continue</div></a>`).join('')}
      ${W && d.counts.to_pick ? `<button class="btn primary" id="wMk" style="height:52px;width:100%;font-size:16px;justify-content:center">Make a pick list · ${d.counts.to_pick} order line(s) waiting</button>` : (d.pick_lists.length ? '' : `<div class="card"><div class="empty">${ic('check')}<div>Nothing to pick.</div></div></div>`)}`;
    if (tab === 'pack') body = Object.values(byOrder).map(ls => `<div class="card" style="margin-bottom:10px"><div class="card-b"><div style="font-weight:700;font-size:17px">Order ${esc(ls[0].order_no)}</div><div class="small muted">${esc(ls[0].shop || '')} · ${esc(ls[0].ship_to_name || '')}</div>${ls.map(l => `<div style="padding:4px 0">${l.qty} × <b>${esc(l.sku)}</b></div>`).join('')}${W ? `<button class="btn primary" data-pack="${ls[0].id}" style="height:46px;width:100%;justify-content:center;margin-top:8px">Pack this order</button>` : ''}</div></div>`).join('') || `<div class="card"><div class="empty">Nothing picked and waiting to be packed.</div></div>`;
    if (tab === 'handover') { const ses = d.handovers[0];
      body = ses ? `<div class="card"><div class="card-b" style="text-align:center"><div class="small muted">${esc(ses.session_code)} · ${esc(ses.carrier || '')}</div><div style="font-size:34px;font-weight:700" id="hoCount">${ses.scanned_count} of ${ses.expected_count}</div><div class="small muted">scanned</div>
          <input class="q" id="hoCode" placeholder="Scan the parcel label…" autocomplete="off" style="width:100%;height:50px;font-size:18px;text-align:center;margin-top:12px"><div id="hoRes" style="min-height:54px;font-size:22px;font-weight:700;margin-top:12px"></div>
          <button class="btn primary" id="hoClose" style="height:48px;width:100%;justify-content:center;margin-top:6px">Close handover = ship the scanned parcels</button></div></div>`
        : `<div class="card"><div class="card-b">${[...new Map(d.ready.map(p => [p.fk_id_carrier, p.carrier])).entries()].filter(([id]) => id).map(([id, n]) => `<button class="btn primary" data-ho="${id}" style="height:50px;width:100%;justify-content:center;margin-bottom:8px">Start handover · ${esc(n)} · ${d.ready.filter(p => p.fk_id_carrier === id).length} parcel(s)</button>`).join('') || '<div class="empty">No parcel is ready for a courier.</div>'}</div></div>`; }
    if (tab === 'returns') body = `<div class="card"><div class="card-b"><input class="q" id="rtCode" placeholder="Scan or type the order number or tracking number" autocomplete="off" style="width:100%;height:50px;font-size:16px"><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px"><input class="q" id="rtQty" type="number" min="1" value="1" style="min-width:0;height:44px" aria-label="Units"><select class="q" id="rtCond" style="min-width:0;height:44px"><option value="SELLABLE">Good – back to the shelf</option><option value="DAMAGED">Damaged</option><option value="QUARANTINE">Check later (quarantine)</option><option value="MISSING">Empty parcel / missing</option></select></div>
      ${W ? `<button class="btn primary" id="rtSave" style="height:48px;width:100%;justify-content:center;margin-top:10px">Book the return</button>` : ''}<div id="rtRes" style="margin-top:10px;font-weight:600"></div></div></div>`;
    $('#main').innerHTML = `<div class="page-head"><div><h1>Work screen</h1><div class="sub">Made for the phone: one job at a time.</div></div></div>
      <div class="seg" style="display:flex;margin-bottom:14px">${tabs.map(([k, l, n]) => `<button type="button" class="${k === tab ? 'on' : ''}" data-wt="${k}" style="flex:1;height:44px">${l}${n ? ' · ' + n : ''}</button>`).join('')}</div>${body}`;
    $$('#main [data-wt]').forEach(b => b.onclick = () => pageWork(b.dataset.wt));
    if ($('#wMk')) $('#wMk').onclick = () => act('pick-lists', {}, r => go('#/pick/' + r.id));
    $$('#main [data-pack]').forEach(b => b.onclick = () => packForm(d, d.rows.find(r => r.id == b.dataset.pack), again));
    $$('#main [data-ho]').forEach(b => b.onclick = () => act('handover', { carrier: +b.dataset.ho }, again));
    if ($('#hoCode')) { const ses = d.handovers[0], inp = $('#hoCode'), res = $('#hoRes'); inp.focus();
      const tone = { OK: 'var(--done)', DUPLICATE: 'var(--warn)', WRONG_CARRIER: 'var(--err)', NOT_READY: 'var(--err)', UNKNOWN: 'var(--err)' };
      inp.onkeydown = async e => { if (e.key !== 'Enter' || !inp.value.trim()) return; const code = inp.value.trim(); inp.value = '';
        try { const r = await api(`handover/${ses.id}/scan`, { method: 'POST', body: { code, op_id: opId() } }); res.style.color = tone[r.result] || 'inherit'; res.textContent = (r.result === 'OK' ? '✓ ' : '✕ ') + (r.message || r.result) + ' · ' + code; if (r.scanned !== undefined) $('#hoCount').textContent = `${r.scanned} of ${r.expected}`; }
        catch (x) { res.style.color = 'var(--err)'; res.textContent = x.message; } inp.focus(); };
      $('#hoClose').onclick = () => act(`handover/${ses.id}/close`, {}, again); }
    if ($('#rtSave')) $('#rtSave').onclick = async () => { const code = $('#rtCode').value.trim(); if (!code) return $('#rtCode').focus();
      try { const r = await api('returns', { method: 'POST', body: { code, qty: +$('#rtQty').value || 1, condition: $('#rtCond').value, op_id: opId() } }); $('#rtRes').style.color = r.matched ? 'var(--done)' : 'var(--warn)'; $('#rtRes').textContent = r.message; $('#rtCode').value = ''; $('#rtCode').focus(); }
      catch (x) { $('#rtRes').style.color = 'var(--err)'; $('#rtRes').textContent = x.message; } };
  }
  async function pageConfirm(st = {}) {
    busy(true); let b, p = null; try { b = await api('orders'); const shop = st.branch || (b.shops[0] || {}).id; const month = st.month || b.month; if (shop) p = await api(`confirm?branch=${shop}&month=${month}`); st = { branch: shop, month }; } finally { busy(false); }
    const again = x => pageConfirm({ ...st, ...x });
    $('#main').innerHTML = `<div class="page-head"><div><h1>Sales month confirm</h1><div class="sub">Confirming saves the month for good: the units are taken out of stock and their cost is booked. All of it or nothing.</div></div></div>
      <div class="card"><div class="list-bar"><select class="q" id="cfShop">${b.shops.map(s => `<option value="${s.id}" ${s.id == st.branch ? 'selected' : ''}>${esc(s.name)}</option>`).join('')}</select><input class="q" id="cfMonth" type="month" value="${esc(st.month)}" style="min-width:150px"></div>
      ${!p ? '<div class="empty">Add a shop first.</div>' : p.already_confirmed ? `<div class="empty">${ic('lock')}<div>This month is already confirmed.</div></div>` : p.lines === 0 ? '<div class="empty">There are no sale lines to confirm in this month.</div>' : `<div class="card-b">
        <div class="kpis"><div class="card kpi"><div class="bar"></div><div class="lbl">Lines to confirm</div><div class="val">${p.to_confirm}</div><div class="foot">${p.refunded_kept} refunded kept · ${p.unmatched} not matched</div></div><div class="card kpi"><div class="bar"></div><div class="lbl">Units out of stock</div><div class="val">${p.units_out}</div></div>${p.sales_total !== undefined ? `<div class="card kpi"><div class="bar"></div><div class="lbl">Sales</div><div class="val">${money(p.sales_total)}</div></div>` : ''}
          <div class="card kpi ${p.shortages.length ? 'err' : 'done'}"><div class="bar"></div><div class="lbl">Shortages</div><div class="val">${p.shortages.length}</div></div></div>
        ${p.shortages.length ? `<h3 style="margin:16px 0 6px;color:var(--err)">Not enough stock for these products, so this month cannot be confirmed yet</h3><table class="t"><thead><tr><th>SKU</th><th>Product</th><th class="num">In stock</th><th class="num">Needed</th></tr></thead><tbody>${p.shortages.map(s => `<tr><td>${esc(s.sku)}</td><td>${esc(s.name)}</td><td class="num">${s.in_stock}</td><td class="num">${s.needed}</td></tr>`).join('')}</tbody></table><p class="muted">Add the stock, or correct or cancel the lines, then try again.</p>` : ''}
        ${p.unshipped.length ? `<h3 style="margin:16px 0 6px">${p.unshipped.length} line(s) were not shipped through the tool</h3><table class="t"><thead><tr><th>Order</th><th>SKU</th><th class="num">Units</th></tr></thead><tbody>${p.unshipped.slice(0, 50).map(u => `<tr><td>${esc(u.order_no)}</td><td>${esc(u.sku)}</td><td class="num">${u.qty}</td></tr>`).join('')}</tbody></table><label class="chk" style="display:block;margin:10px 0"><input type="checkbox" id="cfBook"> Book them as shipped now (they were sent outside the tool)</label>` : ''}
        ${p.rights.confirm ? `<button class="btn primary" id="cfGo" ${p.shortages.length ? 'disabled' : ''} style="margin-top:12px">${ic('lock')} Confirm ${esc(st.month)}</button>` : '<p class="muted">Your role can see the preview but cannot confirm.</p>'}</div>`}</div>`;
    $('#cfShop').onchange = e => again({ branch: +e.target.value }); $('#cfMonth').onchange = e => e.target.value && again({ month: e.target.value });
    if ($('#cfGo')) $('#cfGo').onclick = () => act('confirm', { branch: st.branch, month: st.month, book_unshipped: !!($('#cfBook') && $('#cfBook').checked) }, () => again({}));
  }
  async function pageReturns() {
    busy(true); let d; try { d = await api('returns'); } finally { busy(false); }
    $('#main').innerHTML = `<div class="page-head"><div><h1>Returns</h1><div class="sub">Every parcel that came back. Book a new one on the Work screen.</div></div><div class="actions">${d.rights.warehouse ? `<a class="btn primary" href="#/work?tab=returns">${ic('plus')} Book a return</a>` : ''}</div></div>
      <div class="card"><div class="tbl-wrap"><table class="t"><thead><tr><th>Return</th><th>When</th><th>Scanned</th><th>Order</th><th>SKU</th><th class="num">Units</th><th>Condition</th><th>Status</th><th>By</th></tr></thead><tbody>${d.rows.map(r => `<tr><td>${esc(r.intake_code)}</td><td>${esc(r.received_at)}</td><td>${esc(r.scanned_code)}</td><td>${esc(r.order_no || '')}</td><td>${esc(r.sku || '')}</td><td class="num">${r.qty ?? ''}</td><td>${esc((r.grade || '').toLowerCase())}</td><td><span class="chip ${r.status === 'BOOKED' ? 'done' : 'warn'}">${esc(r.status)}</span></td><td>${esc(r.by || '')}</td></tr>`).join('')}</tbody></table>${d.rows.length ? '' : '<div class="empty">No returns yet.</div>'}</div></div>`;
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
