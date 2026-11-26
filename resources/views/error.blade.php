<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MoreOrderz</title>
<link rel="stylesheet" href="/app/design.css">
<style>.errp{max-width:460px;margin:12vh auto 0;padding:0 16px;text-align:center}.errp .n{font-size:54px;font-weight:700;color:var(--accent);line-height:1}.errp p{font-size:16px;margin:14px 0 20px}.errp form{display:flex;gap:8px;margin-bottom:14px}.errp input{flex:1;height:40px;border:1px solid var(--line);border-radius:8px;padding:0 12px;background:var(--surface-2);color:var(--text);font:inherit}.errp a.b,.errp button{display:inline-block;height:40px;line-height:40px;padding:0 18px;border-radius:8px;border:0;background:var(--accent);color:#fff;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}</style></head>
<body><div class="errp"><div class="n">{{ $code }}</div><p>{{ $text }}</p>
@if ($search)<form method="get" action="/" onsubmit="location.href='/?find='+encodeURIComponent(this.q.value);return false"><input name="q" placeholder="Search" autofocus><button type="submit">Search</button></form>@endif
<a class="b" href="/">Home</a></div></body></html>
