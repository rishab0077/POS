@php
    $businessConfiguration = app(App\Services\BusinessConfigurationService::class);
    $business = $businessConfiguration->detailsForBill($bill);
    $vatRate = $businessConfiguration->vatRateForBill($bill);
    $invoiceNo = $bill->invoice_no ?: $bill->bill_id;
    $printedBy = auth()->check() ? auth()->user()->name : 'System';
    $printedAt = now();
    $invoiceAt = $bill->fiscalSnapshot?->invoice_at ?? $bill->locked_at ?? $bill->created_at;
    $bsDate = app(App\Services\NepaliDateService::class)->format($invoiceAt);
@endphp

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tax Invoice {{ $invoiceNo }}</title>
    <style>
        @page {
            size: 80mm auto;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        body {
            width: 80mm;
            margin: 0;
            padding: 3mm;
            color: #111;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10px;
            line-height: 1.25;
        }

        h1,
        h2,
        p {
            margin: 0;
        }

        .center {
            text-align: center;
        }

        .muted {
            color: #444;
        }

        .title {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 2mm;
        }

        .section {
            border-top: 1px dashed #222;
            margin-top: 2mm;
            padding-top: 2mm;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 1mm 0;
            vertical-align: top;
        }

        th {
            border-bottom: 1px dashed #222;
            font-weight: 700;
            text-align: left;
        }

        .right {
            text-align: right;
        }

        .signature {
            height: 14mm;
            display: flex;
            align-items: end;
            justify-content: center;
            border-top: 1px dashed #222;
            margin-top: 12mm;
            padding-top: 1mm;
        }

        @media print {
            html,
            body {
                width: 80mm;
                margin: 0;
            }
        }
    </style>
</head>

<body>
    <div class="center">
        <h1 class="title">Tax Invoice / कर बीजक</h1>
        <h2>{{ $business['name'] }}</h2>
        <p>{{ $business['address'] }}</p>
        @if ($business['tax_registration'])
            <p>PAN/VAT: {{ $business['tax_registration'] }}</p>
        @endif
    </div>

    <div class="section">
        <p><strong>Invoice No:</strong> {{ $invoiceNo }}</p>
        <p><strong>Fiscal Year:</strong> {{ $bill->fiscal_year ?: 'N/A' }}</p>
        <p><strong>Date AD:</strong> {{ $invoiceAt->format('Y-m-d H:i') }}</p>
        <p><strong>Date BS:</strong> {{ $bsDate }}</p>
        <p><strong>Table:</strong> {{ $bill->table ? $bill->table->name : 'Take Away' }}</p>
    </div>

    <div class="section">
        <p><strong>Buyer:</strong> {{ $bill->buyer_name ?: 'Walk-in Customer' }}</p>
        <p><strong>Buyer PAN:</strong> {{ $bill->buyer_pan ?: '-' }}</p>
        <p><strong>Buyer Address:</strong> {{ $bill->buyer_address ?: '-' }}</p>
    </div>

    <div class="section">
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="right">Qty</th>
                    <th class="right">Rate</th>
                    <th class="right">Amt</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($orderDetails as $name => $details)
                    <tr>
                        <td>{{ $name }}</td>
                        <td class="right">{{ $details['quantity'] }}</td>
                        <td class="right">{{ number_format($details['price'], 2) }}</td>
                        <td class="right">{{ number_format($details['total'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <table>
            <tr>
                <td>Subtotal</td>
                <td class="right">{{ $business['currency_symbol'] }} {{ number_format($bill->bill_amount, 2) }}</td>
            </tr>
            <tr>
                <td>Discount</td>
                <td class="right">{{ $business['currency_symbol'] }} {{ number_format($bill->discount, 2) }}</td>
            </tr>
            @if ((float) $bill->service_charge_amount > 0)
                <tr>
                    <td>Service Charge</td>
                    <td class="right">{{ $business['currency_symbol'] }} {{ number_format($bill->service_charge_amount, 2) }}</td>
                </tr>
            @endif
            <tr>
                <td>Taxable Amount</td>
                <td class="right">{{ $business['currency_symbol'] }} {{ number_format($bill->taxable_amount, 2) }}</td>
            </tr>
            <tr>
                <td>VAT {{ number_format($vatRate, 0) }}%</td>
                <td class="right">{{ $business['currency_symbol'] }} {{ number_format($bill->vat_amount, 2) }}</td>
            </tr>
            <tr>
                <td><strong>Grand Total</strong></td>
                <td class="right"><strong>{{ $business['currency_symbol'] }} {{ number_format($bill->grand_total, 2) }}</strong></td>
            </tr>
            @forelse ($bill->payments as $payment)
                <tr>
                    <td>{{ config('pos.payments.' . $payment->payment_method, ucfirst($payment->payment_method)) }}</td>
                    <td class="right">{{ $business['currency_symbol'] }} {{ number_format($payment->amount, 2) }}</td>
                </tr>
                @if ($payment->reference_no)
                    <tr>
                        <td>Reference</td>
                        <td class="right">{{ $payment->reference_no }}</td>
                    </tr>
                @endif
            @empty
                <tr>
                    <td>Payment Method</td>
                    <td class="right">{{ config('pos.payments.' . $bill->payment_method, $bill->payment_method ?: '-') }}</td>
                </tr>
            @endforelse
        </table>
    </div>

    <div class="signature">
        Seller signature / authorized stamp
    </div>

    <div class="section center muted">
        <p>Printed by {{ $printedBy }}</p>
        <p>{{ $printedAt->format('Y-m-d H:i:s') }}</p>
    </div>
</body>

</html>
