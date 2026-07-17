@csrf

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-semibold text-gray-700">Menu Item</label>
        <select name="menu_item_id" class="w-full border rounded p-2">
            @foreach ($menus as $menu)
                <option value="{{ $menu->id }}" @selected(old('menu_item_id', $mapping->menu_item_id) == $menu->id)>
                    {{ $menu->name }}
                </option>
            @endforeach
        </select>
        @error('menu_item_id') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-semibold text-gray-700">Stock Item</label>
        <select name="stock_item_id" class="w-full border rounded p-2">
            @foreach ($stockItems as $stockItem)
                <option value="{{ $stockItem->id }}" @selected(old('stock_item_id', $mapping->stock_item_id) == $stockItem->id)>
                    {{ $stockItem->name }} ({{ $stockItem->unit }})
                </option>
            @endforeach
        </select>
        @error('stock_item_id') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-semibold text-gray-700">Quantity Per Sale</label>
        <input name="quantity_per_sale" type="number" step="0.001" value="{{ old('quantity_per_sale', $mapping->quantity_per_sale) }}" class="w-full border rounded p-2">
        @error('quantity_per_sale') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    <label class="inline-flex items-center gap-2 mt-6">
        <input type="checkbox" name="active" value="1" @checked(old('active', $mapping->active ?? true))>
        <span>Active</span>
    </label>
</div>

<div class="mt-6 flex gap-3">
    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded font-semibold">Save</button>
    <a href="{{ route('inventory.menu-mappings.index') }}" class="px-4 py-2 bg-gray-200 rounded font-semibold">Cancel</a>
</div>
