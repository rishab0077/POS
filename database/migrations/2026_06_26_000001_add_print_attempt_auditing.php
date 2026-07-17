<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->unsignedInteger('max_attempts')->default(3)->after('attempts');
            $table->timestamp('last_attempted_at')->nullable()->after('max_attempts');
            $table->foreignId('retried_by')->nullable()->after('last_error')->constrained('users')->nullOnDelete();
            $table->timestamp('retried_at')->nullable()->after('retried_by');
        });

        DB::table('print_jobs')
            ->whereIn('status', ['pending', 'processing'])
            ->where('attempts', '>=', 3)
            ->update(['max_attempts' => DB::raw('attempts + 1')]);

        Schema::create('print_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('print_job_id')->constrained('print_jobs')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->foreignId('print_station_id')->nullable()->constrained('print_stations')->nullOnDelete();
            $table->string('printer_name')->nullable();
            $table->string('status');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['print_job_id', 'attempt_number']);
            $table->index(['print_job_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_attempts');

        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retried_by');
            $table->dropColumn([
                'max_attempts',
                'last_attempted_at',
                'retried_at',
            ]);
        });
    }
};
