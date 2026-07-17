<x-guest-layout>
    <div class="mx-auto max-w-5xl">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-3xl font-bold text-gray-900">Menu</h1>
            <a class="text-blue-700 underline" href="{{ route('categories.index') }}">Browse categories</a>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($menus as $menu)
                <article class="rounded-lg bg-white p-5 shadow">
                    <h2 class="text-xl font-semibold text-gray-900">{{ $menu->name }}</h2>
                    @if ($menu->category->isNotEmpty())
                        <p class="mt-1 text-xs uppercase tracking-wide text-gray-500">
                            {{ $menu->category->pluck('name')->join(', ') }}
                        </p>
                    @endif
                    @if ($menu->description)
                        <p class="mt-2 text-sm text-gray-600">{{ $menu->description }}</p>
                    @endif
                    <p class="mt-3 font-semibold">NPR {{ number_format((float) $menu->price, 2) }}</p>
                </article>
            @empty
                <p class="rounded-lg bg-white p-5 text-gray-600 shadow">No menu items are currently available.</p>
            @endforelse
        </div>
    </div>
</x-guest-layout>
