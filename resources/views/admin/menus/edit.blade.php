<x-master-layout>
    @section('title', 'Edit Menu')

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="flex m-2 p-2">
                <a href="{{ route('admin.menus.index') }}"
                    class="px-4 py-2 bg-indigo-500 hover:bg-indigo-700 rounded-lg text-white">Menu Index</a>
            </div>
            <div class="m-2 p-2 bg-slate-100 rounded">
                <div class="space-y-8 divide-y divide-gray-200 w-1/2 mt-10">
                    <form method="POST" action="{{ route('admin.menus.update', $menu->id) }}"
                        enctype="multipart/form-data">
                        @csrf
                        @method('PUT')
                        <div class="sm:col-span-6">
                            <label for="name" class="block text-sm font-medium text-gray-700"> Name </label>
                            <div class="mt-1">
                                <input type="text" id="name" name="name" value="{{ old('name', $menu->name) }}"
                                    class="block w-full appearance-none bg-white border border-gray-400 rounded-md py-2 px-3 text-base leading-normal transition duration-150 ease-in-out sm:text-sm sm:leading-5" />
                            </div>
                            @error('name')
                                <div class="text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="sm:col-span-6">
                            <label for="shortCode" class="block text-sm font-medium text-gray-700"> Short Code </label>
                            <div class="mt-1">
                                <input type="text" id="shortCode" name="shortCode" value="{{ old('shortCode', $menu->shortcode) }}"
                                    class="block w-full appearance-none bg-white border border-gray-400 rounded-md py-2 px-3 text-base leading-normal transition duration-150 ease-in-out sm:text-sm sm:leading-5" />
                            </div>
                            @error('shortCode')
                                <div class="text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="sm:col-span-6">
                            <label for="image" class="block text-sm font-medium text-gray-700"> Image </label>
                            <div>
                                <img class="w-32 h-32" src="{{ Storage::url($menu->image) }}">
                            </div>
                            <div class="mt-1">
                                <input type="file" id="image" name="image"
                                    class="block w-full appearance-none bg-white border border-gray-400 rounded-md py-2 px-3 text-base leading-normal transition duration-150 ease-in-out sm:text-sm sm:leading-5" />
                            </div>
                            @error('image')
                                <div class="text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="sm:col-span-6">
                            <label for="price" class="block text-sm font-medium text-gray-700"> Price </label>
                            <div class="mt-1">
                                <input type="number" min="0.00" max="10000.00" step="0.01" id="price"
                                    name="price" value="{{ old('price', $menu->price) }}"
                                    class="block w-full appearance-none bg-white border border-gray-400 rounded-md py-2 px-3 text-base leading-normal transition duration-150 ease-in-out sm:text-sm sm:leading-5" />
                            </div>
                            @error('price')
                                <div class="text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="sm:col-span-6 pt-5">
                            <label for="body" class="block text-sm font-medium text-gray-700">Description</label>
                            <div class="mt-1">
                                <textarea id="body" rows="3" name="description"
                                    class="shadow-sm focus:ring-indigo-500 appearance-none bg-white border py-2 px-3 text-base leading-normal transition duration-150 ease-in-out focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md">{{ old('description', $menu->description) }}</textarea>
                            </div>
                            @error('description')
                                <div class="text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="sm:col-span-6 pt-5">
                            <label class="block text-sm font-medium text-gray-700">Type</label>
                            <div class="mt-2">
                                <div class="flex items-center">
                                    <input id="type_stock" name="type" type="radio" value="stock" @checked(old('type', $menu->type?->value ?? 'service') === 'stock')
                                        class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300">
                                    <label for="type_stock" class="ml-2 block text-sm text-gray-900">Stock</label>
                                </div>
                                <div class="flex items-center mt-2">
                                    <input id="type_service" name="type" type="radio" value="service" @checked(old('type', $menu->type?->value ?? 'service') === 'service')
                                        class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300">
                                    <label for="type_service" class="ml-2 block text-sm text-gray-900">Service</label>
                                </div>
                            </div>
                            @error('type')
                                <div class="text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>

                        <div id="quantity_input" class="sm:col-span-6 pt-5">
                            <label for="quantity" class="block text-sm font-medium text-gray-700">Quantity</label>
                            <div class="mt-1">
                                <input id="quantity" type="number" name="quantity"
                                    value="{{ old('quantity', $menu->quantity ?? 0) }}"
                                    class="shadow-sm focus:ring-indigo-500 appearance-none bg-white border py-2 px-3 text-base leading-normal transition duration-150 ease-in-out focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md">
                            </div>
                            @error('quantity')
                                <div class="text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="sm:col-span-6 pt-5">
                            <label for="category" class="block text-sm font-medium text-gray-700">Categories</label>
                            <div class="mt-1">
                                <select id="category" name="category" class="form-multiselect block w-full mt-1">
                                    @foreach ($categories as $category)
                                        <option value="{{ $category->id }}" @selected(old('category', $menu->category->first()?->id) == $category->id)>
                                            {{ $category->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @error('category')
                                <div class="text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="mt-6 p-4">
                            <button type="submit"
                                class="px-4 py-2 bg-indigo-500 hover:bg-indigo-700 rounded-lg text-white">Update</button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            function syncQuantityVisibility() {
                if (document.querySelector('input[name="type"]:checked')?.value === 'stock') {
                    document.getElementById('quantity_input').style.display = '';
                } else {
                    document.getElementById('quantity').value = 0;
                    document.getElementById('quantity_input').style.display = 'none';
                }
            }

            document.querySelectorAll('input[name="type"]').forEach(function(input) {
                input.addEventListener('change', syncQuantityVisibility);
            });
            syncQuantityVisibility();
        });
    </script>
</x-master-layout>
