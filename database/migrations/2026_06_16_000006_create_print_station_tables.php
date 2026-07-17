<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_stations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->boolean('enabled')->default(true);
            $table->json('printer_map')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('version')->nullable();
            $table->string('last_ip')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->unique();
            $table->string('type');
            $table->string('printer_key');
            $table->nullableMorphs('source');
            $table->string('payload_format')->default('escpos_base64');
            $table->longText('payload');
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->foreignId('print_station_id')->nullable()->constrained('print_stations')->nullOnDelete();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'printer_key']);
            $table->index('locked_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('print_stations');
    }
};
