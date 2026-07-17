<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('fiscal_year')->unique();
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('invoice_sequences');
    }
};
