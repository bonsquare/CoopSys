<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_products', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->decimal('interest_rate', 5, 2);          // yearly %
            $table->decimal('min_amount', 14, 2);
            $table->decimal('max_amount', 14, 2);
            $table->unsignedInteger('min_term_months');
            $table->unsignedInteger('max_term_months');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->foreignId('loan_product_id')->nullable()->after('member_id')->constrained('loan_products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loan_product_id');
        });
        Schema::dropIfExists('loan_products');
    }
};
