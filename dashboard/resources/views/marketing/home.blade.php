<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="index, follow">
  <link rel="icon" type="image/png" sizes="512x512" href="{{ asset('Logo.png') }}">
  <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
  <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
  <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
  <link rel="manifest" href="{{ asset('site.webmanifest') }}">
  <title>VerifySky | Cloudflare Worker Security SaaS</title>
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
  <main class="mx-auto flex min-h-screen max-w-6xl flex-col px-4 py-8">
    <header class="flex items-center justify-between border-b border-white/10 pb-5">
      <a href="/" class="flex items-center gap-3">
        <img src="{{ asset('Logo.png') }}" alt="VerifySky" class="h-12 w-12 rounded-lg border border-sky-400/40 bg-slate-900 object-cover">
        <span class="text-xl font-black tracking-tight">VerifySky</span>
      </a>
      <a href="{{ route('login') }}" class="rounded-lg border border-sky-300/40 px-4 py-2 text-sm font-semibold text-sky-100 transition hover:bg-sky-400 hover:text-slate-950">Admin Login</a>
    </header>

    <section class="grid flex-1 items-center gap-10 py-14 lg:grid-cols-[1.1fr_0.9fr]">
      <div>
        <p class="mb-4 text-sm font-bold uppercase tracking-[0.22em] text-sky-300">Cloudflare for SaaS control plane</p>
        <h1 class="max-w-3xl text-5xl font-black leading-tight md:text-6xl">Protect customer domains through one Worker.</h1>
        <p class="mt-6 max-w-2xl text-lg text-slate-300">
          VerifySky lets customers point their hostname to your platform while Cloudflare handles SSL, edge routing, and high-speed bot defense.
        </p>
        <div class="mt-8 flex flex-wrap gap-3">
          <a href="{{ route('login') }}" class="rounded-lg bg-sky-400 px-5 py-3 text-sm font-bold text-slate-950 transition hover:bg-sky-300">Open Control Plane</a>
          <a href="#flow" class="rounded-lg border border-white/15 px-5 py-3 text-sm font-semibold text-slate-200 transition hover:border-sky-300/60">View Domain Flow</a>
        </div>
      </div>

      <div class="rounded-lg border border-white/10 bg-white/[0.03] p-5 shadow-2xl shadow-sky-950/40">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">Customer DNS target</p>
        <div class="mt-4 rounded-lg border border-sky-300/25 bg-slate-900 p-4 font-mono text-sm text-sky-100">
          CNAME customers.verifysky.com
        </div>
        <div class="mt-5 grid gap-3 text-sm text-slate-300">
          <p class="rounded-lg border border-white/10 bg-slate-900/70 p-3">Custom Hostname verification</p>
          <p class="rounded-lg border border-white/10 bg-slate-900/70 p-3">Automatic certificate lifecycle</p>
          <p class="rounded-lg border border-white/10 bg-slate-900/70 p-3">Worker-powered challenge and policy engine</p>
        </div>
      </div>
    </section>

    <section id="flow" class="grid gap-4 border-t border-white/10 py-10 md:grid-cols-3">
      <article class="rounded-lg border border-white/10 bg-white/[0.03] p-5">
        <h2 class="text-lg font-bold">1. Customer Adds Domain</h2>
        <p class="mt-2 text-sm text-slate-300">They enter a hostname in your dashboard and receive a CNAME target.</p>
      </article>
      <article class="rounded-lg border border-white/10 bg-white/[0.03] p-5">
        <h2 class="text-lg font-bold">2. Cloudflare Verifies SSL</h2>
        <p class="mt-2 text-sm text-slate-300">The platform creates a Custom Hostname and monitors certificate status.</p>
      </article>
      <article class="rounded-lg border border-white/10 bg-white/[0.03] p-5">
        <h2 class="text-lg font-bold">3. Worker Enforces Policy</h2>
        <p class="mt-2 text-sm text-slate-300">Traffic flows through the Worker for risk scoring, challenge, and blocking.</p>
      </article>
    </section>
  </main>
</body>
</html>
