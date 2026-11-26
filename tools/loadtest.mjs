// MR-1547 load test: many users at once against the live tool. Node 18+.
//   node tools/loadtest.mjs https://app.moreorderz.com "<laravel_session cookie value>" 20 60
// = 20 users, 60 seconds, each user opens the busy pages in turn. Prints requests, errors and the 50 / 95 / 99 % response times per page.
// Use the cookie of a test login (never the Owner's); run it outside busy hours.
const [base, cookie, users = '10', seconds = '30'] = process.argv.slice(2);
if (!base || !cookie) { console.log('usage: node tools/loadtest.mjs <base url> <session cookie> [users] [seconds]'); process.exit(1); }
const pages = ['/api/boot', '/api/home', '/api/tasks?scope=mine', '/api/sales?per=50', '/api/icc', '/api/find?q=bowl', '/api/deadlines'];
const t = {}; let errors = 0; const end = Date.now() + (+seconds) * 1000;
async function user() { while (Date.now() < end) for (const p of pages) { const s = performance.now();
  try { const r = await fetch(base + p, { headers: { Cookie: 'laravel_session=' + cookie, Accept: 'application/json' } }); if (r.status >= 500) errors++; await r.arrayBuffer(); } catch { errors++; }
  (t[p] ||= []).push(performance.now() - s); } }
await Promise.all(Array.from({ length: +users }, user));
const q = (a, f) => a.sort((x, y) => x - y)[Math.min(a.length - 1, Math.floor(a.length * f))].toFixed(0);
let n = 0; for (const [p, a] of Object.entries(t)) { n += a.length; console.log(p.padEnd(26), String(a.length).padStart(5), 'req  p50', q(a, .5), 'ms  p95', q(a, .95), 'ms  p99', q(a, .99), 'ms'); }
console.log(`${n} requests, ${errors} errors (5xx or no answer), ${users} users, ${seconds} s`);
