<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-100">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Restaurant POS') }} - @yield('title')</title>

    @include('layouts.scripts')
    @stack('styles')
</head>

<body class="h-full font-sans antialiased">
    <div class="flex flex-col min-h-screen">

        <header class="sticky top-0 z-50 w-full bg-[#1f2937] backdrop-blur-sm shadow-md">
            <div class="mx-auto max-w-full overflow-x-auto px-2 sm:px-4">
                <div class="flex h-20 min-w-max items-center justify-between gap-4">

                    <div class="flex items-center gap-x-2 sm:gap-x-6">
                        <a href="{{ route('dashboard') }}" aria-label="{{ config('app.name', 'Restaurant POS') }} dashboard"
                            class="flex h-14 w-14 items-center justify-center rounded-xl border border-gray-500 bg-gray-700 text-center text-xs font-bold leading-tight text-white">
                            POS
                        </a>

                        <nav>

                            <x-pos-nav-link :href="route('pos.tables')" :active="request()->routeIs('pos.tables')">
                                {{ __('Select Table') }}
                            </x-pos-nav-link>
                        </nav>
                    </div>

                    <nav class="flex shrink-0 items-center gap-x-1 sm:gap-x-3">
                        @if (auth()->user()->hasPermission(App\Enums\UserRole::Admin))
                            <x-pos-nav-link :href="route('pos.tables')" :active="request()->routeIs('pos.tables')">
                                {{ __('Tables') }}
                            </x-pos-nav-link>
                            <x-pos-nav-link :href="route('admin.bills.index')" :active="request()->routeIs('admin.bills.index')">
                                {{ __('Bills') }}
                            </x-pos-nav-link>
                        @endif

                        <x-pos-nav-link :href="route('order.KOT.view')" :active="request()->routeIs('order.KOT.view')">
                            {{ __('KOT View') }}
                        </x-pos-nav-link>

                        <x-pos-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                            {{ __('Dashboard') }}
                        </x-pos-nav-link>
                    </nav>

                </div>
            </div>
        </header>

        <main class="mx-auto flex-grow lg:p-1 w-full">



            <x-loader />
            {{ $slot }}
        </main>
    </div>

    @stack('scripts')
</body>

</html>
