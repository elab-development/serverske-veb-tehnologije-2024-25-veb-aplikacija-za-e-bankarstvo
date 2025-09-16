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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['debit', 'credit', 'transfer'])->index();
            $table->bigInteger('amount_minor');
            $table->enum('currency', ['RSD', 'EUR', 'USD', 'CHF', 'JPY'])->index();
            $table->string('description')->nullable()->index();
            $table->decimal('fx_rate', 18, 8)->nullable();
            $table->enum('fx_base',  ['RSD', 'EUR', 'USD', 'CHF', 'JPY'])->nullable();
            $table->enum('fx_quote', ['RSD', 'EUR', 'USD', 'CHF', 'JPY'])->nullable();
            $table->timestamp('executed_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
