/* Owner 26-11: the MoreOrderz seal in the browser tab, its ring turning slowly (the same seal as the top-left logo).
   Browsers do not play animated icons by themselves, so each frame is drawn on a small canvas and set as the tab icon. */
(function () {
  const link = document.querySelector('link[rel="icon"]') || Object.assign(document.head.appendChild(document.createElement('link')), { rel: 'icon' });
  const c = document.createElement('canvas'); c.width = c.height = 64; const x = c.getContext && c.getContext('2d'); if (!x) return;
  const bars = new Path2D('M12 42 L31 36 L37 84 L22 87 Z M37 29 L57 23 L60 81 L44 83 Z M63 15 L85 9 L83 78 L67 80 Z');
  let a = 0;
  function frame() {
    const s = 64 / 100; x.setTransform(1, 0, 0, 1, 0, 0); x.clearRect(0, 0, 64, 64); x.scale(s, s);
    const g = x.createLinearGradient(0, 0, 100, 100); g.addColorStop(0, '#11807B'); g.addColorStop(1, '#083F3D');
    x.fillStyle = g; x.beginPath(); x.arc(50, 50, 48, 0, Math.PI * 2); x.fill();
    x.strokeStyle = '#C2A06A'; x.lineWidth = 3; x.beginPath(); x.arc(50, 50, 44, 0, Math.PI * 2); x.stroke();
    x.save(); x.translate(50, 50); x.rotate(a); x.strokeStyle = '#E9D9B8'; x.lineWidth = 5; x.setLineDash([10, 6]); x.beginPath(); x.arc(0, 0, 38, 0, Math.PI * 2); x.stroke(); x.restore();
    x.save(); x.translate(27, 26); x.scale(.48, .48); x.fillStyle = '#fff'; x.fill(bars); x.restore();
    try { link.type = 'image/png'; link.href = c.toDataURL('image/png'); } catch (e) {}
    a += Math.PI / 24;      // one turn in 48 frames ≈ 7 seconds
  }
  frame(); setInterval(frame, 150);
})();
