<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'source_table_id')) {
                $table->foreignId('source_table_id')->nullable()->after('table_id')->constrained('tables')->nullOnDelete();
            }
        });

        if (Schema::hasColumn('orders', 'source_table_id')) {
            DB::table('orders')
                ->whereNull('source_table_id')
                ->whereNotNull('table_id')
                ->update(['source_table_id' => DB::raw('table_id')]);
        }

        Schema::table('bills', function (Blueprint $table) {
            if (!Schema::hasColumn('bills', 'source_table_id')) {
                $table->foreignId('source_table_id')->nullable()->after('table_id')->constrained('tables')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            if (Schema::hasColumn('bills', 'source_table_id')) {
                $table->dropForeign(['source_table_id']);
                $table->dropColumn('source_table_id');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'source_table_id')) {
                $table->dropForeign(['source_table_id']);
                $table->dropColumn('source_table_id');
            }
        });
    }
};
