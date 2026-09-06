<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('loyalty_eligible')->default(false)->after('print_destination');
        });

        Schema::table('order_details', function (Blueprint $table) {
            $table->unsignedInteger('loyalty_reward_quantity')->default(0)->after('unit_price');
            $table->decimal('loyalty_original_unit_price', 12, 2)->nullable()->after('loyalty_reward_quantity');
            $table->foreignId('loyalty_redeemed_by')->nullable()->after('loyalty_original_unit_price')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('loyalty_redeemed_at')->nullable()->after('loyalty_redeemed_by');
        });

        Schema::table('fiscal_invoice_items', function (Blueprint $table) {
            $table->decimal('original_unit_price', 12, 2)->nullable()->after('unit_price');
            $table->string('pricing_reason', 40)->nullable()->after('original_unit_price');
            $table->string('approved_by_name')->nullable()->after('pricing_reason');
            $table->timestamp('approved_at')->nullable()->after('approved_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_invoice_items', function (Blueprint $table) {
            $table->dropColumn(['original_unit_price', 'pricing_reason', 'approved_by_name', 'approved_at']);
        });

        Schema::table('order_details', function (Blueprint $table) {
            $table->dropForeign(['loyalty_redeemed_by']);
            $table->dropColumn([
                'loyalty_reward_quantity',
                'loyalty_original_unit_price',
                'loyalty_redeemed_by',
                'loyalty_redeemed_at',
            ]);
        });

        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('loyalty_eligible'));
    }
};
