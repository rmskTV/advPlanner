<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b24_sync_states', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type')->unique(); // 'Company', 'Contact', etc.
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_b24_updated_at')->nullable();
            $table->unsignedInteger('total_pulled')->default(0);
            $table->unsignedInteger('total_created')->default(0);
            $table->unsignedInteger('total_updated')->default(0);
            $table->unsignedInteger('total_errors')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b24_sync_states');
    }
};
