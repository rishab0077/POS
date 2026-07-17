@csrf

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-semibold text-gray-700">Supplier Name</label>
        <input name="name" value="{{ old('name', $supplier->name) }}" class="w-full border rounded p-2">
        @error('name') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-semibold text-gray-700">Contact Person</label>
        <input name="contact_person" value="{{ old('contact_person', $supplier->contact_person) }}" class="w-full border rounded p-2">
        @error('contact_person') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-semibold text-gray-700">Contact No.</label>
        <input name="phone" value="{{ old('phone', $supplier->phone) }}" class="w-full border rounded p-2">
        @error('phone') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-semibold text-gray-700">Email</label>
        <input name="email" type="email" value="{{ old('email', $supplier->email) }}" class="w-full border rounded p-2">
        @error('email') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-semibold text-gray-700">VAT/PAN No.</label>
        <input name="vat_pan_no" value="{{ old('vat_pan_no', $supplier->vat_pan_no) }}" class="w-full border rounded p-2">
        @error('vat_pan_no') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-semibold text-gray-700">Opening Balance</label>
        <input name="opening_balance" type="number" step="0.01" value="{{ old('opening_balance', $supplier->opening_balance ?? 0) }}" class="w-full border rounded p-2">
        @error('opening_balance') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    </div>
</div>

<div class="mt-4">
    <label class="block text-sm font-semibold text-gray-700">Address</label>
    <textarea name="address" class="w-full border rounded p-2" rows="3">{{ old('address', $supplier->address) }}</textarea>
    @error('address') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
</div>

<div class="mt-4">
    <label class="inline-flex items-center gap-2">
        <input type="checkbox" name="active" value="1" @checked(old('active', $supplier->active ?? true))>
        <span>Active</span>
    </label>
</div>

<div class="mt-6 flex gap-3">
    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded font-semibold">Save</button>
    <a href="{{ route('inventory.suppliers.index') }}" class="px-4 py-2 bg-gray-200 rounded font-semibold">Cancel</a>
</div>
