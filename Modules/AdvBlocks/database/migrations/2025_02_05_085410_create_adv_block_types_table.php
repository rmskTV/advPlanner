<?php

use App\Enum\AccountingUnitsEnum;
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
        Schema::create('adv_block_types', function (Blueprint $table) {
            $table->id();
            $table->uuid()->index();
            $table->string('name')->default('');
            $table->boolean('is_with_exact_time')->default(false);
            $table->enum('accounting_unit', AccountingUnitsEnum::getValuesArray())->default(AccountingUnitsEnum::PIECE);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('advBlockTypes');
    }
};
