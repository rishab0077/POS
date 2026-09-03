<?php

namespace App\Services;

use App\Helpers\BillHelper;
use App\Helpers\KitchenHelper;
use App\Helpers\Printers\BillPrinter;
use App\Helpers\Printers\KOTPrinter;
use App\Helpers\Printers\RawBufferPrintConnector;
use App\Models\Bill;
use App\Models\Order;
use Mike42\Escpos\Printer;

class PrintPayloadService
{
    public const FORMAT = 'escpos_base64';

    public function kotPayload(string $kot, ?int $billId, ?string $productionArea, string $ticketLabel): string
    {
        $connector = new RawBufferPrintConnector();
        $printer = new Printer($connector);
        $orderDetails = KitchenHelper::getKOTOrders($kot, $productionArea);
        $kotDetails = Order::with('waiter', 'table', 'sourceTable')->where('kot', $kot)->firstOrFail();
        $billCompleteId = $billId ? Bill::findOrFail($billId)->bill_id : null;

        (new KOTPrinter(
            $printer,
            $kotDetails,
            $orderDetails,
            $billCompleteId,
            $ticketLabel,
            $productionArea
        ))->print();

        return base64_encode($connector->getData());
    }

    public function billPayload(int $billId, bool $duplicate = false, string $copyType = 'customer'): string
    {
        $connector = new RawBufferPrintConnector();
        $printer = new Printer($connector);
        $billDetails = Bill::with(['table', 'sourceTable', 'lockedBy', 'payments', 'orders.waiter'])->findOrFail($billId);
        $orderDetails = BillHelper::getBillOrders($billId);
        $billPrinter = new BillPrinter($printer, $billDetails, $orderDetails, $copyType);

        if ($duplicate) {
            $billPrinter->printDuplicate();
        } else {
            $billPrinter->print();
        }

        return base64_encode($connector->getData());
    }

    public function summaryBillPayload(int $billId): string
    {
        $connector = new RawBufferPrintConnector();
        $printer = new Printer($connector);
        $billDetails = Bill::with(['table', 'sourceTable', 'lockedBy', 'orders.waiter'])->findOrFail($billId);
        $orderDetails = BillHelper::getBillOrders($billId);

        (new BillPrinter($printer, $billDetails, $orderDetails))->printSummary();

        return base64_encode($connector->getData());
    }
}
