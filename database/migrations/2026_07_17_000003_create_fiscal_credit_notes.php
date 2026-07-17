<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_invoice_snapshot_id')
                ->unique()
                ->constrained('fiscal_invoice_snapshots')
                ->restrictOnDelete();
            $table->string('credit_note_no')->unique();
            $table->string('fiscal_year');
            $table->timestamp('issued_at');
            $table->string('reason', 500);
            $table->string('refund_method', 50);
            $table->decimal('total_sales', 12, 2);
            $table->decimal('taxable_sales', 12, 2);
            $table->decimal('tax_exempted_sales', 12, 2)->default(0);
            $table->decimal('vat', 12, 2);
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operator_name');
            $table->char('document_hash', 64)->unique();
            $table->json('payload');
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('response_code', 10)->nullable();
            $table->text('last_response')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_credit_notes');
    }
};
