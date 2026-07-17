<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('bills', function (Blueprint $table) {
            if (!Schema::hasColumn('bills', 'discount_type')) {
                $table->string('discount_type', 20)->nullable()->after('discount');
            }

            if (!Schema::hasColumn('bills', 'discount_value')) {
                $table->decimal('discount_value', 12, 2)->default(0)->after('discount_type');
            }
        });
    }

    public function down()
    {
        Schema::table('bills', function (Blueprint $table) {
            foreach (['discount_value', 'discount_type'] as $column) {
                if (Schema::hasColumn('bills', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

