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
      <div class="user" id="btnUser" title="Sign out"><div class="avatar">${esc(ini)}</div><div class="hide-m"><div style="font-weight:600;font-size:13px">${esc(b.user.name)}</div><div class="small muted">${esc(b.user.role)} · sign out</div></div></div>`;
    $('#btnBack svg') && ($('#btnBack svg').style.transform = 'rotate(180deg)');
    $('#btnSide').onclick = () => document.body.classList.toggle('side-open');
    wireSearch();
    $('#brand').onclick = () => go('#/home');
    $('#btnCreate').onclick = () => openCreate();
    $('#btnRefresh').onclick = () => render();
    $('#btnBack').onclick = () => { if (S.hist.length) { S.fwd.push(S.route); go(S.hist.pop(), false); } };
    $('#btnBell').onclick = () => go('#/tasks');
    $('#btnUser').onclick = () => $('#logoutForm').submit();
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
      if ((mt = r.match(/^#\/screen\/([\d.]+)/))) return pageScreen(mt[1]);
      return pageHome();
    } catch (e) { m.innerHTML = `<div class="card"><div class="empty">${ic('alert')}<div>${esc(e.message)}</div></div></div>`; }
  }
  function findItem(pred) { for (const s of S.boot.menu) for (const i of s.items) if (pred(i)) return [s, i]; return [null, null]; }

  function pageHome() {
    const b = S.boot;
    $('#main').innerHTML = `<div class="page-head"><div><h1>Good to see you, ${esc(b.user.name.split(' ')[0])}</h1><div class="sub">${esc((b.companies.find(c => c.id == b.ent) || {}).name || '')} · ${esc(b.today)}</div></div>
      <div class="actions"><button class="btn primary" id="hCreate">${ic('plus')} Create</button></div></div>
      <div class="kpis"><div class="card kpi ${b.tasks_open ? 'warn' : 'done'}" data-go="#/tasks"><div class="bar"></div><div class="lbl">My open tasks</div><div class="val">${b.tasks_open}</div><div class="foot">Tasks opened by rules close themselves when the problem is gone</div></div></div>
      <div class="card" style="margin-top:16px"><div class="card-h"><h3>Menu · in the order of the work</h3></div><div class="card-b"><div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px">
      ${b.menu.map(s => `<a class="card kpi" href="${esc(s.items[0].route)}" style="text-decoration:none;color:inherit"><div class="lbl">${ic(secIcon(s.name))} ${esc(s.name)}</div><div class="foot">${s.items.length} screens</div></a>`).join('')}</div></div></div>`;
    $('#hCreate').onclick = () => openCreate();
    $$('#main [data-go]').forEach(k => k.onclick = () => go(k.dataset.go));
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
        <div class="actions"><button class="btn" id="lFilt">${ic('filter')} Filters${Object.values(st.filter || {}).filter(v => v !== '').length ? ' · ' + Object.values(st.filter).filter(v => v !== '').length : ''}</button><a class="btn" id="lExp" href="/api/export/${key}?${qs}" title="Download this list (with its filters) as a spreadsheet file">${ic('ext')} Export</a><button class="btn primary" id="lNew">${ic('plus')} New</button></div></div>
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
      <div class="actions"><button class="btn primary" id="rEdit">${ic('wand')} Edit</button></div></div>
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
