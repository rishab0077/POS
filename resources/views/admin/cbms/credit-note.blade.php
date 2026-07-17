@php
    $snapshot = $creditNote->snapshot;
    $bsDate = app(App\Services\NepaliDateService::class)->format($creditNote->issued_at);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit Note {{ $creditNote->credit_note_no }}</title>
    <style>
        @page { size: 80mm auto; margin: 0; }
        * { box-sizing: border-box; }
        body { width: 80mm; margin: 0; padding: 3mm; color: #111; font: 10px/1.3 DejaVu Sans, Arial, sans-serif; }
        h1, h2, p { margin: 0; }
        h1 { font-size: 14px; }
        .center { text-align: center; }
        .right { text-align: right; }
        .section { border-top: 1px dashed #222; margin-top: 2mm; padding-top: 2mm; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 1mm 0; vertical-align: top; }
        th { border-bottom: 1px dashed #222; text-align: left; }
        button { margin-bottom: 3mm; }
        .signature { border-top: 1px dashed #222; margin-top: 12mm; padding-top: 1mm; text-align: center; }
        @media print { button { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <button type="button" onclick="window.print()">Print credit note</button>
    <div class="center">
        <h1>Credit Note / Sales Return</h1>
        <h2>{{ $snapshot->seller_name }}</h2>
        <p>{{ $snapshot->seller_address }}</p>
        <p>PAN/VAT: {{ $snapshot->seller_tax_registration }}</p>
    </div>

    <div class="section">
        <p><strong>Credit Note:</strong> {{ $creditNote->credit_note_no }}</p>
        <p><strong>Original Invoice:</strong> {{ $snapshot->invoice_no }}</p>
        <p><strong>Fiscal Year:</strong> {{ $creditNote->fiscal_year }}</p>
        <p><strong>Date AD:</strong> {{ $creditNote->issued_at->format('Y-m-d H:i') }}</p>
        <p><strong>Date BS:</strong> {{ $bsDate }}</p>
    </div>

    <div class="section">
        <p><strong>Buyer:</strong> {{ $snapshot->buyer_name ?: 'Walk-in Customer' }}</p>
        <p><strong>Buyer PAN:</strong> {{ $snapshot->buyer_pan ?: '-' }}</p>
        <p><strong>Buyer Address:</strong> {{ $snapshot->buyer_address ?: '-' }}</p>
        <p><strong>Reason:</strong> {{ $creditNote->reason }}</p>
    </div>

    <div class="section">
        <table>
            <thead><tr><th>Item</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Amt</th></tr></thead>
            <tbody>
                @foreach ($creditNote->items as $item)
                    <tr>
                        <td>{{ $item->item_name }}</td>
                        <td class="right">{{ rtrim(rtrim(number_format((float) $item->quantity, 3), '0'), '.') }}</td>
                        <td class="right">{{ number_format((float) $item->unit_price, 2) }}</td>
                        <td class="right">{{ number_format((float) $item->line_total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <table>
            <tr><td>Taxable Amount</td><td class="right">{{ $snapshot->currency_symbol }} {{ number_format((float) $creditNote->taxable_sales, 2) }}</td></tr>
            @if ((float) $creditNote->tax_exempted_sales > 0)
                <tr><td>Tax-exempt Amount</td><td class="right">{{ $snapshot->currency_symbol }} {{ number_format((float) $creditNote->tax_exempted_sales, 2) }}</td></tr>
            @endif
            <tr><td>VAT Returned</td><td class="right">{{ $snapshot->currency_symbol }} {{ number_format((float) $creditNote->vat, 2) }}</td></tr>
            <tr><td><strong>Total Returned</strong></td><td class="right"><strong>{{ $snapshot->currency_symbol }} {{ number_format((float) $creditNote->total_sales, 2) }}</strong></td></tr>
            @foreach ($creditNote->transactions as $transaction)
                <tr>
                    <td>{{ $transaction->type === 'receivable_reversal' ? 'Receivable Reversed' : 'Payment Refunded' }}</td>
                    <td class="right">
                        {{ $snapshot->currency_symbol }} {{ number_format((float) $transaction->amount, 2) }}
                        @if ($transaction->payment_method)
                            ({{ config('pos.payments.' . $transaction->payment_method, ucfirst($transaction->payment_method)) }})
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    </div>

    <div class="signature">Seller signature / authorized stamp</div>
    <div class="section center">
        <p>Issued by {{ $creditNote->operator_name }}</p>
        <p>Document ID: {{ substr($creditNote->document_hash, 0, 16) }}</p>
    </div>
</body>
</html>
