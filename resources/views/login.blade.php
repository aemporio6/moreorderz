<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="manifest" href="/manifest.webmanifest"><meta name="theme-color" content="#125e52"><link rel="apple-touch-icon" href="/app/icons/icon-192.png"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes">
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
@if (($step ?? '') === 'code')
<form class="login" method="post" action="/login/code">
  @csrf
  <h1>{{ config('app.name') }}</h1>
  <p>Enter the 6-digit code from the authenticator app on your phone.</p>
  @if ($errors->any())<div class="errbox">{{ $errors->first() }}</div>@endif
  <label for="code">Code</label>
  <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="7" autofocus required>
  <button class="btn primary" type="submit">Continue</button>
  <p style="margin-top:14px"><a href="/login">Start again</a></p>
</form>
@elseif (($step ?? '') === 'forgot')
<form class="login" method="post" action="/forgot">
  @csrf
  <h1>{{ config('app.name') }}</h1>
  <p>Forgot your password? Type your login and the e-mail address on file – we send a link to choose a new one.</p>
  <label for="login">Login</label>
  <input id="login" name="login" type="text" autocomplete="username" autofocus required>
  <label for="email">E-mail</label>
  <input id="email" name="email" type="email" autocomplete="email" required>
  <button class="btn primary" type="submit">Send the link</button>
  <p style="margin-top:14px"><a href="/login">Back to sign in</a></p>
</form>
@elseif (($step ?? '') === 'sent')
<div class="login">
  <h1>{{ config('app.name') }}</h1>
  <p>{{ $message ?? '' }}</p>
  <p style="margin-top:14px"><a href="/login">Back to sign in</a></p>
</div>
@elseif (($step ?? '') === 'reset')
<form class="login" method="post" action="/reset/{{ $token }}">
  @csrf
  <h1>{{ config('app.name') }}</h1>
  <p>Choose a new password (at least 10 characters). Every other sign-in of your login ends.</p>
  @if ($errors->any())<div class="errbox">{{ $errors->first() }}</div>@endif
  <label for="password">New password</label>
  <input id="password" name="password" type="password" autocomplete="new-password" minlength="10" autofocus required>
  <label for="password_again">New password again</label>
  <input id="password_again" name="password_again" type="password" autocomplete="new-password" minlength="10" required>
  <button class="btn primary" type="submit">Save the new password</button>
</form>
@else
<form class="login" method="post" action="/login">
  @csrf
  <h1>{{ config('app.name') }}</h1>
  <p>Sign in to continue.</p>
  @if (session('status'))<div class="errbox" style="background:var(--done-bg, #e7f6ee);color:var(--done, #1d6b46);border-color:var(--done-line, #b7e0c8)">{{ session('status') }}</div>@endif
  @if ($errors->any())<div class="errbox">{{ $errors->first() }}</div>@endif
  <label for="login">Login</label>
  <input id="login" name="login" type="text" value="{{ old('login') }}" autocomplete="username" autofocus required>
  <label for="password">Password</label>
  <input id="password" name="password" type="password" autocomplete="current-password" required>
  <label style="font-weight:400;display:flex;gap:8px;align-items:center"><input type="checkbox" name="remember" value="1"> Keep me signed in on this device</label>
  <button class="btn primary" type="submit">Sign in</button>
  <p style="margin-top:14px"><a href="/forgot">Forgot your password?</a></p>
</form>
@endif
<script>if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});</script>
</body>
</html>
