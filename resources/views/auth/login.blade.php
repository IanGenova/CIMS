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
      <img src="/images/logo-default.png" class="" alt="logo" />

      </div>

      <div class="loading"></div>
    </div>
    <div id="login">
        <login-component></login-component>
      </div>

      <script src="/js/login.min.js?v=3.3.4"></script>
  </body>
</html>

