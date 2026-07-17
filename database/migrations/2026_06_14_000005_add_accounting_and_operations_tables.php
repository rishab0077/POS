<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('vat_pan_no')->nullable();
            $table->text('address')->nullable();
            $table->decimal('opening_balance', 12, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('billing_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('shift_no')->unique();
            $table->foreignId('opened_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_cash', 12, 2)->default(0);
            $table->decimal('closing_cash', 12, 2)->nullable();
            $table->decimal('cash_total', 12, 2)->default(0);
            $table->decimal('card_total', 12, 2)->default(0);
            $table->decimal('wallet_total', 12, 2)->default(0);
            $table->decimal('credit_total', 12, 2)->default(0);
            $table->decimal('vat_total', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('void_total', 12, 2)->default(0);
            $table->json('category_totals')->nullable();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('invoice_no');
            $table->date('bill_date');
            $table->date('due_date')->nullable();
            $table->enum('status', ['draft', 'posted', 'void'])->default('draft');
            $table->enum('payment_status', ['pending', 'partial', 'paid'])->default('pending');
            $table->string('payment_method')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('taxable_amount', 12, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('balance_amount', 12, 2)->default(0);
            $table->string('attachment_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['supplier_id', 'invoice_no']);
        });

        Schema::create('purchase_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();
            $table->string('item_name');
            $table->string('unit', 30);
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('taxable_amount', 12, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('purchase_invoice_id')->nullable()->constrained('purchase_invoices')->cascadeOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 12, 2);
            $table->string('payment_method');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('stock_items', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_items', 'default_supplier_id')) {
                $table->foreignId('default_supplier_id')->nullable()->after('category_id')->constrained('suppliers')->nullOnDelete();
            }
            if (!Schema::hasColumn('stock_items', 'average_unit_cost')) {
                $table->decimal('average_unit_cost', 12, 2)->default(0)->after('low_stock_threshold');
            }
            if (!Schema::hasColumn('stock_items', 'last_purchase_cost')) {
                $table->decimal('last_purchase_cost', 12, 2)->default(0)->after('average_unit_cost');
            }
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_movements', 'reason')) {
                $table->string('reason')->nullable()->after('reference_id');
            }
            if (!Schema::hasColumn('stock_movements', 'running_balance_after')) {
                $table->decimal('running_balance_after', 12, 3)->nullable()->after('quantity');
            }
        });

        Schema::table('menus', function (Blueprint $table) {
            if (!Schema::hasColumn('menus', 'production_area')) {
                $table->string('production_area', 20)->default('kitchen')->after('type');
            }
        });

        Schema::table('restaurants', function (Blueprint $table) {
            if (!Schema::hasColumn('restaurants', 'bar_printer')) {
                $table->string('bar_printer')->nullable()->after('kitchen_printer');
            }
            if (!Schema::hasColumn('restaurants', 'counter_printer')) {
                $table->string('counter_printer')->nullable()->after('biller_printer');
            }
        });

        Schema::table('bills', function (Blueprint $table) {
            if (!Schema::hasColumn('bills', 'billing_shift_id')) {
                $table->foreignId('billing_shift_id')->nullable()->after('id')->constrained('billing_shifts')->nullOnDelete();
            }
            if (!Schema::hasColumn('bills', 'printed_at')) {
                $table->timestamp('printed_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('bills', 'printed_by')) {
                $table->foreignId('printed_by')->nullable()->after('printed_at')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('bills', 'locked_at')) {
                $table->timestamp('locked_at')->nullable()->after('printed_by');
            }
            if (!Schema::hasColumn('bills', 'locked_by')) {
                $table->foreignId('locked_by')->nullable()->after('locked_at')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('bills', 'discount_reason')) {
                $table->string('discount_reason')->nullable()->after('discount');
            }
            if (!Schema::hasColumn('bills', 'discount_approved_by')) {
                $table->foreignId('discount_approved_by')->nullable()->after('discount_reason')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('bills', 'credit_customer_name')) {
                $table->string('credit_customer_name')->nullable()->after('payment_method');
            }
            if (!Schema::hasColumn('bills', 'credit_customer_contact')) {
                $table->string('credit_customer_contact')->nullable()->after('credit_customer_name');
            }
            if (!Schema::hasColumn('bills', 'credit_status')) {
                $table->string('credit_status')->nullable()->after('credit_customer_contact');
            }
            if (!Schema::hasColumn('bills', 'credit_paid_amount')) {
                $table->decimal('credit_paid_amount', 12, 2)->default(0)->after('credit_status');
            }
        });

        DB::statement('ALTER TABLE bills MODIFY bill_amount DECIMAL(12, 2) NOT NULL');
        DB::statement('ALTER TABLE bills MODIFY discount DECIMAL(12, 2) NOT NULL DEFAULT 0.00');
        DB::statement('ALTER TABLE bills MODIFY grand_total DECIMAL(12, 2) NOT NULL');

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('orders', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('orders', 'cancellation_reason')) {
                $table->string('cancellation_reason')->nullable()->after('cancelled_by');
            }
            if (!Schema::hasColumn('orders', 'cancellation_notes')) {
                $table->text('cancellation_notes')->nullable()->after('cancellation_reason');
            }
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'cancelled_by')) {
                $table->dropConstrainedForeignId('cancelled_by');
            }
            foreach (['cancelled_at', 'cancellation_reason', 'cancellation_notes'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('bills', function (Blueprint $table) {
            foreach ([
                'billing_shift_id',
                'printed_by',
                'locked_by',
                'discount_approved_by',
            ] as $column) {
                if (Schema::hasColumn('bills', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            foreach ([
                'printed_at',
                'locked_at',
                'discount_reason',
                'credit_customer_name',
                'credit_customer_contact',
                'credit_status',
                'credit_paid_amount',
            ] as $column) {
                if (Schema::hasColumn('bills', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('restaurants', function (Blueprint $table) {
            foreach (['bar_printer', 'counter_printer'] as $column) {
                if (Schema::hasColumn('restaurants', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('menus', function (Blueprint $table) {
            if (Schema::hasColumn('menus', 'production_area')) {
                $table->dropColumn('production_area');
            }
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            foreach (['reason', 'running_balance_after'] as $column) {
                if (Schema::hasColumn('stock_movements', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('stock_items', function (Blueprint $table) {
            if (Schema::hasColumn('stock_items', 'default_supplier_id')) {
                $table->dropConstrainedForeignId('default_supplier_id');
            }
            foreach (['average_unit_cost', 'last_purchase_cost'] as $column) {
                if (Schema::hasColumn('stock_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('purchase_invoice_items');
        Schema::dropIfExists('purchase_invoices');
        Schema::dropIfExists('billing_shifts');
        Schema::dropIfExists('suppliers');
    }
};
