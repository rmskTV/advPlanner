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
        Schema::create('vk_ads_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            $table->foreignId('vk_ads_account_id')->constrained()->onDelete('cascade');
            $table->text('access_token');
            $table->enum('token_type', ['agency', 'client'])->default('agency')
                ->comment('Тип токена: агентский или клиентский');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at');
            $table->json('scopes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->index(['vk_ads_account_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vk_ads_tokens');
    }
};
