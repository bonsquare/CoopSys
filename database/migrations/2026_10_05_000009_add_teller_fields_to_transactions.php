<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('payment_method')->default('cash');   // cash | check | bank_transfer | ewallet | other
            $table->string('payment_ref')->nullable();           // check number, transfer number ...
            $table->decimal('balance_after', 14, 2)->nullable(); // running balance of the account after this transaction
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'payment_ref', 'balance_after']);
        });
    }
};
