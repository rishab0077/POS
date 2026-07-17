<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_invoice_items', function (Blueprint $table) {
            $table->json('inventory_consumption')->nullable()->after('category_name');
        });

        Schema::table('fiscal_credit_note_items', function (Blueprint $table) {
            $table->json('inventory_restore_quantities')->nullable()->after('category_name');
            $table->timestamp('inventory_restored_at')->nullable()->after('created_at');
            $table->foreignId('inventory_restored_by')->nullable()->after('inventory_restored_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_credit_note_items', function (Blueprint $table) {
            $table->dropForeign(['inventory_restored_by']);
            $table->dropColumn(['inventory_restore_quantities', 'inventory_restored_at', 'inventory_restored_by']);
        });

        Schema::table('fiscal_invoice_items', function (Blueprint $table) {
            $table->dropColumn('inventory_consumption');
        });
    }
};
