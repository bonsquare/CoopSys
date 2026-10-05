<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create your organization · CoopSys</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@100..900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="{{ asset('assets/js/tailwind-config.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
</head>
<body class="bg-surface min-h-screen flex items-center justify-center px-4 py-8 antialiased">

    <div class="w-full max-w-md">
        <div class="flex items-center justify-center gap-2 mb-6">
            <span class="material-symbols-outlined text-primary text-[36px]">diversity_3</span>
            <div>
                <div class="text-xl font-bold text-primary leading-tight">CoopSys</div>
                <div class="text-[11px] uppercase tracking-wider text-on-surface-variant">Management Portal</div>
            </div>
        </div>

        <form method="POST" action="{{ route('register.store') }}" class="app-card bg-surface-container-lowest rounded-xl p-6 flex flex-col gap-4">
            @csrf
            <div>
                <h1 class="text-lg font-semibold text-on-surface">Create your organization</h1>
                <p class="text-sm text-on-surface-variant">Start a free {{ config('coop.trial_days') }}-day trial. You get your own private books, members and loans, and a unique Organization ID.</p>
            </div>

            <x-field label="Organization name" name="organization_name" required autofocus hint="Your cooperative, lending company or association." />
            <x-field label="Organization email" name="organization_email" type="email" hint="Optional. Defaults to your email below." />

            <div class="border-t border-outline-variant pt-3 text-sm font-semibold text-on-surface">Administrator account</div>
            <x-field label="Your name" name="name" required autocomplete="name" />
            <x-field label="Your email" name="email" type="email" required autocomplete="username" />
            <x-field label="Password" name="password" type="password" required autocomplete="new-password" hint="At least 8 characters." />
            <x-field label="Confirm password" name="password_confirmation" type="password" required autocomplete="new-password" />

            <label class="flex items-start gap-2 text-sm text-on-surface-variant">
                <input type="checkbox" name="terms" value="1" class="mt-1" @checked(old('terms'))>
                <span>I agree to the terms of use and privacy policy.</span>
            </label>
            @error('terms') <p class="field-error">{{ $message }}</p> @enderror

            <button type="submit" class="btn btn-primary justify-center">Create organization</button>
            <p class="text-sm text-center text-on-surface-variant">Already have an Organization ID? <a href="{{ route('login') }}" class="text-primary font-semibold">Sign in</a></p>
        </form>
    </div>

</body>
</html>
