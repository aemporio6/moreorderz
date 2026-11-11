/* ==== 00_icons.js ==== */
/* MoreOrderz – logo and icons */
const MO = window.MO = {};
(function () { let r = ''; try { r = location.hash || ''; } catch (e) {} MO._route = r;
  MO.H = () => MO._route;
  MO.setH = h => { MO._route = h; try { if (location.hash !== h) history.pushState(null, '', h); } catch (e) { try { location.hash = h; } catch (x) {} } };
})();

// Logo (approved): "Engineering seal" – round seal, Vartleaves three bars in the centre, ring text
// MOREORDERZ • COMMERCE ENGINEERING. The ring turns while the system is working (browsing / searching).
const BARS = (fill, t) => `<g transform="${t}" fill="${fill}"><path d="M12 42 L31 36 L37 84 L22 87 Z"/><path d="M37 29 L57 23 L60 81 L44 83 Z"/><path d="M63 15 L85 9 L83 78 L67 80 Z"/></g>`;
let _sid = 0;
MO.seal = (size, opt = {}) => { const id = 'ms' + (++_sid); const mono = opt.mono;
  return `<svg class="${opt.cls || ''}" viewBox="0 0 100 100" width="${size}" height="${size}" aria-label="MoreOrderz">
  <defs><linearGradient id="${id}g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#11807B"/><stop offset="1" stop-color="#083F3D"/></linearGradient>
  <path id="${id}r" d="M50 50 m-39 0 a39 39 0 1 1 78 0 a39 39 0 1 1 -78 0"/></defs>
  ${mono ? '<circle cx="50" cy="50" r="48" fill="none" stroke="currentColor" stroke-width="2"/>' : `<circle cx="50" cy="50" r="48" fill="url(#${id}g)"/>`}
  <circle cx="50" cy="50" r="45" fill="none" stroke="${mono ? 'currentColor' : '#C2A06A'}" stroke-width="1"/>
  <circle cx="50" cy="50" r="31" fill="none" stroke="${mono ? 'currentColor' : '#C2A06A'}" stroke-opacity=".7" stroke-width=".8"/>
  <g class="orbit"><text font-family="Segoe UI,Arial" font-size="7.2" font-weight="700" letter-spacing="2.2" fill="${mono ? 'currentColor' : '#E9D9B8'}"><textPath href="#${id}r">MOREORDERZ • COMMERCE ENGINEERING •</textPath></text></g>
  ${BARS(mono ? 'currentColor' : '#fff', 'translate(29 28) scale(.42)')}</svg>`; };
MO.logo = (size = 40, cls = '') => MO.seal(size, { cls });
MO.watermark = () => MO.seal(400, { mono: true });
MO.spinner = (size = 64) => MO.seal(size, { cls: 'spinning' });

const I = {
  home:'<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/>',
  buy:'<path d="M3 4h2l2.4 11.2a1 1 0 0 0 1 .8h9.2a1 1 0 0 0 1-.8L20 8H6"/><circle cx="9" cy="20" r="1.3"/><circle cx="17" cy="20" r="1.3"/>',
  stock:'<path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z"/><path d="M3 7.5 12 12l9-4.5M12 12v9"/>',
  sell:'<path d="M20 12 12 20l-8-8V4h8z"/><circle cx="8.5" cy="8.5" r="1.4"/>',
  money:'<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.6"/><path d="M6 9.5v5M18 9.5v5"/>',
  partners:'<circle cx="8" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M2.5 20c.6-3.4 2.8-5.5 5.5-5.5s4.9 2.1 5.5 5.5M14 15.2c.9-.5 1.9-.7 3-.7 2.4 0 4 1.8 4.5 4.5"/>',
  audit:'<path d="M9 4h6l1 2h3v15H5V6h3z"/><path d="m9 13 2 2 4-4"/>',
  people:'<circle cx="12" cy="8" r="3.5"/><path d="M5 20c.8-3.8 3.6-6 7-6s6.2 2.2 7 6"/>',
  work:'<rect x="4" y="4" width="16" height="16" rx="2.5"/><path d="m8 12 2.5 2.5L16 9"/>',
  docs:'<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5M10 13h6M10 17h6"/>',
  import:'<path d="M12 3v12m0 0-4-4m4 4 4-4"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>',
  reports:'<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
  setup:'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 7 19.4l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 3 14H3a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 7l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.6 1.6 0 0 0 10 3.1V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
  search:'<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
  plus:'<path d="M12 5v14M5 12h14"/>',
  refresh:'<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/>',
  bell:'<path d="M6 16V11a6 6 0 1 1 12 0v5l2 2H4z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
  help:'<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.3-1 .8-1 1.5V14M12 17h.01"/>',
  menu:'<path d="M4 6h16M4 12h16M4 18h16"/>',
  chev:'<path d="m9 6 6 6-6 6"/>',
  x:'<path d="M6 6l12 12M18 6 6 18"/>',
  star:'<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>',
  ext:'<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
  bolt:'<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
  moon:'<path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z"/>',
  sun:'<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
  link:'<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
  drive:'<path d="M8 3h8l6 10-4 7H6l-4-7z"/><path d="M8 3l6 10H2M16 3 10 13l-4 7M14 13h8"/>',
  undo:'<path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/>',
  check:'<path d="m5 12 5 5 9-10"/>',
  alert:'<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/>',
  eye:'<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
  filter:'<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
  save:'<path d="M5 3h11l3 3v15H5z"/><path d="M8 3v5h8M8 21v-7h8v7"/>',
  play:'<path d="M7 4v16l13-8z"/>',
  panel:'<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M15 4v16"/>',
  file:'<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/>',
  lock:'<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
  user:'<circle cx="12" cy="8" r="3.5"/><path d="M5 20c.8-3.8 3.6-6 7-6s6.2 2.2 7 6"/>',
  truck:'<path d="M2 6h11v10H2zM13 10h4l4 3v3h-8"/><circle cx="6" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>',
  wand:'<path d="m4 20 11-11M14 4l1 2 2 1-2 1-1 2-1-2-2-1 2-1zM19 11l.6 1.4L21 13l-1.4.6L19 15l-.6-1.4L17 13l1.4-.6z"/>'
};
MO.ic = (name, cls = 'ico') => `<svg class="${cls}" viewBox="0 0 24 24">${I[name] || I.file}</svg>`;

