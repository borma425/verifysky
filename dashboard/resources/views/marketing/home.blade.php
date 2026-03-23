<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
  <link rel="canonical" href="{{ url('/') }}">
  <title>YouCaptcha | Global Human Verification Cloud</title>
  @vite(['resources/css/app.css', 'resources/js/app.js'])
  <style>
    :root{--bg:#08131a;--panel:#0f2029;--line:rgba(148,163,184,.25);--c1:#22d3ee;--c2:#0ea5e9;--c3:#f59e0b}
    body{background:var(--bg);font-family:"Space Grotesk","Manrope","Segoe UI",sans-serif}
    .ambient{background:radial-gradient(1100px 520px at 12% -8%,rgba(34,211,238,.28),transparent 55%),radial-gradient(920px 450px at 92% 0%,rgba(14,165,233,.2),transparent 54%),radial-gradient(900px 360px at 50% 100%,rgba(245,158,11,.14),transparent 62%)}
    .noise{position:absolute;inset:0;pointer-events:none;opacity:.2;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='180' height='180' viewBox='0 0 180 180'%3E%3Cg fill='none' stroke='rgba(148,163,184,0.18)' stroke-width='1'%3E%3Cpath d='M0 30h180M0 90h180M0 150h180M30 0v180M90 0v180M150 0v180'/%3E%3C/g%3E%3C/svg%3E")}
    .glass{background:linear-gradient(180deg,rgba(255,255,255,.08),rgba(255,255,255,.02));backdrop-filter:blur(14px);border:1px solid rgba(148,163,184,.25)}
    .reveal{opacity:0;transform:translateY(18px);transition:opacity .55s ease,transform .55s ease}
    .reveal.in{opacity:1;transform:translateY(0)}
    .hero-title{line-height:.95;letter-spacing:-.02em}
    .timeline:before{content:"";position:absolute;left:18px;top:10px;bottom:10px;width:2px;background:linear-gradient(to bottom,rgba(34,211,238,.8),rgba(14,165,233,.28))}
    .dot{box-shadow:0 0 0 4px rgba(34,211,238,.1),0 0 0 8px rgba(34,211,238,.06)}
    .price-pop{animation:floaty 3.4s ease-in-out infinite}
    @keyframes floaty{0%,100%{transform:translateY(0)}50%{transform:translateY(-5px)}}
  </style>
</head>
<body class="text-slate-100">
  <div class="ambient min-h-screen overflow-x-hidden">
    <div class="noise"></div>

    <header class="sticky top-0 z-50 border-b border-slate-700/40 bg-[#071018]/80 backdrop-blur">
      <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3">
        <a href="/" class="flex items-center gap-2">
          <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-cyan-400/20 text-cyan-300">Y</span>
          <span class="text-lg font-black tracking-tight">YouCaptcha</span>
        </a>
        <nav class="hidden items-center gap-5 text-sm text-slate-300 md:flex">
          <a class="hover:text-white" href="#platform">Platform</a>
          <a class="hover:text-white" href="#timeline">How It Works</a>
          <a class="hover:text-white" href="#pricing">Pricing</a>
          <a class="hover:text-white" href="#contact">Get Started</a>
        </nav>
      </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-10 md:py-14">
      @if(session('status'))
        <div class="mb-6 rounded-xl border border-emerald-400/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">{{ session('status') }}</div>
      @endif
      @if($errors->any())
        <div class="mb-6 rounded-xl border border-rose-400/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">{{ $errors->first() }}</div>
      @endif

      <section class="mb-12 grid gap-6 lg:grid-cols-2">
        <div class="reveal rounded-3xl p-7 md:p-10 glass">
          <p class="mb-3 text-xs font-bold tracking-[0.22em] text-cyan-300">GLOBAL HUMAN VERIFICATION CLOUD</p>
          <h1 class="hero-title mb-4 text-5xl font-black md:text-6xl">Block Bots.<br>Keep Humans Fast.</h1>
          <p class="mb-7 max-w-xl text-slate-300">YouCaptcha provides adaptive edge verification with friction-aware security workflows, high-throughput challenge orchestration, and traffic trust scoring at global scale.</p>
          <div class="flex flex-wrap gap-3">
            <a href="#contact" class="rounded-xl bg-cyan-400 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300">Request Enterprise Demo</a>
            <a href="#timeline" class="rounded-xl border border-slate-500/70 bg-slate-900/50 px-5 py-2.5 text-sm font-semibold text-slate-200 transition hover:border-cyan-300/60">Architecture Timeline</a>
          </div>
        </div>
        <div class="grid gap-4">
          <div class="reveal rounded-2xl p-5 glass">
            <p class="text-xs uppercase tracking-[0.16em] text-slate-400">Verified Human Passage Rate</p>
            <p class="mt-2 text-4xl font-black text-white">99.4%</p>
            <p class="mt-1 text-xs text-slate-400">across multi-region challenge clusters</p>
          </div>
          <div class="reveal rounded-2xl p-5 glass">
            <p class="text-xs uppercase tracking-[0.16em] text-slate-400">Bot Reduction Efficiency</p>
            <p class="mt-2 text-4xl font-black text-white">+93%</p>
            <p class="mt-1 text-xs text-slate-400">with adaptive verification layers</p>
          </div>
          <div class="reveal rounded-2xl p-5 glass">
            <p class="text-xs uppercase tracking-[0.16em] text-slate-400">Median Decision Time</p>
            <p class="mt-2 text-4xl font-black text-white">&lt;40ms</p>
            <p class="mt-1 text-xs text-slate-400">edge-scored with low-latency routing</p>
          </div>
        </div>
      </section>

      <section class="mb-12">
        <div class="reveal rounded-3xl p-6 md:p-8 glass">
          <p class="mb-3 text-xs font-bold tracking-[0.2em] text-cyan-300">OPEN PLATFORM ACCESS</p>
          <h2 class="mb-3 text-3xl font-black text-white md:text-4xl">Full Open-Source Control, Built for Teams That Need Total Flexibility</h2>
          <p class="max-w-3xl text-sm text-slate-300 md:text-base">Get production-ready source code with complete freedom to customize every component, workflow, policy, and UI without vendor lock-in.</p>
          <div class="mt-5 grid gap-3 text-sm text-slate-200 md:grid-cols-2">
            <p class="rounded-xl border border-slate-600/60 bg-slate-900/35 px-4 py-3">100% editable architecture</p>
            <p class="rounded-xl border border-slate-600/60 bg-slate-900/35 px-4 py-3">Self-hosted or hybrid deployment options</p>
            <p class="rounded-xl border border-slate-600/60 bg-slate-900/35 px-4 py-3">Custom security logic and challenge flows</p>
            <p class="rounded-xl border border-slate-600/60 bg-slate-900/35 px-4 py-3">Developer-first documentation and integration guides</p>
          </div>
          <div class="mt-5">
            <a href="#contact" class="inline-flex rounded-xl bg-cyan-400 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300">Request Source Access</a>
          </div>
        </div>
      </section>

      <section id="platform" class="mb-12">
        <div class="reveal mb-4 flex items-end justify-between">
          <h2 class="text-3xl font-black">Platform Modules</h2>
          <p class="text-sm text-slate-400">Modern verification kit for high-risk traffic surfaces</p>
        </div>
        <div class="grid gap-4 md:grid-cols-3">
          <article class="reveal rounded-2xl p-5 glass">
            <h3 class="mb-2 text-lg font-bold text-white">Adaptive Challenge Engine</h3>
            <p class="text-sm text-slate-300">Dynamically adjusts challenge complexity based on request entropy, behavior history, and route sensitivity.</p>
          </article>
          <article class="reveal rounded-2xl p-5 glass">
            <h3 class="mb-2 text-lg font-bold text-white">Edge Threat Decisioning</h3>
            <p class="text-sm text-slate-300">Instant edge-side scoring and policy branching for login, checkout, API, and content surfaces.</p>
          </article>
          <article class="reveal rounded-2xl p-5 glass">
            <h3 class="mb-2 text-lg font-bold text-white">Risk Intelligence Graph</h3>
            <p class="text-sm text-slate-300">Continuous trust graph enrichment using telemetry, timing patterns, path pressure, and identity heuristics.</p>
          </article>
        </div>
      </section>

      <section id="timeline" class="mb-12 rounded-3xl p-6 md:p-8 glass">
        <div class="reveal mb-6">
          <h2 class="text-3xl font-black">How We Work (Operational Timeline)</h2>
          <p class="mt-2 max-w-3xl text-sm text-slate-300">An illustrative, high-level service flow designed to show product capabilities without exposing internal implementation details.</p>
        </div>
        <div class="timeline relative grid gap-5 pl-12">
          <div class="reveal relative">
            <span class="dot absolute -left-[44px] top-1 h-4 w-4 rounded-full bg-cyan-300"></span>
            <h3 class="font-bold text-white">Stage 01 — Global Edge Intake</h3>
            <p class="text-sm text-slate-300">Incoming traffic is routed through distributed edge points for consistency, stability, and service continuity.</p>
          </div>
          <div class="reveal relative">
            <span class="dot absolute -left-[44px] top-1 h-4 w-4 rounded-full bg-cyan-300"></span>
            <h3 class="font-bold text-white">Stage 02 — Signal Enrichment</h3>
            <p class="text-sm text-slate-300">General service signals are enriched to improve platform reliability and user experience across regions.</p>
          </div>
          <div class="reveal relative">
            <span class="dot absolute -left-[44px] top-1 h-4 w-4 rounded-full bg-cyan-300"></span>
            <h3 class="font-bold text-white">Stage 03 — Policy Mapping</h3>
            <p class="text-sm text-slate-300">Traffic contexts are aligned to policy groups for standardized handling and easier compliance operations.</p>
          </div>
          <div class="reveal relative">
            <span class="dot absolute -left-[44px] top-1 h-4 w-4 rounded-full bg-cyan-300"></span>
            <h3 class="font-bold text-white">Stage 04 — Experience Orchestration</h3>
            <p class="text-sm text-slate-300">Verification journeys are orchestrated to balance friction, throughput, and user confidence.</p>
          </div>
          <div class="reveal relative">
            <span class="dot absolute -left-[44px] top-1 h-4 w-4 rounded-full bg-cyan-300"></span>
            <h3 class="font-bold text-white">Stage 05 — Trust Confirmation</h3>
            <p class="text-sm text-slate-300">Platform trust checks are applied to support protected access decisions in real time.</p>
          </div>
          <div class="reveal relative">
            <span class="dot absolute -left-[44px] top-1 h-4 w-4 rounded-full bg-cyan-300"></span>
            <h3 class="font-bold text-white">Stage 06 — Session Continuity</h3>
            <p class="text-sm text-slate-300">Approved journeys continue with performance-aware controls for smooth ongoing interaction.</p>
          </div>
          <div class="reveal relative">
            <span class="dot absolute -left-[44px] top-1 h-4 w-4 rounded-full bg-cyan-300"></span>
            <h3 class="font-bold text-white">Stage 07 — Optimization Cycle</h3>
            <p class="text-sm text-slate-300">Operational outcomes are reviewed to refine service quality, resilience, and deployment consistency.</p>
          </div>
          <div class="reveal relative">
            <span class="dot absolute -left-[44px] top-1 h-4 w-4 rounded-full bg-cyan-300"></span>
            <h3 class="font-bold text-white">Stage 08 — Reporting Layer</h3>
            <p class="text-sm text-slate-300">Business-facing insights and operational reporting are delivered for planning and governance workflows.</p>
          </div>
        </div>
      </section>

      <section id="pricing" class="mb-12">
        <div class="reveal mb-4">
          <h2 class="text-3xl font-black">Pricing Plans</h2>
        </div>
        <div class="grid gap-4 md:grid-cols-3">
          <article class="reveal rounded-2xl p-6 glass">
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">Starter</p>
            <p class="my-3 text-4xl font-black text-white">$9<span class="text-base font-medium text-slate-400">/mo</span></p>
            <p class="text-sm text-slate-300">Up to 200K protected requests, baseline challenge orchestration, and route-level analytics.</p>
          </article>
          <article class="reveal price-pop rounded-2xl border border-cyan-300/40 p-6 glass">
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-cyan-300">Scale</p>
            <p class="my-3 text-4xl font-black text-white">$19<span class="text-base font-medium text-slate-400">/mo</span></p>
            <p class="text-sm text-slate-300">Up to 3M requests, advanced risk graphing, adaptive policy packs, and API integrations.</p>
          </article>
          <article class="reveal rounded-2xl p-6 glass">
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">Enterprise</p>
            <p class="my-3 text-4xl font-black text-white">$49<span class="text-base font-medium text-slate-400">/mo</span></p>
            <p class="text-sm text-slate-300">Priority support, dedicated deployment guidance, compliance workflows, and security architect support.</p>
          </article>
        </div>
      </section>

      <section id="contact" class="reveal rounded-3xl p-6 md:p-8 glass">
        <h2 class="text-3xl font-black">Apply for Activation</h2>
        <p class="mt-2 mb-5 text-sm text-slate-300">Share your details and our onboarding team will contact you with rollout guidance.</p>
        <form method="POST" action="{{ route('marketing.lead') }}" class="grid gap-3 md:grid-cols-2">
          @csrf
          <input name="name" value="{{ old('name') }}" required placeholder="Full name" class="rounded-xl border border-slate-500/60 bg-slate-900/45 px-3 py-2.5 text-slate-100 outline-none ring-cyan-400 focus:ring">
          <input name="email" value="{{ old('email') }}" required type="email" placeholder="Work email" class="rounded-xl border border-slate-500/60 bg-slate-900/45 px-3 py-2.5 text-slate-100 outline-none ring-cyan-400 focus:ring">
          <input name="domain" value="{{ old('domain') }}" required placeholder="Primary domain (example.com)" class="rounded-xl border border-slate-500/60 bg-slate-900/45 px-3 py-2.5 text-slate-100 outline-none ring-cyan-400 focus:ring md:col-span-2">
          <input name="company" value="{{ old('company') }}" placeholder="Company (optional)" class="rounded-xl border border-slate-500/60 bg-slate-900/45 px-3 py-2.5 text-slate-100 outline-none ring-cyan-400 focus:ring md:col-span-2">
          <textarea name="notes" rows="4" placeholder="Traffic profile, stack, and preferred launch window (optional)" class="rounded-xl border border-slate-500/60 bg-slate-900/45 px-3 py-2.5 text-slate-100 outline-none ring-cyan-400 focus:ring md:col-span-2">{{ old('notes') }}</textarea>
          <button type="submit" class="rounded-xl bg-cyan-400 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300 md:col-span-2">Submit Activation Request</button>
        </form>
      </section>
    </main>
  </div>

  <script>
    (function(){
      var io = new IntersectionObserver(function(entries){
        entries.forEach(function(entry){
          if(entry.isIntersecting){ entry.target.classList.add('in'); }
        });
      },{threshold:0.12});
      document.querySelectorAll('.reveal').forEach(function(el,idx){
        el.style.transitionDelay = (idx % 6) * 60 + 'ms';
        io.observe(el);
      });
    })();
  </script>
</body>
</html>
