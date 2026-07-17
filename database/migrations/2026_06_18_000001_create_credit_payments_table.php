<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            if (!Schema::hasColumn('bills', 'credit_settled_at')) {
                $table->timestamp('credit_settled_at')->nullable()->after('credit_paid_amount');
            }
        });

        Schema::create('credit_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained('bills')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 50);
            $table->timestamp('paid_at');
            $table->string('reference_no')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['paid_at', 'payment_method']);
            $table->index(['bill_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_payments');

        Schema::table('bills', function (Blueprint $table) {
            if (Schema::hasColumn('bills', 'credit_settled_at')) {
                $table->dropColumn('credit_settled_at');
            }
        });
    }
};
