<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Counterparties (Companies)
        Schema::table('counterparties', function (Blueprint $table) {
            $table->unsignedBigInteger('b24_id')->nullable()->unique()->after('id');
            $table->timestamp('last_update_from_1c')->nullable()->after('last_sync_at');
            $table->timestamp('last_pulled_at')->nullable()->after('last_update_from_1c');
        });

        // Contact Persons
        Schema::table('contact_persons', function (Blueprint $table) {
            $table->unsignedBigInteger('b24_id')->nullable()->unique()->after('id');
            $table->timestamp('last_update_from_1c')->nullable()->after('last_sync_at');
            $table->timestamp('last_pulled_at')->nullable()->after('last_update_from_1c');
        });

        // Contracts
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('b24_id')->nullable()->unique()->after('id');
            $table->timestamp('last_pulled_at')->nullable();
        });

        // Products
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('b24_id')->nullable()->unique()->after('id');
            $table->timestamp('last_pulled_at')->nullable();
        });

        // Customer Orders (Invoices)
        Schema::table('customer_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('b24_id')->nullable()->unique()->after('id');
            $table->timestamp('last_pulled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('counterparties', function (Blueprint $table) {
            $table->dropColumn(['b24_id', 'last_update_from_1c', 'last_pulled_at']);
        });

        Schema::table('contact_persons', function (Blueprint $table) {
            $table->dropColumn(['b24_id', 'last_update_from_1c', 'last_pulled_at']);
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['b24_id', 'last_update_from_1c', 'last_pulled_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['b24_id', 'last_update_from_1c', 'last_pulled_at']);
        });

        Schema::table('customer_orders', function (Blueprint $table) {
            $table->dropColumn(['b24_id', 'last_update_from_1c', 'last_pulled_at']);
        });
    }
};
