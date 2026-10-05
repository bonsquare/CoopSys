<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('loan_no')->unique();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->decimal('principal', 14, 2);
            $table->decimal('interest_rate', 5, 2);      // yearly %
            $table->unsignedInteger('term_months');
            $table->decimal('total_interest', 14, 2);
            $table->decimal('total_payable', 14, 2);
            $table->decimal('monthly_amortization', 14, 2);
            $table->decimal('balance', 14, 2)->default(0);
            $table->string('purpose')->nullable();
            $table->string('status')->default('pending'); // pending | approved | released | paid | rejected
            $table->date('applied_at');
            $table->date('approved_at')->nullable();
            $table->date('released_at')->nullable();
            $table->date('maturity_date')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
