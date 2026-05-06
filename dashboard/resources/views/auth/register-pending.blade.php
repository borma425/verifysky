<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
  <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
  <title>Check your email - VerifySky</title>
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-[#171C26] text-[#D7E1F5]">
  <main class="mx-auto grid min-h-screen max-w-3xl place-items-center px-4">
    <section class="w-full rounded-lg border border-white/10 bg-[#202632] p-8 text-center shadow-2xl">
      <img src="{{ asset('Logo.png') }}" alt="VerifySky" class="mx-auto mb-5 h-auto w-24 object-contain">
      <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[#FCB900]">Email activation required</p>
      <h1 class="mt-3 text-3xl font-bold text-white">Check your email</h1>
      <p class="mx-auto mt-4 max-w-xl text-sm leading-7 text-[#AEB9CC]">
        Your account has been created. We sent your login details and activation link to your email address. Open the link once to activate your account, then sign in from your private login path.
      </p>
      <a href="{{ route('home') }}" class="mt-7 inline-flex rounded-lg bg-[#FCB900] px-5 py-3 text-sm font-bold text-[#171C26] transition hover:brightness-110">
        Return to VerifySky
      </a>
    </section>
  </main>
</body>
</html>
