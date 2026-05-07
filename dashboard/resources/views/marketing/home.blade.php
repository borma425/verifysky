@php
    $duotone = static fn (string $icon): string => asset('duotone/'.$icon);
@endphp
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <base target="_self">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VerifySky — Ad Protection & Edge Cybersecurity Platform</title>
    <meta name="description" content="Stop fake clicks before they drain your budget. Enterprise-grade ad fraud prevention and edge security powered by Cloudflare Workers.">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        obsidian: "#0B0F19",
                        slate: {
                            850: "#151B2B",
                            900: "#0F172A",
                            950: "#020617"
                        },
                        gold: {
                            400: "#FBBF24",
                            500: "#F59E0B",
                            600: "#D97706"
                        },
                        cyan: {
                            400: "#22D3EE",
                            500: "#06B6D4"
                        },
                        emerald: {
                            400: "#34D399",
                            500: "#10B981"
                        },
                        coral: {
                            400: "#FB923C",
                            500: "#F97316"
                        }
                    },
                    fontFamily: {
                        sans: ["Inter", "system-ui", "sans-serif"],
                        mono: ["JetBrains Mono", "monospace"]
                    },
                    animation: {
                        "pulse-slow": "pulse 4s cubic-bezier(0.4, 0, 0.6, 1) infinite",
                        "flow": "flow 2s linear infinite",
                        "packet": "packet 3s linear infinite",
                        "packet-delayed": "packet 3s linear infinite 1.5s",
                        "fragment": "fragment 0.6s ease-out forwards",
                        "telemetry": "telemetry 20s linear infinite",
                        "glow": "glow 2s ease-in-out infinite alternate",
                        "hostile": "hostile 2.4s ease-in-out infinite",
                        "scan": "scan 2.8s ease-in-out infinite",
                        "riskbar": "riskbar 2.6s ease-in-out infinite"
                    },
                    keyframes: {
                        flow: {
                            "0%": { strokeDashoffset: "100" },
                            "100%": { strokeDashoffset: "0" }
                        },
                        packet: {
                            "0%": { transform: "translateX(0)", opacity: "0" },
                            "10%": { opacity: "1" },
                            "40%": { transform: "translateX(400px)", opacity: "1" },
                            "45%": { opacity: "0" },
                            "100%": { opacity: "0" }
                        },
                        fragment: {
                            "0%": { transform: "scale(1)", opacity: "1" },
                            "100%": { transform: "scale(0)", opacity: "0" }
                        },
                        telemetry: {
                            "0%": { transform: "translateY(0)" },
                            "100%": { transform: "translateY(-50%)" }
                        },
                        glow: {
                            "0%": { boxShadow: "0 0 5px rgba(245, 158, 11, 0.2)" },
                            "100%": { boxShadow: "0 0 20px rgba(245, 158, 11, 0.6), 0 0 40px rgba(245, 158, 11, 0.3)" }
                        },
                        hostile: {
                            "0%, 100%": { transform: "translateY(0) rotate(0deg)" },
                            "40%": { transform: "translateY(-4px) rotate(-2deg)" },
                            "70%": { transform: "translateY(2px) rotate(2deg)" }
                        },
                        scan: {
                            "0%, 100%": { opacity: "0.35", transform: "scaleX(0.72)" },
                            "50%": { opacity: "1", transform: "scaleX(1)" }
                        },
                        riskbar: {
                            "0%, 100%": { width: "32%" },
                            "50%": { width: "86%" }
                        }
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <style>
        body {
            background-color: #0B0F19;
            color: #E2E8F0;
        }

        html {
            overflow-x: hidden;
        }

        .glass-panel {
            background: rgba(21, 27, 43, 0.6);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        .gradient-text {
            background: linear-gradient(135deg, #FBBF24 0%, #F59E0B 50%, #D97706 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .cyan-glow {
            box-shadow: 0 0 20px rgba(34, 211, 238, 0.15);
        }

        .gold-glow {
            box-shadow: 0 0 30px rgba(245, 158, 11, 0.3);
        }

        .topology-line {
            stroke-dasharray: 10 5;
            animation: flow 3s linear infinite;
        }

        @keyframes telemetry-icon-pulse {
            0%, 100% {
                opacity: 0.86;
                transform: scale(0.94);
            }
            50% {
                opacity: 1;
                transform: scale(1.04);
            }
        }

        .telemetry-node-icon {
            animation: telemetry-icon-pulse 2.8s ease-in-out infinite;
            transform-box: fill-box;
            transform-origin: center;
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        .mono-text {
            font-variant-numeric: tabular-nums;
        }

        .icon-tile {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 3rem;
            height: 3rem;
            border-radius: 0.8rem;
            border: 1px solid rgba(148, 163, 184, 0.16);
            background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.012)), rgba(15, 23, 42, 0.72);
        }

        .icon-tile img,
        .hero-icon img {
            width: 1.55rem;
            height: 1.55rem;
            object-fit: contain;
        }

        .tone-threat { filter: invert(57%) sepia(84%) saturate(1852%) hue-rotate(328deg) brightness(101%) contrast(96%); }
        .tone-ai { filter: invert(76%) sepia(63%) saturate(1134%) hue-rotate(349deg) brightness(104%) contrast(97%); }
        .tone-detect { filter: invert(75%) sepia(95%) saturate(1112%) hue-rotate(141deg) brightness(102%) contrast(88%); }
        .tone-protect { filter: invert(73%) sepia(69%) saturate(601%) hue-rotate(103deg) brightness(93%) contrast(89%); }
        .tone-muted { filter: invert(64%) sepia(16%) saturate(561%) hue-rotate(176deg) brightness(91%) contrast(88%); }

        .threat-glow { box-shadow: 0 0 22px rgba(249, 115, 22, 0.22), inset 0 0 18px rgba(249, 115, 22, 0.05); }
        .ai-glow { box-shadow: 0 0 24px rgba(245, 158, 11, 0.25), inset 0 0 18px rgba(245, 158, 11, 0.06); }
        .detect-glow { box-shadow: 0 0 24px rgba(34, 211, 238, 0.2), inset 0 0 18px rgba(34, 211, 238, 0.05); }
        .protect-glow { box-shadow: 0 0 24px rgba(16, 185, 129, 0.22), inset 0 0 18px rgba(16, 185, 129, 0.05); }

        .hero-icon {
            position: absolute;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 3.4rem;
            height: 3.4rem;
            border-radius: 1rem;
            border: 1px solid rgba(148, 163, 184, 0.14);
            background: rgba(8, 13, 24, 0.86);
            backdrop-filter: blur(10px);
        }

        .feature-card {
            min-height: 15rem;
            position: relative;
            overflow: hidden;
        }

        .feature-card::after {
            content: "";
            position: absolute;
            inset: auto 1.5rem 0 1.5rem;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.16), transparent);
            opacity: 0;
            transition: opacity 180ms ease;
        }

        .feature-card:hover::after { opacity: 1; }
        .feature-card[data-motion="threat"]:hover .icon-tile { animation: hostile 420ms ease-in-out; }
        .feature-card[data-motion="ai"]:hover .icon-tile { box-shadow: 0 0 28px rgba(245, 158, 11, 0.42); }
        .feature-card[data-motion="detect"]:hover .scan-line { animation: scan 900ms ease-in-out; }
        .feature-card[data-motion="protect"]:hover .icon-tile { box-shadow: 0 0 30px rgba(16, 185, 129, 0.36); }

        .scan-line {
            transform-origin: left;
        }

        .telemetry-roll {
            animation: none;
        }

        @keyframes progress-sweep {
            0%, 100% { transform: translateX(0); opacity: 0.45; }
            50% { transform: translateX(calc(100% - 0.75rem)); opacity: 1; }
        }

        @keyframes log-hover {
            0%, 100% {
                background: transparent;
                border-color: transparent;
                opacity: 0.68;
                transform: translateX(0);
            }
            12%, 28% {
                background: rgba(30, 41, 59, 0.55);
                border-color: rgba(34, 211, 238, 0.16);
                opacity: 1;
                transform: translateX(3px);
            }
        }

        .progress-indicator {
            animation: progress-sweep 2.4s ease-in-out infinite;
        }

        .log-line {
            border: 1px solid transparent;
            border-radius: 0.5rem;
            padding: 0.35rem 0.45rem;
            animation: log-hover 7.2s ease-in-out infinite;
        }

        @media (max-width: 640px) {
            body,
            nav,
            section,
            footer {
                width: 100vw;
                max-width: 100vw;
                overflow-x: hidden;
            }

            .max-w-7xl,
            .max-w-4xl {
                width: min(100vw, 390px) !important;
                max-width: min(100vw, 390px) !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
            }

            .mobile-killchain {
                position: relative;
                width: 100%;
                max-width: 100%;
                transform: none;
            }

            .mobile-killchain-wrap {
                height: 18rem;
                overflow: hidden;
            }
        }
    </style>
</head>
<body class="antialiased overflow-x-hidden">

    <!-- Navigation -->
    <nav class="fixed top-0 w-full z-50 glass-panel border-b border-slate-800/50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-gradient-to-br from-gold-400 to-gold-600 rounded-lg flex items-center justify-center">
                        <span class="text-obsidian font-bold text-lg">V</span>
                    </div>
                    <span class="font-semibold text-xl tracking-tight text-slate-100">VerifySky</span>
                </div>
                <div class="hidden md:flex items-center gap-8">
                    <a href="#superpowers" class="text-sm text-slate-400 hover:text-cyan-400 transition-colors">Platform</a>
                    <a href="#pricing" class="text-sm text-slate-400 hover:text-cyan-400 transition-colors">Pricing</a>
                </div>
                <a href="{{ route('register') }}" class="shrink-0 px-3 sm:px-5 py-2 bg-gold-500 hover:bg-gold-400 text-obsidian font-semibold text-xs sm:text-sm rounded-lg transition-all hover:shadow-lg hover:shadow-gold-500/20">
                    Create Account
                </a>
            </div>
        </div>
    </nav>

    <!-- Hero Section: The Timeline Network -->
    <section class="relative pt-32 pb-20 lg:pt-40 lg:pb-32 overflow-hidden">
        <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-slate-800/20 via-obsidian to-obsidian"></div>

        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-slate-800/50 border border-slate-700/50 text-xs font-medium text-cyan-400 mb-6">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    Now with Google Ads & AdSense Integration
                </div>
                <h1 class="text-[1.85rem] sm:text-4xl md:text-6xl lg:text-7xl font-bold tracking-tight text-slate-100 mb-6 leading-tight">
                    Stop Fake Clicks Before<br>
                    <span class="gradient-text">They Drain Your Budget</span>
                </h1>
                <p class="max-w-2xl mx-auto text-base sm:text-lg text-slate-400 mb-8 leading-relaxed">
                    Enterprise-grade ad protection and edge security powered by Cloudflare Workers.
                    Score risky traffic, catch proxy swarms, and keep fake clicks away from your server in under 15ms.
                </p>
                <div class="flex flex-col sm:flex-row gap-4 justify-center">
                    <a href="{{ route('register') }}" class="px-8 py-4 bg-gold-500 hover:bg-gold-400 text-obsidian font-bold rounded-lg transition-all hover:shadow-xl hover:shadow-gold-500/20 text-lg">
                        Start Defending
                    </a>
                    <a href="#superpowers" class="px-8 py-4 glass-panel hover:bg-slate-800/80 text-slate-300 font-semibold rounded-lg transition-all border border-slate-700">
                        Explore Platform
                    </a>
                </div>
            </div>

            <!-- Timeline Network Visualization -->
            <div class="relative mt-20 glass-panel rounded-2xl p-8 lg:p-12 border border-slate-800">
                <div class="absolute top-4 left-4 text-xs font-mono text-slate-500">LIVE REQUEST TELEMETRY</div>

                <div class="relative h-64 md:h-80 w-full mt-8">
                    <svg class="w-full h-full" viewBox="0 0 1000 300" preserveAspectRatio="xMidYMid meet">
                        <!-- Connection Lines -->
                        <line x1="100" y1="150" x2="350" y2="150" stroke="#334155" stroke-width="2" />
                        <line x1="350" y1="150" x2="650" y2="150" stroke="#334155" stroke-width="2" />
                        <line x1="650" y1="150" x2="900" y2="150" stroke="#334155" stroke-width="2" />

                        <!-- Animated Flow Lines -->
                        <line x1="100" y1="150" x2="350" y2="150" stroke="#06B6D4" stroke-width="2" class="topology-line" opacity="0.5" />
                        <line x1="350" y1="150" x2="650" y2="150" stroke="#10B981" stroke-width="2" class="topology-line" opacity="0.5" style="animation-delay: 1s" />
                        <line x1="650" y1="150" x2="900" y2="150" stroke="#10B981" stroke-width="2" class="topology-line" opacity="0.5" style="animation-delay: 2s" />

                        <!-- Node A: Client Request -->
                        <g transform="translate(100, 150)">
                            <circle r="40" fill="#151B2B" stroke="#334155" stroke-width="2" />
                            <image href="{{ $duotone('user-ninja.svg') }}" x="-23" y="-23" width="46" height="46" class="telemetry-node-icon tone-threat" preserveAspectRatio="xMidYMid meet" />
                            <text x="0" y="-55" text-anchor="middle" fill="#94A3B8" font-size="12" font-family="JetBrains Mono">0ms</text>
                            <text x="0" y="58" text-anchor="middle" fill="#E2E8F0" font-size="11" font-weight="600">CLIENT</text>
                            <text x="0" y="73" text-anchor="middle" fill="#64748B" font-size="9">Ads/Organic</text>
                        </g>

                        <!-- Node B: Edge Shield -->
                        <g transform="translate(350, 150)">
                            <circle r="50" fill="#151B2B" stroke="#F59E0B" stroke-width="2" class="gold-glow" />
                            <image href="{{ $duotone('brain-circuit.svg') }}" x="-28" y="-28" width="56" height="56" class="telemetry-node-icon tone-ai" preserveAspectRatio="xMidYMid meet" />
                            <text x="0" y="-65" text-anchor="middle" fill="#F59E0B" font-size="12" font-family="JetBrains Mono">12ms</text>
                            <text x="0" y="70" text-anchor="middle" fill="#FBBF24" font-size="12" font-weight="700">EDGE SHIELD</text>
                            <text x="0" y="87" text-anchor="middle" fill="#94A3B8" font-size="9">AI Scoring</text>
                        </g>

                        <!-- Node C: Decision -->
                        <g transform="translate(650, 150)">
                            <circle r="40" fill="#151B2B" stroke="#10B981" stroke-width="2" />
                            <image href="{{ $duotone('radar.svg') }}" x="-23" y="-23" width="46" height="46" class="telemetry-node-icon tone-detect" preserveAspectRatio="xMidYMid meet" />
                            <text x="0" y="-55" text-anchor="middle" fill="#34D399" font-size="12" font-family="JetBrains Mono">15ms</text>
                            <text x="0" y="58" text-anchor="middle" fill="#34D399" font-size="11" font-weight="600">DECISION</text>
                            <text x="0" y="73" text-anchor="middle" fill="#64748B" font-size="9">Pass/Block</text>
                        </g>

                        <!-- Node D: Server -->
                        <g transform="translate(900, 150)">
                            <circle r="40" fill="#151B2B" stroke="#06B6D4" stroke-width="2" />
                            <image href="{{ $duotone('server.svg') }}" x="-23" y="-23" width="46" height="46" class="telemetry-node-icon tone-protect" preserveAspectRatio="xMidYMid meet" />
                            <text x="0" y="-55" text-anchor="middle" fill="#22D3EE" font-size="12" font-family="JetBrains Mono">45ms</text>
                            <text x="0" y="58" text-anchor="middle" fill="#E2E8F0" font-size="11" font-weight="600">SERVER</text>
                            <text x="0" y="73" text-anchor="middle" fill="#64748B" font-size="9">Protected</text>
                        </g>

                        <!-- Animated Packets -->
                        <!-- Legitimate Traffic (Emerald) -->
                        <circle r="6" fill="#10B981" opacity="0.9">
                            <animateMotion dur="3s" repeatCount="indefinite" path="M 100 150 L 350 150 L 650 150 L 900 150" />
                        </circle>
                        <circle r="6" fill="#10B981" opacity="0.9">
                            <animateMotion dur="3s" begin="1s" repeatCount="indefinite" path="M 100 150 L 350 150 L 650 150 L 900 150" />
                        </circle>

                        <!-- Malicious Traffic (Coral) - Stops at Edge -->
                        <circle r="6" fill="#F97316" opacity="0.9">
                            <animateMotion dur="2s" repeatCount="indefinite" path="M 100 150 L 350 150" />
                            <animate attributeName="opacity" values="1;1;0" dur="2s" repeatCount="indefinite" />
                        </circle>
                        <circle r="6" fill="#EF4444" opacity="0.9">
                            <animateMotion dur="2.5s" begin="0.5s" repeatCount="indefinite" path="M 100 150 L 350 150" />
                            <animate attributeName="opacity" values="1;1;0" dur="2.5s" repeatCount="indefinite" />
                        </circle>
                    </svg>
                </div>

                <!-- Timeline Labels -->
                <div class="grid grid-cols-4 gap-4 mt-4 text-center">
                    <div class="text-xs text-slate-500 font-mono">Request Ingestion</div>
                    <div class="text-xs text-gold-400 font-mono">WAF + AI Analysis</div>
                    <div class="text-xs text-emerald-400 font-mono">Threat Decision</div>
                    <div class="text-xs text-cyan-400 font-mono">Clean Traffic</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Worker Intelligence Section -->
    <section id="superpowers" class="py-24 relative">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl md:text-4xl font-bold text-slate-100 mb-4">Worker Intelligence</h2>
                <p class="text-slate-400 max-w-3xl mx-auto">Real edge behavior pulled from the VerifySky Worker: fake-click detection, proxy pressure, human checks, signed sessions, and automatic rules before abuse reaches your server.</p>
            </div>

            <div id="features-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-5">
                <!-- Features injected via JavaScript -->
            </div>
        </div>
    </section>

    <!-- Live Decision Engine Section -->
    <section class="py-24 relative bg-slate-950/50 overflow-hidden">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                <div>
                    <h2 class="text-3xl md:text-4xl font-bold text-slate-100 mb-6">Live Decision Engine</h2>
                    <p class="text-slate-400 mb-8 leading-relaxed">
                        Every request moves through the Worker as a live verdict: pass trusted humans, trap suspicious traffic with telemetry, block confirmed abuse, and let AI deploy temporary WAF rules when attack patterns emerge.
                    </p>
                    <div id="decision-stages" class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-8">
                        <!-- Decision stages injected via JS -->
                    </div>

                    <div class="space-y-4" id="decision-steps">
                        <!-- Decision steps injected via JS -->
                    </div>
                </div>

                <div class="glass-panel rounded-2xl p-6 border border-slate-800 font-mono text-sm relative overflow-hidden h-96">
                    <div class="absolute top-4 right-4 flex items-center gap-2">
                        <div class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></div>
                        <span class="text-xs text-emerald-400">LIVE</span>
                    </div>

                    <div class="space-y-2 text-slate-400 mt-8" id="telemetry-stream">
                        <!-- Telemetry logs injected via JS -->
                    </div>

                    <div class="absolute bottom-0 left-0 right-0 h-32 bg-gradient-to-t from-slate-900 to-transparent pointer-events-none"></div>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section id="pricing" class="py-24 relative">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl md:text-4xl font-bold text-slate-100 mb-4">Enterprise-Grade Protection</h2>
                <p class="text-slate-400">Transparent pricing for teams of every size</p>
            </div>

            <div id="pricing-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <!-- Pricing cards injected via JavaScript -->
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-24 relative">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <div class="glass-panel rounded-3xl p-12 border border-gold-500/20 gold-glow">
                <h2 class="text-3xl md:text-5xl font-bold text-slate-100 mb-6">Ready to secure your ad spend?</h2>
                <p class="text-slate-400 mb-8 text-lg">Put VerifySky between your budget and every fake click, bot network, proxy swarm, and server abuse attempt.</p>
                <a href="{{ route('register') }}" class="inline-flex items-center gap-2 px-8 py-4 bg-gold-500 hover:bg-gold-400 text-obsidian font-bold rounded-lg transition-all hover:shadow-xl hover:shadow-gold-500/20 text-lg">
                    Create Account
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                    </svg>
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="border-t border-slate-800 py-12 bg-slate-950">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row justify-between items-center gap-6">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 bg-gradient-to-br from-gold-400 to-gold-600 rounded-lg flex items-center justify-center">
                    <span class="text-obsidian font-bold text-lg">V</span>
                </div>
                <span class="font-semibold text-xl text-slate-100">VerifySky</span>
            </div>
            <div class="text-slate-500 text-sm">
                © 2024 VerifySky. All rights reserved.
            </div>
        </div>
    </footer>

    <script>
        // Data Layer
        const registerUrl = @json(route('register'));

        const features = [
            {
                title: "Ad Click Spoofing Detection",
                description: "Flags paid-click URLs with missing referer, missing language headers, or bot-like context before fake clicks burn budget.",
                icon: "{{ $duotone('eye-evil.svg') }}",
                tone: "tone-threat",
                accent: "coral",
                motion: "threat",
                stat: "ad trackers watched"
            },
            {
                title: "Multi-Signal Risk Scoring",
                description: "Scores every request from Bot Management, User-Agent, ASN, TLS, geo, headers, historical fingerprint, and click context.",
                icon: "{{ $duotone('brain-circuit.svg') }}",
                tone: "tone-ai",
                accent: "gold",
                motion: "ai",
                stat: "0-100 edge verdict"
            },
            {
                title: "IP/Subnet/ASN Swarm Detection",
                description: "Catches rotating proxy swarms by correlating IP rate, /24 bursts, /16 pressure, ASN spikes, and UA entropy.",
                icon: "{{ $duotone('radar.svg') }}",
                tone: "tone-detect",
                accent: "cyan",
                motion: "detect",
                stat: "burst + sustained windows"
            },
            {
                title: "Path Pressure & Sensitive Routes",
                description: "Locks down high-value paths and detects attack pressure against a route globally or from a single ASN.",
                icon: "{{ $duotone('lock-keyhole.svg') }}",
                tone: "tone-protect",
                accent: "emerald",
                motion: "protect",
                stat: "/checkout /admin /api"
            },
            {
                title: "Dynamic Honeypot Decoys",
                description: "Daily decoy paths expose crawlers and scrapers that touch URLs normal customers should never visit.",
                icon: "{{ $duotone('spider-web.svg') }}",
                tone: "tone-threat",
                accent: "coral",
                motion: "threat",
                stat: "trap hits become signal"
            },
            {
                title: "Slider CAPTCHA + Human Telemetry",
                description: "Suspicious traffic must solve a signed slider while the Worker evaluates movement, velocity, pauses, and Turnstile proof.",
                icon: "{{ $duotone('waveform-lines.svg') }}",
                tone: "tone-detect",
                accent: "cyan",
                motion: "detect",
                stat: "mouse/touch analysis"
            },
            {
                title: "Signed Human Sessions",
                description: "Solved users receive a JWT session bound to IP and fingerprint, with replay and nonce checks around the challenge.",
                icon: "{{ $duotone('fingerprint.svg') }}",
                tone: "tone-protect",
                accent: "emerald",
                motion: "protect",
                stat: "IP + fingerprint bound"
            },
            {
                title: "AI WAF Rule Automation",
                description: "When severe logs cluster, AI reviews recent events and creates internal WAF rules for IPs, ASNs, or countries.",
                icon: "{{ $duotone('microchip-ai.svg') }}",
                tone: "tone-ai",
                accent: "gold",
                motion: "ai",
                stat: "auto-expiring rules"
            },
            {
                title: "Blocked IP List",
                description: "Repeat hard blocks, challenge abuse, and malicious signatures can be added to the blocked IP list.",
                icon: "{{ $duotone('ban-bug.svg') }}",
                tone: "tone-threat",
                accent: "coral",
                motion: "threat",
                stat: "hard_block memory"
            },
            {
                title: "Protected Session Metering",
                description: "Clean HTML sessions are metered with a signed cookie so revenue and protection usage stay attributable.",
                icon: "{{ $duotone('cloud-check.svg') }}",
                tone: "tone-protect",
                accent: "emerald",
                motion: "protect",
                stat: "protected session counted"
            }
        ];

        const decisionStages = [
            { label: "PASS", icon: "{{ $duotone('shield-check.svg') }}", tone: "tone-protect", className: "border-emerald-500/30 text-emerald-400" },
            { label: "CHALLENGE", icon: "{{ $duotone('radar.svg') }}", tone: "tone-detect", className: "border-cyan-500/30 text-cyan-400" },
            { label: "BLOCK", icon: "{{ $duotone('shield-xmark.svg') }}", tone: "tone-threat", className: "border-coral-500/30 text-coral-400" },
            { label: "AUTO_RULE", display: "AUTO RULE", icon: "{{ $duotone('siren-on.svg') }}", tone: "tone-ai", className: "border-gold-500/30 text-gold-400" },
            { label: "BLOCKED_IP", display: "BLOCKED IP", icon: "{{ $duotone('ban-bug.svg') }}", tone: "tone-threat", className: "border-coral-500/30 text-coral-400" }
        ];

        const decisionSteps = [
            { label: "Request Metadata", desc: "Domain config, IP, ASN, TLS, headers, and ad-click markers are extracted at the edge", time: "0ms", color: "text-cyan-400", bar: "w-1/4" },
            { label: "Risk Correlation", desc: "Bot score, fingerprint history, subnet bursts, path pressure, and honeypot hits become a 0-100 verdict", time: "3ms", color: "text-gold-400", bar: "w-3/4" },
            { label: "Challenge Trap", desc: "Suspicious traffic receives signed slider CAPTCHA, Turnstile, nonce, and telemetry validation", time: "8ms", color: "text-cyan-400", bar: "w-1/2" },
            { label: "Auto Rules", desc: "Confirmed abuse is blocked, logged, and eligible for automatic rules or the blocked IP list", time: "12ms", color: "text-coral-400", bar: "w-5/6" },
            { label: "Protected Server", desc: "Clean users continue with a signed session or trusted-IP fast path while your server stays protected", time: "15ms", color: "text-emerald-400", bar: "w-2/3" }
        ];

        const telemetryLogs = [
            { time: "14:23:01.042", type: "BLOCK", ip: "194.32.x.x", reason: "Blocked IP match + repeat abuse", region: "US-EAST" },
            { time: "14:23:01.045", type: "PASS", ip: "72.184.x.x", reason: "Signed human session accepted", region: "US-WEST" },
            { time: "14:23:01.048", type: "CHALLENGE", ip: "91.203.x.x", reason: "Subnet burst + missing browser headers", region: "EU-WEST" },
            { time: "14:23:01.052", type: "PASS", ip: "8.29.x.x", reason: "Google Ads click context verified", region: "US-CENTRAL" },
            { time: "14:23:01.055", type: "AUTO_RULE", ip: "103.5.x.x", reason: "AI found a repeated attack pattern", region: "APAC" },
            { time: "14:23:01.061", type: "BLOCKED_IP", ip: "185.71.x.x", reason: "Repeated challenge abuse", region: "EU-CENTRAL" }
        ];

        const pricingTiers = [
            {
                name: "Free",
                price: "$0",
                period: "/month",
                description: "For new teams validating protection",
                features: ["10K Protected Sessions", "25K Bot Requests", "5 Edge Rules", "1 Workspace", "Email Support"],
                cta: "Create Account",
                highlighted: false
            },
            {
                name: "Growth",
                price: "$149",
                period: "/month",
                description: "For growing businesses",
                features: ["500K Protected Sessions", "2M Bot Requests", "20 Edge Rules", "3 Workspaces", "Priority Support", "Google Ads Integration"],
                cta: "Create Account",
                highlighted: false
            },
            {
                name: "Pro",
                price: "$499",
                period: "/month",
                description: "Recommended defense posture",
                features: ["2M Protected Sessions", "10M Bot Requests", "Unlimited Edge Rules", "10 Workspaces", "Dedicated Support", "Custom CAPTCHA", "AI WAF Automation", "Blocked IP List"],
                cta: "Create Account",
                highlighted: true
            },
            {
                name: "Business",
                price: "Custom",
                period: "",
                description: "For large organizations",
                features: ["Unlimited Sessions", "Unlimited Requests", "Custom Edge Rules", "Unlimited Workspaces", "SLA Guarantee", "Dedicated Infrastructure", "White-label Options"],
                cta: "Contact Sales",
                highlighted: false
            }
        ];

        // Render Functions
        const accentStyles = {
            coral: {
                border: "border-coral-500/30",
                text: "text-coral-400",
                glow: "threat-glow",
                line: "from-coral-500 to-red-500"
            },
            cyan: {
                border: "border-cyan-500/30",
                text: "text-cyan-400",
                glow: "detect-glow",
                line: "from-cyan-400 to-cyan-500"
            },
            gold: {
                border: "border-gold-500/30",
                text: "text-gold-400",
                glow: "ai-glow",
                line: "from-gold-400 to-gold-600"
            },
            emerald: {
                border: "border-emerald-500/30",
                text: "text-emerald-400",
                glow: "protect-glow",
                line: "from-emerald-400 to-emerald-500"
            }
        };

        function renderFeatures() {
            const container = document.getElementById("features-grid");
            container.innerHTML = features.map(feature => `
                <div class="feature-card group p-5 rounded-xl bg-slate-900/50 border ${accentStyles[feature.accent].border} hover:bg-slate-800/50 transition-all duration-300 ${accentStyles[feature.accent].glow}" data-motion="${feature.motion}">
                    <div class="icon-tile mb-4 group-hover:scale-110 transition-transform">
                        <img src="${feature.icon}" alt="" class="${feature.tone}">
                    </div>
                    <div class="h-1 w-full bg-slate-800 rounded-full mb-4 overflow-hidden">
                        <div class="scan-line h-full rounded-full bg-gradient-to-r ${accentStyles[feature.accent].line}" style="width: 58%"></div>
                    </div>
                    <h3 class="text-base font-semibold text-slate-100 mb-2 leading-tight">${feature.title}</h3>
                    <p class="text-sm text-slate-400 leading-relaxed">${feature.description}</p>
                    <div class="mt-4 text-[11px] font-mono uppercase tracking-wider ${accentStyles[feature.accent].text}">${feature.stat}</div>
                </div>
            `).join("");
        }

        function renderDecisionStages() {
            const container = document.getElementById("decision-stages");
            container.innerHTML = decisionStages.map(stage => `
                <div class="rounded-xl border ${stage.className} bg-slate-900/50 p-3 text-center">
                    <div class="mx-auto mb-2 icon-tile !w-10 !h-10">
                        <img src="${stage.icon}" alt="" class="${stage.tone} !w-5 !h-5">
                    </div>
                    <div class="text-[10px] font-mono font-semibold leading-tight">${stage.display || stage.label}</div>
                </div>
            `).join("");
        }

        function renderDecisionSteps() {
            const container = document.getElementById("decision-steps");
            container.innerHTML = decisionSteps.map((step, index) => `
                <div class="p-4 rounded-lg bg-slate-900/30 border border-slate-800 hover:border-slate-700 transition-colors">
                    <div class="flex items-center gap-4">
                    <div class="w-12 text-right font-mono text-xs text-slate-500">${step.time}</div>
                    <div class="flex-1">
                        <div class="font-medium text-slate-200">${step.label}</div>
                        <div class="text-sm text-slate-500">${step.desc}</div>
                    </div>
                    <div class="w-2 h-2 rounded-full ${step.color.replace('text', 'bg')} animate-pulse"></div>
                    </div>
                    <div class="mt-3 ml-16 h-1.5 bg-slate-800 rounded-full overflow-hidden">
                        <div class="h-full rounded-full ${step.color.replace('text', 'bg')} ${step.bar}" style="animation: riskbar ${2.2 + index * 0.18}s ease-in-out infinite"></div>
                        <div class="progress-indicator -mt-1.5 h-1.5 w-3 rounded-full bg-white/80 shadow-[0_0_12px_rgba(255,255,255,0.6)]" style="animation-delay: ${index * 0.2}s"></div>
                    </div>
                </div>
            `).join("");
        }

        function renderTelemetry() {
            const container = document.getElementById("telemetry-stream");
            const logs = [...telemetryLogs, ...telemetryLogs]; // Duplicate for scroll effect

            container.innerHTML = `<div class="telemetry-roll space-y-1.5">${logs.map((log, index) => `
                <div class="log-line flex items-center gap-3 text-xs opacity-80 hover:opacity-100 transition-opacity min-w-0" style="animation-delay: ${index * 0.55}s">
                    <span class="text-slate-600 w-20">${log.time}</span>
                    <span class="${log.type === 'BLOCK' || log.type === 'BLOCKED_IP' ? 'text-red-400' : log.type === 'PASS' ? 'text-emerald-400' : log.type === 'CHALLENGE' ? 'text-cyan-400' : 'text-gold-400'} w-28 font-semibold truncate">${log.type}</span>
                    <span class="text-slate-500 w-24">${log.ip}</span>
                    <span class="text-slate-400 flex-1 truncate">${log.reason}</span>
                    <span class="text-slate-600">${log.region}</span>
                </div>
            `).join("")}</div>`;
        }

        function renderPricing() {
            const container = document.getElementById("pricing-grid");
            container.innerHTML = pricingTiers.map(tier => `
                <div class="relative p-6 rounded-2xl ${tier.highlighted ? 'bg-slate-900 border-2 border-gold-500/50 gold-glow' : 'bg-slate-900/50 border border-slate-800'} flex flex-col">
                    ${tier.highlighted ? '<div class="absolute -top-3 left-1/2 transform -translate-x-1/2 px-3 py-1 bg-gold-500 text-obsidian text-xs font-bold rounded-full">RECOMMENDED</div>' : ''}
                    <div class="mb-4">
                        <h3 class="text-lg font-semibold text-slate-100">${tier.name}</h3>
                        <p class="text-sm text-slate-500 mt-1">${tier.description}</p>
                    </div>
                    <div class="mb-6">
                        <span class="text-4xl font-bold text-slate-100">${tier.price}</span>
                        <span class="text-slate-500">${tier.period}</span>
                    </div>
                    <ul class="space-y-3 mb-8 flex-1">
                        ${tier.features.map(feature => `
                            <li class="flex items-start gap-2 text-sm text-slate-400">
                                <svg class="w-5 h-5 text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                ${feature}
                            </li>
                        `).join("")}
                    </ul>
                    <a href="${registerUrl}" class="w-full py-3 text-center rounded-lg ${tier.highlighted ? 'bg-gold-500 hover:bg-gold-400 text-obsidian font-semibold' : 'bg-slate-800 hover:bg-slate-700 text-slate-200'} transition-all">
                        ${tier.cta}
                    </a>
                </div>
            `).join("");
        }

        // Initialize
        document.addEventListener("DOMContentLoaded", () => {
            renderFeatures();
            renderDecisionStages();
            renderDecisionSteps();
            renderTelemetry();
            renderPricing();

            // Smooth scroll for anchor links
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            });
        });
    </script>
</body>
</html>
