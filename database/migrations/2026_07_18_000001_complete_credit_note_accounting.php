<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_credit_notes', function (Blueprint $table) {
            $table->dropForeign(['fiscal_invoice_snapshot_id']);
        });
        Schema::table('fiscal_credit_notes', function (Blueprint $table) {
            $table->dropUnique(['fiscal_invoice_snapshot_id']);
            $table->index('fiscal_invoice_snapshot_id');
            $table->foreign('fiscal_invoice_snapshot_id')
                ->references('id')
                ->on('fiscal_invoice_snapshots')
                ->restrictOnDelete();
        });

        Schema::table('fiscal_invoice_items', function (Blueprint $table) {
            $table->string('category_name')->nullable()->after('item_name');
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->decimal('credit_returned_amount', 12, 2)->default(0)->after('credit_paid_amount');
        });

        Schema::create('fiscal_credit_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_credit_note_id')->constrained('fiscal_credit_notes')->restrictOnDelete();
            $table->foreignId('fiscal_invoice_item_id')->constrained('fiscal_invoice_items')->restrictOnDelete();
            $table->string('item_name');
            $table->string('category_name')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->string('tax_category', 20);
            $table->decimal('vat_rate', 6, 3);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['fiscal_credit_note_id', 'fiscal_invoice_item_id'], 'credit_note_item_unique');
        });

        Schema::create('refund_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_credit_note_id')->constrained('fiscal_credit_notes')->restrictOnDelete();
            $table->string('type', 30);
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 50)->nullable();
            $table->string('reference_no', 100)->nullable();
            $table->timestamp('recorded_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['fiscal_credit_note_id', 'type']);
            $table->index(['recorded_at', 'payment_method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_transactions');
        Schema::dropIfExists('fiscal_credit_note_items');

        Schema::table('bills', fn (Blueprint $table) => $table->dropColumn('credit_returned_amount'));
        Schema::table('fiscal_invoice_items', fn (Blueprint $table) => $table->dropColumn('category_name'));
        Schema::table('fiscal_credit_notes', function (Blueprint $table) {
            $table->dropForeign(['fiscal_invoice_snapshot_id']);
        });
        Schema::table('fiscal_credit_notes', function (Blueprint $table) {
            $table->dropIndex(['fiscal_invoice_snapshot_id']);
            $table->unique('fiscal_invoice_snapshot_id');
            $table->foreign('fiscal_invoice_snapshot_id')
                ->references('id')
                ->on('fiscal_invoice_snapshots')
                ->restrictOnDelete();
        });
    }
};
