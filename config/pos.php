<?php

return [
    'business' => [
        'name' => env('BUSINESS_NAME', env('APP_NAME', 'Restaurant POS')),
        'address' => env('BUSINESS_ADDRESS', 'Kathmandu, Nepal - TO BE UPDATED'),
        'pan' => env('BUSINESS_PAN', 'TO BE UPDATED'),
        'vat' => env('BUSINESS_VAT', 'TO BE UPDATED'),
    ],

    'invoice' => [
        'format' => env('INVOICE_FORMAT', 'thermal_80mm'),
        'fiscal_year_bill_prefix_auto' => (bool) env('FISCAL_YEAR_BILL_PREFIX_AUTO', true),
        'buyer_pan_required_above' => 10000,
    ],

    'printing' => [
        'lock_minutes' => (int) env('PRINT_JOB_LOCK_MINUTES', 2),
        'station_offline_after_seconds' => (int) env('PRINT_STATION_OFFLINE_AFTER_SECONDS', 90),
        'receipt' => [
            'customer_copy_enabled' => (bool) env('RECEIPT_CUSTOMER_COPY_ENABLED', true),
            'restaurant_copy_enabled' => (bool) env('RECEIPT_RESTAURANT_COPY_ENABLED', false),
            'default_copies' => env('RECEIPT_DEFAULT_COPIES', 'customer'),
            'footer_text' => env('RECEIPT_FOOTER_TEXT'),
            'width' => env('RECEIPT_WIDTH', '80mm'),
        ],
    ],

    'tax' => [
        'vat_rate' => (float) env('VAT_RATE', 13),
        'vat_inclusive' => (bool) env('VAT_INCLUSIVE', true),
        'service_charge_enabled' => (bool) env('SERVICE_CHARGE_ENABLED', false),
        'service_charge_rate' => (float) env('SERVICE_CHARGE_RATE', 0),
    ],

    'payments' => [
        'cash' => 'Cash',
        'card' => 'Card',
        'esewa' => 'eSewa',
        'fonepay' => 'Fonepay',
        'khalti' => 'Khalti',
        'credit' => 'Credit',
    ],

    'legacy_payments' => [
        'fonepay_qr' => 'Fonepay QR',
        'bank_transfer' => 'Bank Transfer',
    ],

    'purchase_payments' => [
        'cash' => 'Cash',
        'card' => 'Card',
        'esewa' => 'eSewa',
        'fonepay' => 'Fonepay',
        'khalti' => 'Khalti',
        'credit' => 'Credit',
    ],

    'inventory_units' => [
        'kg' => 'Kilogram (kg)',
        'g' => 'Gram (g)',
        'ltr' => 'Litre (ltr)',
        'ml' => 'Millilitre (ml)',
        'pcs' => 'Pieces (pcs)',
        'bottle' => 'Bottle',
        'packet' => 'Packet',
        'box' => 'Box',
        'dozen' => 'Dozen',
        'carton' => 'Carton',
        'crate' => 'Crate',
        'can' => 'Can',
        'jar' => 'Jar',
        'bag' => 'Bag',
        'roll' => 'Roll',
        'portion' => 'Portion',
    ],

    'discount_reasons' => [
        'owner_approval' => 'Owner approval',
        'manager_approval' => 'Manager approval',
        'service_issue' => 'Service issue',
        'regular_customer' => 'Regular customer',
        'promotion' => 'Promotion',
        'other' => 'Other',
    ],

    'stock_removal_reasons' => [
        'damaged' => 'Damaged',
        'staff_use' => 'Staff Use',
        'wastage' => 'Wastage',
        'other' => 'Other',
    ],

    'cancellation_reasons' => [
        'Item Unavailable',
        'Customer Changed Mind',
        'Wrong Item Sent',
        'Duplicate Entry',
        'Other',
    ],
];
