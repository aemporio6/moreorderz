<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · {{ config('app.name') }}</title>
<link rel="stylesheet" href="/app/design.css">
<style>
body{display:grid;place-items:center;min-height:100vh;padding:20px}
.login{width:min(380px,100%);background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:26px}
.login h1{font-size:20px;margin:0 0 4px}.login p{color:var(--muted);margin:0 0 18px;font-size:13px}
.login label{display:block;font-size:12.5px;font-weight:600;margin:12px 0 5px}
.login input[type=text],.login input[type=password]{width:100%;height:38px;border:1px solid var(--line);border-radius:7px;padding:0 10px;background:var(--surface-2);color:var(--text);font:inherit}
.login .btn{width:100%;height:38px;margin-top:18px;justify-content:center}
.login .errbox{background:var(--err-bg);color:var(--err);border:1px solid var(--err-line);border-radius:7px;padding:8px 10px;font-size:13px;margin-bottom:6px}
</style>
</head>
<body>
<form class="login" method="post" action="/login">
  @csrf
  <h1>{{ config('app.name') }}</h1>
  <p>Sign in to continue.</p>
  @if ($errors->any())<div class="errbox">{{ $errors->first() }}</div>@endif
  <label for="login">Login</label>
  <input id="login" name="login" type="text" value="{{ old('login') }}" autocomplete="username" autofocus required>
  <label for="password">Password</label>
  <input id="password" name="password" type="password" autocomplete="current-password" required>
  <label style="font-weight:400;display:flex;gap:8px;align-items:center"><input type="checkbox" name="remember" value="1"> Keep me signed in on this device</label>
  <button class="btn primary" type="submit">Sign in</button>
</form>
</body>
</html>
