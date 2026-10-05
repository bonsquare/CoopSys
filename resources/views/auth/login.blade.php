<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in · CoopSys</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@100..900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="{{ asset('assets/js/tailwind-config.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
</head>
<body class="bg-surface min-h-screen flex items-center justify-center px-4 antialiased">

    <div class="w-full max-w-sm">
        <div class="flex items-center justify-center gap-2 mb-6">
            <span class="material-symbols-outlined text-primary text-[36px]">diversity_3</span>
            <div>
                <div class="text-xl font-bold text-primary leading-tight">CoopSys</div>
                <div class="text-[11px] uppercase tracking-wider text-on-surface-variant">Management Portal</div>
            </div>
        </div>

        <form method="POST" action="{{ route('login.attempt') }}" class="app-card bg-surface-container-lowest rounded-xl p-6 flex flex-col gap-4">
            @csrf
            <h1 class="text-lg font-semibold text-on-surface">Sign in</h1>

            @error('email')
                <div class="bg-error-container text-on-error-container text-sm px-3 py-2 rounded-lg">{{ $message }}</div>
            @enderror

            <x-field label="Email" name="email" type="email" required autofocus autocomplete="username" />
            <x-field label="Password" name="password" type="password" required autocomplete="current-password" />

            <label class="flex items-center gap-2 text-sm text-on-surface-variant">
                <input type="checkbox" name="remember" value="1"> Remember me
            </label>

            <button type="submit" class="btn btn-primary justify-center">Sign in</button>
        </form>
    </div>

</body>
</html>
