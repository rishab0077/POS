<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_invoice_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->unique()->constrained('bills')->restrictOnDelete();
            $table->string('invoice_no')->unique();
            $table->string('fiscal_year');
            $table->timestamp('invoice_at');
            $table->string('seller_name');
            $table->string('seller_address')->nullable();
            $table->string('seller_phone')->nullable();
            $table->string('seller_email')->nullable();
            $table->string('seller_tax_registration');
            $table->string('currency_symbol', 10)->default('NPR');
            $table->string('buyer_name')->nullable();
            $table->string('buyer_pan')->nullable();
            $table->string('buyer_address')->nullable();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('service_charge', 12, 2)->default(0);
            $table->decimal('taxable_sales', 12, 2);
            $table->decimal('tax_exempted_sales', 12, 2)->default(0);
            $table->decimal('vat_rate', 6, 3);
            $table->decimal('vat', 12, 2);
            $table->decimal('total_sales', 12, 2);
            $table->string('payment_method')->nullable();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operator_name');
            $table->char('document_hash', 64)->unique();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('fiscal_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_invoice_snapshot_id')
                ->constrained('fiscal_invoice_snapshots')
                ->restrictOnDelete();
            $table->unsignedBigInteger('source_order_detail_id')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->string('tax_category', 20)->default('standard');
            $table->decimal('vat_rate', 6, 3);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_invoice_items');
        Schema::dropIfExists('fiscal_invoice_snapshots');
    }
};
