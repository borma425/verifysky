<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="index, follow">
  <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
  <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
  <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
  <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
  <link rel="manifest" href="{{ asset('site.webmanifest') }}">
  <title>VerifySky | Enterprise Domain Protection</title>
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#171C26] text-[#D7E1F5]">
  <main class="mx-auto flex min-h-screen max-w-6xl flex-col px-4 py-8">
    <header class="flex items-center justify-between border-b border-white/10 pb-5">
      <a href="/" class="flex items-center gap-3">
        <img src="{{ asset('Logo.png') }}" alt="VerifySky" class="h-auto w-20 object-contain">
      </a>
      <a href="{{ route('admin.login') }}" class="es-btn">Admin Login</a>
    </header>

    <section class="grid flex-1 items-center gap-10 py-14 lg:grid-cols-[1.1fr_0.9fr]">
      <div>
        <p class="mb-4 text-sm font-bold uppercase tracking-[0.22em] text-[#FCB900]">Enterprise domain protection</p>
        <h1 class="max-w-3xl text-5xl font-black leading-tight text-[#FFFFFF] md:text-6xl">Protect customer domains through one edge shield.</h1>
        <p class="mt-6 max-w-2xl text-lg text-[#D7E1F5]">
          VerifySky lets customers point their hostname to your platform while managed SSL, edge routing, and high-speed bot defense work behind the scenes.
        </p>
        <div class="mt-8 flex flex-wrap gap-3">
          <a href="{{ route('admin.login') }}" class="es-btn">Open Control Plane</a>
          <a href="#flow" class="es-btn es-btn-secondary">View Domain Flow</a>
        </div>
      </div>

      <div class="rounded-lg border border-white/10 bg-[#202632] p-5 shadow-2xl">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#959BA7]">Customer DNS target</p>
        <div class="mt-4 rounded-lg border border-[#FCB900]/25 bg-[#313A4B] p-4 font-mono text-sm text-[#FFFFFF]">
          CNAME customers.verifysky.com
        </div>
        <div class="mt-5 grid gap-3 text-sm text-[#D7E1F5]">
          <p class="rounded-lg border border-white/10 bg-[#313A4B] p-3">Domain verification</p>
          <p class="rounded-lg border border-white/10 bg-[#313A4B] p-3">Automatic certificate lifecycle</p>
          <p class="rounded-lg border border-white/10 bg-[#313A4B] p-3">Challenge and policy engine</p>
        </div>
      </div>
    </section>

    <section id="flow" class="grid gap-4 border-t border-white/10 py-10 md:grid-cols-3">
      <article class="rounded-lg border border-white/10 bg-white/[0.03] p-5">
        <h2 class="text-lg font-bold">1. Customer Adds Domain</h2>
        <p class="mt-2 text-sm text-[#D7E1F5]">They enter a hostname in your dashboard and receive a CNAME target.</p>
      </article>
      <article class="rounded-lg border border-white/10 bg-white/[0.03] p-5">
        <h2 class="text-lg font-bold">2. VerifySky Verifies SSL</h2>
        <p class="mt-2 text-sm text-[#D7E1F5]">The platform prepares the hostname and monitors certificate status.</p>
      </article>
      <article class="rounded-lg border border-white/10 bg-white/[0.03] p-5">
        <h2 class="text-lg font-bold">3. VerifySky Enforces Policy</h2>
        <p class="mt-2 text-sm text-[#D7E1F5]">Traffic flows through VerifySky for risk scoring, challenge, and blocking.</p>
      </article>
    </section>
  </main>
</body>
</html>
