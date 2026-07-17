<x-guest-layout>
    <div class="mx-auto max-w-5xl">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-3xl font-bold text-gray-900">Menu Categories</h1>
            <a class="text-blue-700 underline" href="{{ route('menus.index') }}">View all menu items</a>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($categories as $category)
                <a class="rounded-lg bg-white p-5 shadow hover:shadow-md" href="{{ route('categories.show', $category) }}">
                    <h2 class="text-xl font-semibold text-gray-900">{{ $category->name }}</h2>
                    @if ($category->description)
                        <p class="mt-2 text-sm text-gray-600">{{ $category->description }}</p>
                    @endif
                </a>
            @empty
                <p class="rounded-lg bg-white p-5 text-gray-600 shadow">No categories are currently available.</p>
            @endforelse
        </div>
    </div>
</x-guest-layout>
