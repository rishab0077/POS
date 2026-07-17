<x-master-layout>
    @section('title', 'Record Stock Movement')

    <div class="max-w-4xl mx-auto">
        @include('modules.inventory.partials.nav')
        <div class="bg-white rounded shadow p-6">
            <h1 class="text-xl font-semibold mb-4">Record Stock Movement</h1>
            <form method="POST" action="{{ route('inventory.stock-movements.store') }}">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Stock Item</label>
                        <select name="stock_item_id" class="w-full border rounded p-2">
                            @foreach ($stockItems as $item)
                                <option value="{{ $item->id }}" @selected(old('stock_item_id') == $item->id)>{{ $item->name }} ({{ number_format($item->current_quantity, 3) }} {{ $item->unit }})</option>
                            @endforeach
                        </select>
                        @error('stock_item_id') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Movement Type</label>
                        <select name="movement_type" class="w-full border rounded p-2">
                            <option value="in" @selected(old('movement_type') === 'in')>Stock In</option>
                            <option value="out" @selected(old('movement_type') === 'out')>Stock Out</option>
                            <option value="adjustment" @selected(old('movement_type') === 'adjustment')>Adjustment</option>
                        </select>
                        @error('movement_type') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Quantity</label>
                        <input name="quantity" type="number" step="0.001" value="{{ old('quantity') }}" class="w-full border rounded p-2">
                        @error('quantity') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Unit Cost</label>
                        <input name="unit_cost" type="number" step="0.01" value="{{ old('unit_cost') }}" class="w-full border rounded p-2">
                        @error('unit_cost') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Reason</label>
                        <select name="reason" class="w-full border rounded p-2">
                            <option value="">Select for stock out</option>
                            @foreach (config('pos.stock_removal_reasons') as $value => $label)
                                <option value="{{ $value }}" @selected(old('reason') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('reason') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-4">
                    <label class="block text-sm font-semibold text-gray-700">Notes</label>
                    <textarea name="notes" class="w-full border rounded p-2">{{ old('notes') }}</textarea>
                </div>

                <div class="mt-6 flex gap-3">
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded font-semibold">Save</button>
                    <a href="{{ route('inventory.stock-movements.index') }}" class="px-4 py-2 bg-gray-200 rounded font-semibold">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</x-master-layout>
