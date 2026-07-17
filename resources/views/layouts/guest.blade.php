<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Restaurant POS') }}</title>

    @include('layouts.scripts')
</head>

<body class="font-sans text-gray-900 antialiased min-h-screen flex flex-col bg-gray-100">
    <header class="bg-white shadow-md">
        <nav class="container px-4 py-4 mx-auto flex flex-col gap-2 md:flex-row md:justify-between md:items-center">
            <div>
                <h1 class="text-3xl sm:text-4xl font-bold text-gray-800">
                    {{ config('app.name', 'Restaurant POS') }}
                </h1>
                <p class="mt-1 text-gray-600">Restaurant service, billing, and kitchen operations</p>
            </div>
            <a href="{{ url('/login') }}"
                class="inline-flex items-center justify-center rounded-md bg-gray-800 px-4 py-2 font-semibold text-white hover:bg-gray-700">
                Staff login
            </a>
        </nav>
    </header>

    <main class="flex-grow p-4 sm:p-6 lg:p-8">
        {{ $slot }}
    </main>

    <footer class="bg-gray-800 p-4 text-center text-sm text-gray-200">
        {{ config('app.name', 'Restaurant POS') }}
    </footer>
</body>

</html>
