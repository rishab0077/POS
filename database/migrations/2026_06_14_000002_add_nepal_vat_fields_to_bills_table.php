<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('bills', function (Blueprint $table) {
            if (!Schema::hasColumn('bills', 'invoice_no')) {
                $table->string('invoice_no')->nullable()->unique()->after('bill_id');
            }

            if (!Schema::hasColumn('bills', 'fiscal_year')) {
                $table->string('fiscal_year')->nullable()->after('invoice_no');
            }

            if (!Schema::hasColumn('bills', 'buyer_name')) {
                $table->string('buyer_name')->nullable()->after('customer_name');
            }

            if (!Schema::hasColumn('bills', 'buyer_pan')) {
                $table->string('buyer_pan')->nullable()->after('buyer_name');
            }

            if (!Schema::hasColumn('bills', 'buyer_address')) {
                $table->string('buyer_address')->nullable()->after('buyer_pan');
            }

            if (!Schema::hasColumn('bills', 'taxable_amount')) {
                $table->decimal('taxable_amount', 12, 2)->default(0)->after('discount');
            }

            if (!Schema::hasColumn('bills', 'vat_amount')) {
                $table->decimal('vat_amount', 12, 2)->default(0)->after('taxable_amount');
            }

            if (!Schema::hasColumn('bills', 'service_charge_amount')) {
                $table->decimal('service_charge_amount', 12, 2)->default(0)->after('vat_amount');
            }
        });

        Schema::table('bill_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('bill_orders', 'stock_deducted_at')) {
                $table->timestamp('stock_deducted_at')->nullable()->after('order_id');
            }
        });
    }

    public function down()
    {
        Schema::table('bill_orders', function (Blueprint $table) {
            if (Schema::hasColumn('bill_orders', 'stock_deducted_at')) {
                $table->dropColumn('stock_deducted_at');
            }
        });

        Schema::table('bills', function (Blueprint $table) {
            foreach ([
                'service_charge_amount',
                'vat_amount',
                'taxable_amount',
                'buyer_address',
                'buyer_pan',
                'buyer_name',
                'fiscal_year',
                'invoice_no',
            ] as $column) {
                if (Schema::hasColumn('bills', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
