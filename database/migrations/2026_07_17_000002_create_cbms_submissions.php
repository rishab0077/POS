<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbms_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_invoice_snapshot_id')
                ->unique()
                ->constrained('fiscal_invoice_snapshots')
                ->restrictOnDelete();
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
        Schema::dropIfExists('cbms_submissions');
    }
};
