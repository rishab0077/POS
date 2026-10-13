<?php

/**
 * File-level doc comment for KitchenHelper.php
 *
 * PHP version 7.4.3
 *
 * @category Helpers
 * @package  App\Helpers
 * @author   Pavan Vattikala <pavanvattikala54@gmail.com>
 * @license  MIT License
 */

namespace App\Helpers;

use App\Models\Order;
use App\Models\InvoiceSequence;
use Illuminate\Support\Facades\DB;

/**
 * Class KitchenHelper
 * 
 * @category Helper
 * @package  App\Helpers
 * @author   Pavan Vattikala <pavanvattikala54@gmail.com>
 * @license  MIT License
 */
class KitchenHelper
{

    /**
     * Generate a Kitchen Order Ticket (KOT) ID.
     *
     * @return string
     */
    public static function generateKOT()
    {
        $date = now()->format('Ymd');
        return DB::transaction(function () use ($date) {
            $prefix = "KOT-$date-";
            $lastNumber = Order::where('KOT', 'like', $prefix . '%')
                ->selectRaw("MAX(CAST(SUBSTRING(KOT, ?) AS UNSIGNED)) AS last_number", [strlen($prefix) + 1])
                ->value('last_number');
            InvoiceSequence::insertOrIgnore([
                'fiscal_year' => "kot:$date", 'next_number' => (int) $lastNumber + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $sequence = InvoiceSequence::where('fiscal_year', "kot:$date")->lockForUpdate()->firstOrFail();
            $number = $sequence->next_number;
            $sequence->increment('next_number');
            return $prefix . $number;
        });
    }

    public static function printKOT($kot)
    {
        //handle print kot service
    }

    public static function createKOT($kot)
    {
    }

    public static function getKOTOrders($kot, ?string $productionArea = null)
    {
        $order = Order::with('orderDetails.menu.category')
            ->where('kot', $kot)->first();

        $orderDetails = collect([]);

        if (!$order) {
            return $orderDetails;
        }

        foreach ($order->orderDetails as $details) {
            if ($productionArea && self::productionAreaForOrderDetail($details) !== $productionArea) {
                continue;
            }

            $itemName = $details->menu->name;
            $quantity = $details->quantity;

            $orderDetails->put($itemName, ($orderDetails->get($itemName, 0) + $quantity));
        }

        return $orderDetails;
    }

    private static function productionAreaForOrderDetail($details): string
    {
        $destinations = $details->menu?->category
            ?->pluck('print_destination')
            ->filter()
            ->values();

        if ($destinations?->contains('bot')) {
            return 'bar';
        }

        return 'kitchen';
    }
}
