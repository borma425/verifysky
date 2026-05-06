<!DOCTYPE html>
<html lang="en">
<head>
    <base target="_self">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - VerifySky</title>
    <meta name="description" content="Create your protected account with VerifySky. Simple setup for secure traffic management.">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    },
                    colors: {
                        vs: {
                            bg: '#0E131D',
                            panel: '#151B26',
                            border: '#202632',
                            borderLight: '#303540',
                            gold: '#FCB900',
                            sky: '#38BDF8',
                            success: '#10B981',
                            muted: '#AEB9CC',
                            error: '#FB7185',
                        }
                    },
                    animation: {
                        'threat-drift': 'threat-drift 18s linear infinite',
                        'packet-east': 'packet-east 5s linear infinite',
                        'packet-west': 'packet-west 6s linear infinite',
                        'packet-south': 'packet-south 7s linear infinite',
                        'scan': 'scan 5s ease-in-out infinite',
                        'pulse-slow': 'pulse-glow 4s ease-in-out infinite',
                    },
                    keyframes: {
                        'threat-drift': {
                            '0%': { transform: 'translate3d(0, 0, 0)' },
                            '100%': { transform: 'translate3d(-220px, 160px, 0)' },
                        },
                        'packet-east': {
                            '0%': { transform: 'translateX(-12vw)', opacity: '0' },
                            '12%': { opacity: '1' },
                            '88%': { opacity: '1' },
                            '100%': { transform: 'translateX(112vw)', opacity: '0' },
                        },
                        'packet-west': {
                            '0%': { transform: 'translateX(112vw)', opacity: '0' },
                            '12%': { opacity: '1' },
                            '88%': { opacity: '1' },
                            '100%': { transform: 'translateX(-12vw)', opacity: '0' },
                        },
                        'packet-south': {
                            '0%': { transform: 'translateY(-12vh)', opacity: '0' },
                            '15%': { opacity: '1' },
                            '85%': { opacity: '1' },
                            '100%': { transform: 'translateY(112vh)', opacity: '0' },
                        },
                        'scan': {
                            '0%, 100%': { transform: 'translateY(-18%)', opacity: '0.12' },
                            '50%': { transform: 'translateY(18%)', opacity: '0.28' },
                        },
                        'pulse-glow': {
                            '0%, 100%': { boxShadow: '0 0 24px rgba(252, 185, 0, 0.24), 0 0 60px rgba(56, 189, 248, 0.10)' },
                            '50%': { boxShadow: '0 0 34px rgba(252, 185, 0, 0.42), 0 0 86px rgba(56, 189, 248, 0.18)' },
                        },
                    }
                }
            }
        }
    </script>

    <style>
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #0E131D;
        }

        ::-webkit-scrollbar-thumb {
            background: #303540;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #FCB900;
        }

        .security-grid {
            background-image:
                linear-gradient(rgba(48, 53, 64, 0.28) 1px, transparent 1px),
                linear-gradient(90deg, rgba(48, 53, 64, 0.22) 1px, transparent 1px);
            background-size: 48px 48px;
            mask-image: radial-gradient(circle at center, black 0%, black 52%, transparent 82%);
        }

        .threat-text {
            text-shadow: 0 0 22px rgba(56, 189, 248, 0.28);
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus {
            -webkit-text-fill-color: #AEB9CC;
            -webkit-box-shadow: 0 0 0px 1000px #0E131D inset;
            transition: background-color 5000s ease-in-out 0s;
        }
    </style>
</head>
<body class="min-h-screen bg-vs-bg text-vs-muted font-sans antialiased selection:bg-vs-gold selection:text-vs-bg">
    <main class="relative min-h-screen overflow-hidden px-5 py-8 sm:px-6 lg:px-8">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_50%_30%,rgba(56,189,248,0.18),transparent_34%),radial-gradient(circle_at_18%_80%,rgba(252,185,0,0.12),transparent_28%),linear-gradient(135deg,#0E131D_0%,#151B26_46%,#0E131D_100%)]"></div>
        <div class="security-grid absolute inset-0 opacity-60"></div>
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
            <div class="absolute left-0 top-[14%] h-px w-32 bg-gradient-to-r from-transparent via-vs-sky to-transparent animate-packet-east"></div>
            <div class="absolute left-0 top-[35%] h-px w-40 bg-gradient-to-r from-transparent via-vs-gold to-transparent animate-packet-east" style="animation-delay: 1.2s;"></div>
            <div class="absolute left-0 top-[72%] h-px w-28 bg-gradient-to-r from-transparent via-white to-transparent animate-packet-east" style="animation-delay: 2.1s;"></div>
            <div class="absolute left-0 top-[52%] h-px w-36 bg-gradient-to-r from-transparent via-vs-sky to-transparent animate-packet-west" style="animation-delay: 0.7s;"></div>
            <div class="absolute left-[16%] top-0 h-32 w-px bg-gradient-to-b from-transparent via-vs-gold to-transparent animate-packet-south" style="animation-delay: 1.6s;"></div>
            <div class="absolute left-[81%] top-0 h-40 w-px bg-gradient-to-b from-transparent via-vs-sky to-transparent animate-packet-south" style="animation-delay: 2.8s;"></div>
        </div>
        <div class="pointer-events-none absolute -left-20 -top-16 grid grid-cols-4 gap-8 text-xs font-mono uppercase tracking-[0.22em] text-vs-muted/10 animate-threat-drift">
            <span class="threat-text">403</span>
            <span class="threat-text text-vs-sky/20">XSS</span>
            <span class="threat-text">SQLi</span>
            <span class="threat-text text-vs-gold/20">TLS</span>
            <span class="threat-text">&lt;/&gt;</span>
            <span class="threat-text">WAF</span>
            <span class="threat-text text-vs-sky/20">BOT</span>
            <span class="threat-text">JWT</span>
            <span class="threat-text">403</span>
            <span class="threat-text text-vs-gold/20">SCAN</span>
            <span class="threat-text">{ }</span>
            <span class="threat-text">DDoS</span>
        </div>
        <div class="pointer-events-none absolute inset-x-0 top-1/2 h-40 -translate-y-1/2 bg-gradient-to-b from-transparent via-vs-sky/10 to-transparent blur-sm animate-scan"></div>

        <section class="relative z-10 flex min-h-[calc(100vh-4rem)] items-center justify-center">
            <div class="w-full max-w-md">
                <a href="{{ route('home') }}" class="mx-auto mb-7 inline-flex w-full items-center justify-center gap-3 group">
                    <img src="{{ asset('Logo.png') }}" alt="VerifySky" class="h-12 w-12 object-contain transition-transform duration-300 group-hover:scale-105">
                    <span class="text-white font-semibold text-xl tracking-tight">VerifySky</span>
                </a>

                <!-- Registration Card -->
                <div class="relative animate-pulse-slow rounded-lg">
                    <div class="absolute -top-px left-0 right-0 h-1 bg-gradient-to-r from-vs-gold via-vs-sky to-vs-gold rounded-t-lg opacity-80"></div>

                    <div class="relative overflow-hidden bg-vs-panel/95 border border-vs-border rounded-lg shadow-2xl shadow-black/50 p-8 lg:p-10 backdrop-blur-xl">
                        <div class="pointer-events-none absolute inset-x-0 top-0 h-24 bg-gradient-to-b from-white/[0.04] to-transparent"></div>

                        <!-- Card Header -->
                        <div class="relative mb-8">
                            <div class="inline-flex items-center gap-2 rounded-full border border-vs-borderLight bg-vs-bg/70 px-3 py-1 text-xs font-semibold text-vs-gold uppercase tracking-wider mb-4">
                                <span class="h-2 w-2 rounded-full bg-vs-success animate-pulse"></span>
                                Create Account
                            </div>
                            <h1 class="text-2xl font-bold text-white mb-2">Start defending your traffic.</h1>
                            <p class="text-sm text-vs-muted">Your first workspace starts on the Starter plan. Billing can be managed after onboarding.</p>
                        </div>

                        <!-- Form -->
                        <form method="POST" action="{{ route('register.store') }}" class="relative space-y-5" autocomplete="off">
                            @csrf

                            <!-- Full Name -->
                            <div>
                                <label for="name" class="block text-xs font-medium text-vs-muted uppercase tracking-wider mb-2">Full name</label>
                                <input
                                    type="text"
                                    id="name"
                                    name="name"
                                    value="{{ old('name') }}"
                                    autocomplete="name"
                                    required
                                    class="w-full px-4 py-3 rounded-lg bg-vs-bg border border-vs-borderLight text-white placeholder-vs-muted/50 focus:border-vs-gold focus:ring-1 focus:ring-vs-gold focus:outline-none transition-all duration-200 hover:border-vs-border"
                                    placeholder="Enter your full name"
                                >
                                <!-- Validation Error Slot: Name -->
                                <div class="mt-2 text-sm text-vs-error @error('name') @else hidden @enderror" id="error-name" role="alert">
                                    <span class="flex items-center gap-1">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                        <span class="error-message">@error('name'){{ $message }}@else Please enter your full name.@enderror</span>
                                    </span>
                                </div>
                            </div>

                            <!-- Work Email -->
                            <div>
                                <label for="email" class="block text-xs font-medium text-vs-muted uppercase tracking-wider mb-2">Work email</label>
                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    value="{{ old('email') }}"
                                    autocomplete="email"
                                    required
                                    class="w-full px-4 py-3 rounded-lg bg-vs-bg border border-vs-borderLight text-white placeholder-vs-muted/50 focus:border-vs-gold focus:ring-1 focus:ring-vs-gold focus:outline-none transition-all duration-200 hover:border-vs-border"
                                    placeholder="you@company.com"
                                >
                                <!-- Validation Error Slot: Email -->
                                <div class="mt-2 text-sm text-vs-error @error('email') @else hidden @enderror" id="error-email" role="alert">
                                    <span class="flex items-center gap-1">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                        <span class="error-message">@error('email'){{ $message }}@else Please enter a valid email address.@enderror</span>
                                    </span>
                                </div>
                            </div>

                            <!-- Workspace Name -->
                            <div>
                                <label for="workspace_name" class="block text-xs font-medium text-vs-muted uppercase tracking-wider mb-2">Workspace / company name</label>
                                <input
                                    type="text"
                                    id="workspace_name"
                                    name="workspace_name"
                                    value="{{ old('workspace_name') }}"
                                    autocomplete="organization"
                                    required
                                    class="w-full px-4 py-3 rounded-lg bg-vs-bg border border-vs-borderLight text-white placeholder-vs-muted/50 focus:border-vs-gold focus:ring-1 focus:ring-vs-gold focus:outline-none transition-all duration-200 hover:border-vs-border"
                                    placeholder="Acme Corp"
                                >
                                <!-- Validation Error Slot: Workspace -->
                                <div class="mt-2 text-sm text-vs-error @error('workspace_name') @else hidden @enderror" id="error-workspace_name" role="alert">
                                    <span class="flex items-center gap-1">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                        <span class="error-message">@error('workspace_name'){{ $message }}@else Workspace name is required.@enderror</span>
                                    </span>
                                </div>
                            </div>

                            <!-- Password Fields Grid -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <!-- Password -->
                                <div>
                                    <label for="password" class="block text-xs font-medium text-vs-muted uppercase tracking-wider mb-2">Password</label>
                                    <input
                                        type="password"
                                        id="password"
                                        name="password"
                                        autocomplete="new-password"
                                        required
                                        class="w-full px-4 py-3 rounded-lg bg-vs-bg border border-vs-borderLight text-white placeholder-vs-muted/50 focus:border-vs-gold focus:ring-1 focus:ring-vs-gold focus:outline-none transition-all duration-200 hover:border-vs-border"
                                        placeholder="••••••••"
                                    >
                                </div>

                                <!-- Confirm Password -->
                                <div>
                                    <label for="password_confirmation" class="block text-xs font-medium text-vs-muted uppercase tracking-wider mb-2">Confirm password</label>
                                    <input
                                        type="password"
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        autocomplete="new-password"
                                        required
                                        class="w-full px-4 py-3 rounded-lg bg-vs-bg border border-vs-borderLight text-white placeholder-vs-muted/50 focus:border-vs-gold focus:ring-1 focus:ring-vs-gold focus:outline-none transition-all duration-200 hover:border-vs-border"
                                        placeholder="••••••••"
                                    >
                                </div>
                            </div>

                            <!-- Validation Error Slot: Password (covers both) -->
                            <div class="text-sm text-vs-error @error('password') @else hidden @enderror" id="error-password" role="alert">
                                <span class="flex items-center gap-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    <span class="error-message">@error('password'){{ $message }}@else Password must be at least 8 characters.@enderror</span>
                                </span>
                            </div>

                            <!-- Submit Button -->
                            <div class="pt-2">
                                <button
                                    type="submit"
                                    class="w-full py-3.5 px-4 rounded-lg bg-vs-gold text-vs-bg font-semibold text-sm uppercase tracking-wider hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-vs-gold focus:ring-offset-2 focus:ring-offset-vs-panel transition-all duration-200 shadow-lg shadow-vs-gold/20 active:transform active:scale-[0.98]"
                                >
                                    Create Account
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- JavaScript for interactions -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const inputs = document.querySelectorAll('input');

            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.classList.add('focused');
                });

                input.addEventListener('blur', function() {
                    this.parentElement.classList.remove('focused');
                });
            });
        });
    </script>
</body>
</html>
