<?php

namespace App\Helpers\Printers;

use Mike42\Escpos\Printer;

class KOTPrinter
{
    private const LINE_WIDTH = 45;

    public function __construct(
        private Printer $printer,
        private $KOTDetails,
        private $orderDetails,
        private $bill_complete_id,
        private string $ticketLabel = 'KOT',
        private ?string $productionArea = null,
    ) {
    }

    public function print(): void
    {
        $this->printer->setJustification(Printer::JUSTIFY_CENTER);
        $this->printer->setEmphasis(true);
        $this->printer->setTextSize(2, 2);
        $this->printer->text(strtoupper($this->ticketLabel) . "\n");
        $this->printer->setTextSize(1, 1);
        $this->printer->text("ID: {$this->KOTDetails->KOT}\n");

        if ($this->bill_complete_id) {
            $this->printer->text("Token: {$this->bill_complete_id}\n");
        }

        $this->printer->text('Printer Area: ' . strtoupper($this->productionArea ?: 'UNASSIGNED') . "\n");
        $this->printer->setEmphasis(false);

        $this->printer->setJustification(Printer::JUSTIFY_LEFT);
        $this->printer->text($this->getSeparator());
        $this->detailLine('Table', $this->KOTDetails->table?->name ?? 'Takeaway');

        if (
            $this->KOTDetails->sourceTable
            && $this->KOTDetails->sourceTable->name !== $this->KOTDetails->table?->name
        ) {
            $this->detailLine('Source Table', $this->KOTDetails->sourceTable->name);
        }

        $this->detailLine(
            'Order Type',
            $this->KOTDetails->table_id ? 'Dine-in' : 'Takeaway / Packed'
        );
        $this->detailLine('Waiter', $this->KOTDetails->waiter?->name ?? 'System');
        $this->detailLine('Order Time', $this->KOTDetails->created_at->format('Y-m-d h:i A'));
        $this->printer->text($this->getSeparator());

        if (filled($this->KOTDetails->special_instructions)) {
            $this->printer->setEmphasis(true);
            $this->printer->text("SPECIAL INSTRUCTIONS\n");
            $this->printer->setEmphasis(false);
            $this->wrappedText((string) $this->KOTDetails->special_instructions);
            $this->printer->text($this->getSeparator());
        }

        $nameWidth = 39;
        $qtyWidth = 6;
        $this->printer->setEmphasis(true);
        $this->printer->text(
            str_pad('Item', $nameWidth)
            . str_pad('Qty', $qtyWidth, ' ', STR_PAD_LEFT)
            . "\n"
        );
        $this->printer->setEmphasis(false);
        $this->printer->text($this->getSeparator());

        foreach ($this->orderDetails as $name => $quantity) {
            $nameLines = str_split((string) $name, $nameWidth);
            $this->printer->text(
                str_pad($nameLines[0] ?? '', $nameWidth)
                . str_pad((string) $quantity, $qtyWidth, ' ', STR_PAD_LEFT)
                . "\n"
            );

            foreach (array_slice($nameLines, 1) as $nameLine) {
                $this->printer->text($nameLine . "\n");
            }
        }

        $this->printer->text($this->getSeparator());
        $this->printer->setJustification(Printer::JUSTIFY_CENTER);
        $this->printer->setEmphasis(true);
        $this->printer->text("Total Qty: {$this->orderDetails->sum()}\n");
        $this->printer->setEmphasis(false);
        $this->printer->text($this->getSeparator());
        $this->printer->cut();
        $this->printer->close();
    }

    public function getSeparator(): string
    {
        return str_repeat('-', self::LINE_WIDTH) . "\n";
    }

    private function detailLine(string $label, string $value): void
    {
        $this->printer->text("{$label}: {$value}\n");
    }

    private function wrappedText(string $text): void
    {
        foreach (str_split($text, self::LINE_WIDTH) as $line) {
            $this->printer->text($line . "\n");
        }
    }
}
