<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained('bills')->restrictOnDelete();
            $table->string('payment_method', 50);
            $table->decimal('amount', 12, 2);
            $table->string('reference_no', 100)->nullable();
            $table->timestamp('received_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['bill_id', 'payment_method']);
            $table->index(['received_at', 'payment_method']);
        });

        Schema::table('fiscal_invoice_snapshots', function (Blueprint $table) {
            $table->json('payment_breakdown')->nullable()->after('payment_method');
        });

        DB::table('bills')
            ->where(function ($query) {
                $query->whereNotNull('locked_at')
                    ->orWhereNotNull('printed_at')
                    ->orWhere('status', 'closed');
            })
            ->where(function ($query) {
                $query->whereNull('payment_method')
                    ->orWhere('payment_method', '!=', 'credit');
            })
            ->where('grand_total', '>', 0)
            ->orderBy('id')
            ->chunkById(500, function ($bills) {
                $now = now();
                $rows = $bills->map(fn ($bill) => [
                    'bill_id' => $bill->id,
                    'payment_method' => $bill->payment_method ?: 'cash',
                    'amount' => $bill->grand_total,
                    'reference_no' => null,
                    'received_at' => $bill->locked_at ?? $bill->printed_at ?? $bill->created_at ?? $now,
                    'recorded_by' => $bill->locked_by ?? $bill->printed_by ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('bill_payments')->insert($rows);
            });
    }

    public function down(): void
    {
        Schema::table('fiscal_invoice_snapshots', function (Blueprint $table) {
            $table->dropColumn('payment_breakdown');
        });

        Schema::dropIfExists('bill_payments');
    }
};
