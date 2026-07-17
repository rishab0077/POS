<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Requests\StoreSupplierRequest;
use App\Services\AuditLogger;

class SupplierController extends Controller
{
    public function index()
    {
        $suppliers = Supplier::withCount('purchaseInvoices')
            ->orderBy('name')
            ->paginate(25);

        return view('modules.inventory.suppliers.index', compact('suppliers'));
    }

    public function create()
    {
        $supplier = new Supplier(['active' => true, 'opening_balance' => 0]);

        return view('modules.inventory.suppliers.create', compact('supplier'));
    }

    public function store(StoreSupplierRequest $request, AuditLogger $audit)
    {
        $supplier = Supplier::create($this->payload($request));

        $audit->record('supplier_created', 'inventory', [
            'subject' => $supplier,
            'after' => $supplier->attributesToArray(),
            'metadata' => ['summary' => "Supplier {$supplier->name} created."],
        ]);

        return redirect()->route('inventory.suppliers.index')->with('success', 'Supplier created.');
    }

    public function edit(Supplier $supplier)
    {
        return view('modules.inventory.suppliers.edit', compact('supplier'));
    }

    public function update(StoreSupplierRequest $request, Supplier $supplier, AuditLogger $audit)
    {
        $before = $supplier->attributesToArray();
        $supplier->update($this->payload($request));

        $audit->record('supplier_updated', 'inventory', [
            'subject' => $supplier,
            'before' => $before,
            'after' => $supplier->fresh()->attributesToArray(),
            'metadata' => ['summary' => "Supplier {$supplier->name} updated."],
        ]);

        return redirect()->route('inventory.suppliers.index')->with('success', 'Supplier updated.');
    }

    private function payload(StoreSupplierRequest $request): array
    {
        return array_merge($request->validated(), [
            'opening_balance' => $request->input('opening_balance', 0),
            'active' => $request->boolean('active', true),
        ]);
    }
}
