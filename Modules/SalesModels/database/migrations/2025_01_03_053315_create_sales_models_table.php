<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sales_models', function (Blueprint $table) {
            $table->id();
            $table->uuid()->index();
            $table->integer('organisation_id')->index();
            //$table->integer('contragent_id')->index()->nullable();
            $table->string('name');
            $table->float('percent')->default(0);
            $table->float('guarantee')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_models');
    }
};
