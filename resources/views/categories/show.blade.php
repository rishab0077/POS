<x-guest-layout>
    <div class="mx-auto max-w-5xl">
        <div class="mb-6">
            <a class="text-blue-700 underline" href="{{ route('categories.index') }}">All categories</a>
            <h1 class="mt-2 text-3xl font-bold text-gray-900">{{ $category->name }}</h1>
            @if ($category->description)
                <p class="mt-2 text-gray-600">{{ $category->description }}</p>
            @endif
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($category->menus as $menu)
                <article class="rounded-lg bg-white p-5 shadow">
                    <h2 class="text-xl font-semibold text-gray-900">{{ $menu->name }}</h2>
                    @if ($menu->description)
                        <p class="mt-2 text-sm text-gray-600">{{ $menu->description }}</p>
                    @endif
                    <p class="mt-3 font-semibold">NPR {{ number_format((float) $menu->price, 2) }}</p>
                </article>
            @empty
                <p class="rounded-lg bg-white p-5 text-gray-600 shadow">No menu items are currently available in this category.</p>
            @endforelse
        </div>
    </div>
</x-guest-layout>
