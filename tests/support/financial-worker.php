<?php

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$script, $action, $subjectId, $userId, $readyFile] = $argv;
Illuminate\Support\Facades\Auth::loginUsingId($userId);
Illuminate\Support\Facades\DB::connection()->beforeExecuting(function ($query) use ($readyFile) {
    if (str_contains(strtolower($query), 'for update') || str_contains($query, 'insert into `supplier_payments`')) {
        touch($readyFile);
    }
});

try {
    if ($action === 'post') {
        $invoice = App\Modules\Inventory\Models\PurchaseInvoice::findOrFail($subjectId);
        app(App\Modules\Inventory\Services\PurchasePostingService::class)->post($invoice);
    } elseif ($action === 'payment') {
        $invoice = App\Modules\Inventory\Models\PurchaseInvoice::findOrFail($subjectId);
        $request = App\Modules\Inventory\Requests\StoreSupplierPaymentRequest::create('/', 'POST', [
            'supplier_id' => $invoice->supplier_id, 'purchase_invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(), 'amount' => 100, 'payment_method' => 'cash',
        ]);
        $request->setValidator(Illuminate\Support\Facades\Validator::make($request->all(), $request->rules()));
        app(App\Modules\Inventory\Controllers\SupplierPaymentController::class)->store(
            $request, app(App\Modules\Inventory\Services\PurchasePostingService::class), app(App\Services\AuditLogger::class)
        );
    } elseif ($action === 'return') {
        $snapshot = App\Models\Bill::findOrFail($subjectId)->fiscalSnapshot;
        app(App\Services\CbmsService::class)->issueCreditNote(
            $snapshot, 'Concurrent regression return', 'cash', Illuminate\Support\Facades\Auth::user()
        );
    } else {
        throw new RuntimeException('Unknown test action');
    }
    echo 'accepted';
} catch (Illuminate\Validation\ValidationException $e) {
    echo 'rejected';
}
