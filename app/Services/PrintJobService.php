<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\Order;
use App\Models\PrintJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PrintJobService
{
    public function __construct(private PrintPayloadService $payloadService)
    {
    }

    public function queueKot(string $kot, ?int $billId, string $productionArea, string $ticketLabel): PrintJob
    {
        $order = Order::where('kot', $kot)->firstOrFail();
        $payload = $this->payloadService->kotPayload($kot, $billId, $productionArea, $ticketLabel);

        return $this->firstOrCreateJob(
            "order:{$order->id}:{$productionArea}:{$ticketLabel}",
            [
                'type' => strtolower($ticketLabel),
                'printer_key' => $productionArea,
                'source_type' => Order::class,
                'source_id' => $order->id,
                'payload_format' => PrintPayloadService::FORMAT,
                'payload' => $payload,
            ]
        );
    }

    public function queueBill(
        int $billId,
        bool $duplicate = false,
        string $printerKey = 'counter',
        string $copyType = 'customer'
    ): PrintJob
    {
        $bill = Bill::findOrFail($billId);
        $copyType = $duplicate ? 'duplicate' : $copyType;
        $payload = $this->payloadService->billPayload($billId, $duplicate, $copyType);

        if ($duplicate) {
            return $this->createJob(
                'bill:' . $bill->id . ':duplicate:' . $printerKey . ':' . Str::uuid(),
                [
                    'type' => 'duplicate_bill',
                    'copy_type' => 'duplicate',
                    'printer_key' => $printerKey,
                    'source_type' => Bill::class,
                    'source_id' => $bill->id,
                    'payload_format' => PrintPayloadService::FORMAT,
                    'payload' => $payload,
                ]
            );
        }

        $idempotencyKey = $copyType === 'restaurant'
            ? "bill:{$bill->id}:{$printerKey}:restaurant"
            : "bill:{$bill->id}:{$printerKey}";

        return $this->firstOrCreateJob(
            $idempotencyKey,
            [
                'type' => 'bill',
                'copy_type' => $copyType,
                'printer_key' => $printerKey,
                'source_type' => Bill::class,
                'source_id' => $bill->id,
                'payload_format' => PrintPayloadService::FORMAT,
                'payload' => $payload,
            ]
        );
    }

    public function queueBillCopies(
        int $billId,
        ?string $printCopies = null,
        string $printerKey = 'counter'
    ): Collection {
        return collect([
            $this->queueBill($billId, false, $printerKey, 'customer'),
            $this->queueBill($billId, false, $printerKey, 'restaurant'),
        ]);
    }

    public function queueSummaryBill(int $billId, string $printerKey = 'counter'): PrintJob
    {
        $bill = Bill::findOrFail($billId);
        $payload = $this->payloadService->summaryBillPayload($billId);

        return $this->createJob(
            'bill:' . $bill->id . ':summary:' . $printerKey . ':' . Str::uuid(),
            [
                'type' => 'summary_bill',
                'copy_type' => 'summary',
                'printer_key' => $printerKey,
                'source_type' => Bill::class,
                'source_id' => $bill->id,
                'payload_format' => PrintPayloadService::FORMAT,
                'payload' => $payload,
            ]
        );
    }

    private function firstOrCreateJob(string $idempotencyKey, array $attributes): PrintJob
    {
        return PrintJob::firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            array_merge($attributes, ['status' => PrintJob::STATUS_PENDING])
        );
    }

    private function createJob(string $idempotencyKey, array $attributes): PrintJob
    {
        return PrintJob::create(array_merge($attributes, [
            'idempotency_key' => $idempotencyKey,
            'status' => PrintJob::STATUS_PENDING,
        ]));
    }

}
