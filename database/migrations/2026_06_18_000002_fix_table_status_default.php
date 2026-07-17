<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tables')
            ->where('status', 'avaliable')
            ->update(['status' => 'available']);

        Schema::table('tables', function (Blueprint $table) {
            $table->string('status')->default('available')->change();
        });
    }

    public function down(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->string('status')->default('avaliable')->change();
        });
    }
};
