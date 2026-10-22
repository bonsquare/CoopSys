<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'CoopSys') · CoopSys</title>

    <!-- Fonts and icons (Google Fonts CDN) -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@100..900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">

    <!-- Tailwind CSS via CDN + our theme settings -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="{{ asset('assets/js/tailwind-config.js') }}"></script>

    <!-- Our own CSS file -->
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
</head>
<body class="bg-surface font-body-md text-on-surface antialiased">
<a href="#main" class="skip-link">Skip to main content</a>

@php
    $user = auth()->user();

    // Sidebar menu: [label, icon, route name, module needed to see it]
    $menu = [
        ['Dashboard',          'grid_view',            'dashboard',               'dashboard'],
        ['Collections (Teller)', 'point_of_sale',     'collections.create',      'collections'],
        ['Member Management',  'group',                'members.index',           'members'],
        ['Share Capital',      'pie_chart',            'share.index',             'share'],
        ['Deposits & Withdrawals', 'savings',              'savings.index',           'savings'],
        ['Loan Management',    'account_balance',      'loans.index',             'loans'],
        ['Loan Products',      'tune',                 'loan-products.index',     'loan_products', true],
        ['Cash Receipts',      'receipt_long',         'receipts.index',          'receipts'],
        ['Cash Disbursements', 'payments',             'disbursements.index',     'disbursements'],
        ['Transaction Ledger', 'list_alt',             'transactions.index',      'ledger'],
        ['Accounting',         'menu_book',            'accounting.index',        'accounting'],
        ['Reports',            'analytics',            'reports.index',           'reports'],
        ['User Management',    'admin_panel_settings', 'users.index',             'users'],
        ['Settings',           'settings',             'settings.edit',           'settings'],
    ];
    $coopName = \App\Models\Setting::get('coop_name');
    $branch = \App\Models\Setting::get('branch_name');
    $org = \App\Support\Tenant::current();
@endphp

<!-- Sidebar -->
<!-- Dark overlay behind the menu on phones -->
<div id="sidebar-overlay" class="fixed inset-0 bg-black/30 z-40 hidden lg:hidden"></div>

<aside id="sidebar" class="fixed left-0 top-0 h-full w-64 bg-surface-container-lowest shadow-[0_1px_8px_rgba(0,0,0,0.04)] z-50 flex flex-col justify-between overflow-y-auto -translate-x-full lg:translate-x-0 transition-transform duration-200">
    <div class="flex flex-col">
        <div class="h-16 px-space-md flex items-center gap-space-sm">
            <span class="material-symbols-outlined text-primary text-[28px]">diversity_3</span>
            <div class="flex flex-col min-w-0">
                <span class="font-headline-sm text-headline-sm text-primary tracking-tight truncate">CoopSys</span>
                <span class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider truncate">Management Portal</span>
            </div>
        </div>

        <div class="px-space-md py-space-xs">
            <span class="font-label-sm text-label-sm text-outline uppercase tracking-wider">Operational Modules</span>
        </div>

        <nav aria-label="Main menu" class="flex flex-col gap-space-xs px-space-xs pb-space-lg">
            @foreach ($menu as $item)
                @php [$label, $icon, $routeName, $module] = $item; $isChild = $item[4] ?? false; @endphp
                @if ($user->canAccess($module))
                    @php $active = request()->routeIs(explode('.', $routeName)[0] . '*'); @endphp
                    @php $target = ($module === 'collections' && ! $user->canAccess('collections', 'manage')) ? 'collections.index' : $routeName; @endphp
                    <a href="{{ route($target) }}"
                       @if ($active) aria-current="page" @endif
                       class="flex items-center gap-space-sm px-space-md py-space-sm rounded-lg transition-colors {{ $isChild ? 'ml-6 text-[13px]' : '' }} {{ $active ? 'bg-surface-container-high text-primary font-semibold' : 'text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface' }}">
                        <span aria-hidden="true" class="material-symbols-outlined {{ $isChild ? 'text-[18px]' : 'text-[20px]' }}">{{ $icon }}</span>
                        <span class="font-body-md text-body-md">{{ $label }}</span>
                    </a>
                @endif
            @endforeach
        </nav>
    </div>

    <div class="p-space-md bg-surface-container-low m-space-xs rounded-xl flex items-center justify-between">
        <div class="flex flex-col">
            <span class="font-label-sm text-label-sm text-on-surface-variant">Status</span>
            <span class="font-label-md text-label-md text-secondary font-semibold flex items-center gap-space-xs">
                <span class="w-2 h-2 rounded-full bg-secondary inline-block"></span>Audit Active
            </span>
        </div>
        <span class="material-symbols-outlined text-outline text-[18px]">verified_user</span>
    </div>
</aside>

<div class="lg:pl-64">
    <!-- Top header -->
    <header class="fixed top-0 left-0 lg:left-64 right-0 h-16 bg-surface/90 backdrop-blur-xl shadow-[0_1px_8px_rgba(0,0,0,0.04)] z-40 px-space-lg flex items-center justify-between">
        <div class="flex items-center gap-space-sm min-w-0">
            <button type="button" id="menu-btn" class="lg:hidden p-1 rounded hover:bg-surface-container-highest text-on-surface" aria-label="Open menu">
                <span class="material-symbols-outlined">menu</span>
            </button>
            <div class="hidden sm:flex items-center gap-space-xs font-label-md text-label-md text-on-surface-variant">
                <span class="text-outline truncate">{{ $coopName }}</span>
                @if ($org)<span class="font-mono text-xs px-1.5 py-0.5 rounded bg-surface-container-high text-primary font-semibold" title="Organization ID">{{ $org->code }}</span>@endif
            </div>
            <div class="h-4 w-px bg-outline-variant hidden lg:block"></div>
            <div class="hidden lg:flex items-center gap-space-xs bg-surface-container-lowest px-space-sm py-space-xs rounded-lg shadow-[0_1px_8px_rgba(0,0,0,0.04)]">
                <span class="material-symbols-outlined text-[18px] text-primary">domain</span>
                <span class="font-label-md text-label-md text-on-surface font-medium">{{ $branch }}</span>
            </div>
        </div>

        <div class="flex items-center gap-space-md">
            <div class="hidden md:flex items-center gap-space-xs bg-surface-container-low px-space-sm py-space-xs rounded-lg">
                <span class="material-symbols-outlined text-[18px] text-secondary">calendar_today</span>
                <span class="font-label-sm text-label-sm text-on-surface-variant font-medium">
                    Business Date: <strong class="text-on-surface font-semibold">{{ now()->format('M d, Y') }}</strong>
                </span>
            </div>

            <div class="hidden sm:flex items-center gap-space-xs bg-surface-container-highest px-space-sm py-space-xs rounded-lg">
                <span class="material-symbols-outlined text-[16px] text-primary">shield_person</span>
                <span class="font-label-sm text-label-sm text-primary uppercase font-bold tracking-wider">Role:</span>
                <span class="font-label-sm text-label-sm font-semibold text-on-surface">{{ $user->roleLabel() }}</span>
            </div>

            <div class="h-6 w-px bg-outline-variant hidden sm:block"></div>

            <div class="flex items-center gap-space-sm">
                <a href="{{ route('account.edit') }}" class="flex items-center gap-space-sm" title="My account">
                    <div class="hidden sm:flex flex-col text-right">
                        <span class="font-label-md text-label-md text-on-surface font-semibold leading-tight">{{ $user->name }}</span>
                        <span class="font-body-sm text-body-sm text-on-surface-variant leading-tight">{{ $user->email }}</span>
                    </div>
                    <span class="w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center font-label-md text-label-md font-semibold">{{ $user->initials() }}</span>
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" title="Sign out" class="p-1 rounded hover:bg-surface-container-highest text-on-surface-variant">
                        <span class="material-symbols-outlined text-[20px]">logout</span>
                    </button>
                </form>
            </div>
        </div>
    </header>

    <!-- Page content goes here -->
    <main id="main" tabindex="-1" class="relative pt-16 w-full bg-surface min-h-screen">
        <div class="px-margin-lg py-gutter-lg flex flex-col gap-space-xl">

            @if ($org && $org->onTrial())
                <div class="no-print flex items-center gap-space-sm px-space-md py-space-sm rounded-lg font-label-md text-label-md {{ $org->trialExpired() ? 'bg-error-container text-on-error-container' : 'bg-surface-container-high text-primary' }}">
                    <span class="material-symbols-outlined text-[18px]">{{ $org->trialExpired() ? 'lock' : 'hourglass_top' }}</span>
                    {{ $org->trialExpired() ? 'Your free trial has ended. Your records are read-only until you choose a plan.' : 'Free trial: '.$org->trialDaysLeft().' '.\Illuminate\Support\Str::plural('day', $org->trialDaysLeft()).' left.' }}
                </div>
            @endif

            @if (session('status'))
                <div class="no-print flex items-center gap-space-sm bg-secondary-fixed text-on-secondary-fixed px-space-md py-space-sm rounded-lg font-label-md text-label-md">
                    <span class="material-symbols-outlined text-[18px]">check_circle</span> {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="no-print bg-error-container text-on-error-container px-space-md py-space-sm rounded-lg font-label-md text-label-md">
                    <div class="flex items-center gap-space-sm font-semibold">
                        <span class="material-symbols-outlined text-[18px]">error</span> Please fix the following:
                    </div>
                    <ul class="list-disc ml-8 mt-1">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </div>
    </main>
</div>

<script>
    // Phone menu: open / close the sidebar
    (function () {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        const button = document.getElementById('menu-btn');
        function toggle(open) {
            sidebar.classList.toggle('-translate-x-full', !open);
            overlay.classList.toggle('hidden', !open);
        }
        button.addEventListener('click', () => toggle(sidebar.classList.contains('-translate-x-full')));
        overlay.addEventListener('click', () => toggle(false));
    })();
</script>
@stack('scripts')
</body>
</html>
