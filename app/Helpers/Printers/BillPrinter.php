<?php

namespace App\Helpers\Printers;

use App\Services\BusinessConfigurationService;
use Illuminate\Support\Facades\Auth;
use Mike42\Escpos\Printer;

class BillPrinter
{
    private const WIDTH_80MM = 45;
    private const WIDTH_58MM = 32;

    private array $business;
    private float $vatRate;
    private string $currencySymbol;
    private int $lineWidth;
    private string $copyType;
    private ?string $footerText;

    public function __construct(
        private Printer $printer,
        private $billDetails,
        private $orderDetails,
        string $copyType = 'customer'
    ) {
        $businessConfiguration = app(BusinessConfigurationService::class);

        $this->business = $businessConfiguration->detailsForBill($billDetails);
        $this->vatRate = $businessConfiguration->vatRateForBill($billDetails);
        $this->currencySymbol = $this->business['currency_symbol'];
        $this->copyType = $copyType;
        $this->footerText = config('pos.printing.receipt.footer_text');
        $this->lineWidth = config('pos.printing.receipt.width', '80mm') === '58mm'
            ? self::WIDTH_58MM
            : self::WIDTH_80MM;
    }

    public function print(bool $duplicate = false): void
    {
        $copyType = $duplicate ? 'duplicate' : $this->copyType;

        $this->printHeader($copyType);
        $this->printInvoiceDetails();
        $this->printItems();
        $this->printTotals();
        $this->printFooter();
        $this->finish();
    }

    public function printDuplicate(): void
    {
        $this->print(true);
    }

    public function printSummary(): void
    {
        $this->printer->setJustification(Printer::JUSTIFY_CENTER);
        $this->printer->setEmphasis(true);
        $this->printer->setTextSize(2, 2);
        $this->printer->text($this->business['name'] . "\n");
        $this->printer->setTextSize(1, 1);
        $this->printer->text("ORDER SUMMARY\n");
        $this->printer->text("SUMMARY COPY\n");
        $this->printer->setEmphasis(false);
        $this->printer->text("NOT A TAX INVOICE\n");
        $this->printer->text($this->business['address'] . "\n");

        $this->printDash();
        $this->printer->setJustification(Printer::JUSTIFY_LEFT);
        $invoiceAt = $this->invoiceAt();
        $this->detailLine('Bill Date', $invoiceAt->format('Y-m-d'));
        $this->detailLine('Bill Time', $invoiceAt->format('h:i A'));
        $this->detailLine('Table', $this->tableName());
        $this->detailLine('Cashier', $this->cashierName());
        $this->printDash();

        $this->printItems();
        $this->printer->setJustification(Printer::JUSTIFY_RIGHT);
        $this->printer->setEmphasis(true);
        $this->printer->text(
            'Summary Total ' . $this->currencySymbol . ' '
            . $this->money($this->billDetails->bill_amount) . "\n"
        );
        $this->printer->setEmphasis(false);
        $this->printDash();

        $this->printer->setJustification(Printer::JUSTIFY_CENTER);
        $this->printer->text("Editable order summary only\n");
        $this->printer->text("Final tax invoice prints separately\n");
        $this->finish();
    }

    public function printDash(): void
    {
        $this->printer->setJustification(Printer::JUSTIFY_LEFT);
        $this->printer->text(str_repeat('-', $this->lineWidth) . "\n");
    }

    private function printHeader(string $copyType): void
    {
        $this->printer->setJustification(Printer::JUSTIFY_CENTER);
        $this->printer->setEmphasis(true);
        $this->printer->setTextSize(2, 2);
        $this->printer->text($this->business['name'] . "\n");
        $this->printer->setTextSize(1, 1);
        $this->printer->text("TAX INVOICE\n");
        $this->printer->text($this->copyLabel($copyType) . "\n");

        if ($copyType === 'duplicate') {
            $this->printer->text("*** DUPLICATE COPY ***\n");
        }

        $this->printer->setEmphasis(false);
        $this->printer->text($this->business['address'] . "\n");

        if ($this->business['tax_registration']) {
            $this->printer->text("PAN/VAT: {$this->business['tax_registration']}\n");
        }

        $this->printDash();
    }

    private function printInvoiceDetails(): void
    {
        $this->printer->setJustification(Printer::JUSTIFY_LEFT);
        $this->detailLine('Invoice Number', $this->billDetails->invoice_no ?: $this->billDetails->bill_id);
        $this->detailLine('Fiscal Year', $this->billDetails->fiscal_year ?: 'N/A');
        $this->detailLine('Bill Date', $this->invoiceAt()->format('Y-m-d'));
        $this->detailLine('Bill Time', $this->invoiceAt()->format('h:i A'));
        $this->detailLine('Table', $this->tableName());

        if ($this->hasSourceTable()) {
            $this->detailLine('Source Table', $this->billDetails->sourceTable->name);
        }

        $this->detailLine('Order Type', $this->billDetails->table_id ? 'Dine-in' : 'Takeaway');
        $this->detailLine('Waiter', $this->waiterName());
        $this->detailLine('Cashier', $this->cashierName());

        if ($this->billDetails->buyer_name) {
            $this->detailLine('Buyer Name', $this->billDetails->buyer_name);
        }

        if ($this->billDetails->buyer_pan) {
            $this->detailLine('Buyer PAN', $this->billDetails->buyer_pan);
        }

        $this->printDash();
    }

    private function printItems(): void
    {
        [$nameWidth, $qtyWidth, $unitWidth, $totalWidth] = $this->itemColumnWidths();

        $this->printer->setJustification(Printer::JUSTIFY_LEFT);
        $this->printer->text(
            $this->fit('Item', $nameWidth)
            . $this->fit('Qty', $qtyWidth, STR_PAD_LEFT)
            . $this->fit('Unit', $unitWidth, STR_PAD_LEFT)
            . $this->fit('Total', $totalWidth, STR_PAD_LEFT)
            . "\n"
        );
        $this->printDash();

        foreach ($this->orderDetails as $name => $details) {
            $nameLines = str_split((string) $name, $nameWidth);
            $this->printer->text(
                $this->fit($nameLines[0] ?? '', $nameWidth)
                . $this->fit((string) $details['quantity'], $qtyWidth, STR_PAD_LEFT)
                . $this->fit($this->money($details['price']), $unitWidth, STR_PAD_LEFT)
                . $this->fit($this->money($details['total']), $totalWidth, STR_PAD_LEFT)
                . "\n"
            );

            foreach (array_slice($nameLines, 1) as $nameLine) {
                $this->printer->text($this->fit($nameLine, $nameWidth) . "\n");
            }

            if ($details['loyalty_reward'] ?? false) {
                $this->printer->text('Stamp-card reward; regular '
                    . $this->currencySymbol . ' ' . $this->money($details['original_price']) . "\n");
            }
        }

        $this->printDash();
    }

    private function printTotals(): void
    {
        $totalQuantity = $this->orderDetails->sum(
            fn (array $details) => (int) $details['quantity']
        );

        $this->printer->text("Total Qty :{$totalQuantity}\n");
        $this->amountLine('Subtotal', $this->billDetails->bill_amount);
        $this->amountLine('Discount', $this->billDetails->discount);

        if ((float) $this->billDetails->service_charge_amount > 0) {
            $this->amountLine('Service Charge', $this->billDetails->service_charge_amount);
        }

        $this->amountLine('Taxable Amount', $this->billDetails->taxable_amount);
        $this->amountLine(
            'VAT ' . number_format($this->vatRate, 2) . '%',
            $this->billDetails->vat_amount
        );
        $this->printDash();

        $this->printer->setEmphasis(true);
        $this->amountLine('Grand Total', $this->billDetails->grand_total);
        $this->printer->setEmphasis(false);
        if ($this->billDetails->payments->isEmpty()) {
            $this->detailLine(
                'Payment Method',
                config('pos.payments.' . $this->billDetails->payment_method, $this->billDetails->payment_method ?: '-')
            );
        } else {
            foreach ($this->billDetails->payments as $payment) {
                $this->amountLine(
                    config('pos.payments.' . $payment->payment_method, ucfirst($payment->payment_method)),
                    $payment->amount
                );

                if ($payment->reference_no) {
                    $this->detailLine('Reference', $payment->reference_no);
                }
            }
        }
        $this->printDash();
    }

    private function printFooter(): void
    {
        $this->printer->setJustification(Printer::JUSTIFY_CENTER);

        $footer = filled($this->footerText)
            ? $this->footerText
            : $this->business['tagline'];

        if (filled($footer)) {
            $this->printer->text($footer . "\n");
        }

        $this->printer->text("Thank You\n");
    }

    private function finish(): void
    {
        $this->printer->cut();
        $this->printer->pulse();
        $this->printer->close();
    }

    private function copyLabel(string $copyType): string
    {
        return match ($copyType) {
            'restaurant' => 'RESTAURANT COPY',
            'duplicate' => 'DUPLICATE COPY',
            'summary' => 'SUMMARY COPY',
            default => 'CUSTOMER COPY',
        };
    }

    private function detailLine(string $label, string $value): void
    {
        $this->printer->text("{$label}: {$value}\n");
    }

    private function amountLine(string $label, $amount): void
    {
        $value = $this->currencySymbol . ' ' . $this->money($amount);
        $spacing = max($this->lineWidth - strlen($label) - strlen($value), 1);

        $this->printer->text($label . str_repeat(' ', $spacing) . $value . "\n");
    }

    private function itemColumnWidths(): array
    {
        return $this->lineWidth === self::WIDTH_58MM
            ? [11, 3, 8, 10]
            : [22, 4, 9, 10];
    }

    private function fit(string $value, int $width, int $padType = STR_PAD_RIGHT): string
    {
        if (strlen($value) > $width) {
            $value = substr($value, 0, $width);
        }

        return str_pad($value, $width, ' ', $padType);
    }

    private function money($amount): string
    {
        return number_format((float) $amount, 2, '.', ',');
    }

    private function tableName(): string
    {
        return $this->billDetails->table?->name ?? 'Takeaway';
    }

    private function hasSourceTable(): bool
    {
        return $this->billDetails->sourceTable
            && $this->billDetails->sourceTable->name !== $this->tableName();
    }

    private function waiterName(): string
    {
        return $this->billDetails->orders
            ->pluck('waiter.name')
            ->filter()
            ->unique()
            ->implode(', ') ?: 'System';
    }

    private function cashierName(): string
    {
        return $this->billDetails->lockedBy?->name
            ?? Auth::user()?->name
            ?? 'System';
    }

    private function invoiceAt()
    {
        return $this->billDetails->fiscalSnapshot?->invoice_at
            ?? $this->billDetails->locked_at
            ?? $this->billDetails->created_at;
    }
}
