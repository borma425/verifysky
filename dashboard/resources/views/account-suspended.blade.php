<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Account Suspended | VerifySky</title>
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="es-body">
  <main class="flex min-h-screen items-center justify-center px-4">
    <div class="es-card max-w-xl p-8 text-center">
      <img src="{{ asset('Logo.png') }}" alt="VerifySky" class="mx-auto mb-6 h-12 w-auto">
      <h1 class="es-title text-2xl">Your account has been suspended</h1>
      <p class="mt-4 text-sm leading-7 text-sky-100/75">
        You cannot access the dashboard right now. Please contact VerifySky support to reactivate your account.
      </p>
      <form method="POST" action="{{ route('logout') }}" class="mt-6">
        @csrf
        <button class="es-btn" type="submit">Log out</button>
      </form>
    </div>
  </main>
</body>
</html>
