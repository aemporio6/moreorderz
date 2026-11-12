<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="manifest" href="/manifest.webmanifest"><meta name="theme-color" content="#125e52"><link rel="apple-touch-icon" href="/app/icons/icon-192.png"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ config('app.name') }}</title>
<link rel="stylesheet" href="/app/design.css?v={{ filemtime(public_path('app/design.css')) }}">
<link rel="stylesheet" href="/app/app.css?v={{ filemtime(public_path('app/app.css')) }}">
</head>
<body>
<header id="top"></header>
<nav id="side"></nav>
<main id="main"></main>
<div id="loader"><div class="box"><div id="loaderTxt">Working…</div></div></div>
<div id="toasts"></div>
<form id="logoutForm" method="post" action="/logout" hidden>@csrf</form>
<script src="/app/icons.js?v={{ filemtime(public_path('app/icons.js')) }}"></script>
<script src="/app/app.js?v={{ filemtime(public_path('app/app.js')) }}"></script>
<script>if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});</script>
</body>
</html>
