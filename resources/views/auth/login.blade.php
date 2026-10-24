<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width,initial-scale=1.0" />
    <link rel=icon href=/images/favicon.ico>
    <!-- Installable as a Windows app: Edge/Chrome show "Install" (public/manifest.webmanifest). -->
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0a7350">
    <link rel="apple-touch-icon" href="/images/app/app-icon-192.png">
    <link rel="stylesheet" href="/css/master.css">

    <title>NASPIN TECH | Cloudbased Inventory Management System</title>
  </head>

  <body class="text-left">
    <noscript>
      <strong>
        We're sorry but NASPIN TECH doesn't work properly without JavaScript
        enabled. Please enable it to continue.</strong
      >
    </noscript>

    <!-- built files will be auto injected -->
    <div class="loading_wrap" id="loading_wrap">
      <div class="loader_logo">
      <img src="/images/logo-default.png" alt="NASPIN TECH" />

      </div>

      <div class="loading"></div>
      <p class="loading-message">Loading CIMS…</p>
      <div class="loading-skeleton" aria-hidden="true"><span></span><span></span><span></span></div>
      <div class="loading-error" id="loading_error" role="alert" hidden>
        <strong>CIMS is taking longer than expected.</strong>
        <p>Check your connection, then try again.</p>
        <button type="button" onclick="window.location.reload()">Retry loading</button>
      </div>
    </div>
    <div id="login">
        <login-component></login-component>
      </div>

      <script defer src="/js/login.min.js?v={{ filemtime(public_path('js/login.min.js')) }}"></script>
      <script>setTimeout(function () { var wrap = document.getElementById('loading_wrap'); if (wrap && getComputedStyle(wrap).display !== 'none') { document.getElementById('loading_error').hidden = false; wrap.classList.add('loading-failed'); } }, 12000);</script>
  </body>
</html>

