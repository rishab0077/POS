<?php

use App\Enums\TableStatus;
use App\Models\Table;

return [
    'notes' => [
        'less Spicy',
        'more spicy',
        'leg peice',
        'extra masala',
        'less masala'
    ],
    'table_colors' => [
        TableStatus::Available->value => 'grey',
        TableStatus::Running->value => 'blue',
        TableStatus::Printed->value => 'green',
        TableStatus::RunningKOT->value => 'orange',
        TableStatus::Unavailable->value => 'red',
    ],
    'printer' => [
        'pos' => env('BILLER_PRINTER', 'biller'),
        'biller' => env('BILLER_PRINTER', 'biller'),
        'counter' => env('COUNTER_PRINTER', env('BILLER_PRINTER', 'biller')),
        'kitchen' => env('KITCHEN_PRINTER', 'kitchen'),
        'bar' => env('BAR_PRINTER', env('KITCHEN_PRINTER', 'kitchen')),
    ]
];
