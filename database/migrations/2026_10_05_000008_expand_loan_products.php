<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_products', function (Blueprint $table) {
            $table->renameColumn('min_term_months', 'min_term');
            $table->renameColumn('max_term_months', 'max_term');
        });

        Schema::table('loan_products', function (Blueprint $table) {
            // A. Basic information
            $table->string('category')->nullable()->after('name');

            // B. Amount and term
            $table->string('term_unit')->default('months');
            $table->string('payment_frequency')->default('monthly');

            // C. Interest and schedule (existing rows were yearly add-on interest)
            $table->string('interest_type')->default('add_on');
            $table->string('interest_basis')->default('annual');
            $table->string('amortization_method')->default('equal_payment');
            $table->boolean('auto_schedule')->default(true);

            // E. Penalties
            $table->string('penalty_type')->default('none');
            $table->decimal('penalty_value', 14, 2)->default(0);
            $table->string('penalty_basis')->default('per_day');
            $table->unsignedInteger('grace_days')->default(0);

            // F. Requirements
            $table->decimal('min_savings', 14, 2)->default(0);
            $table->decimal('min_shares', 14, 2)->default(0);
            $table->boolean('guarantor_required')->default(false);
            $table->unsignedInteger('guarantors_required')->default(0);
            $table->text('required_documents')->nullable(); // JSON list of keys

            // G. Approval and release
            $table->string('approval_level')->default('manager');
            $table->string('release_method')->default('cash');

            // H. Other settings
            $table->boolean('allow_early_payment')->default(true);
            $table->boolean('early_payment_penalty')->default(false);
            $table->decimal('early_payment_penalty_rate', 5, 2)->default(0);
            $table->boolean('allow_renewal')->default(false);
            $table->boolean('allow_restructuring')->default(false);
        });

        Schema::create('loan_product_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('fixed');            // fixed | percentage | none
            $table->decimal('value', 14, 2)->default(0);
            $table->string('basis')->default('loan_amount');
            $table->string('timing')->default('upon_release');   // upon_release | add_to_payable | first_payment
            $table->boolean('is_required')->default(true);
            $table->boolean('is_editable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Snapshot of the computation, stored on every new loan
        Schema::table('loans', function (Blueprint $table) {
            $table->unsignedInteger('term_value')->nullable();
            $table->string('term_unit')->default('months');
            $table->string('payment_frequency')->default('monthly');
            $table->unsignedInteger('number_of_payments')->default(0);   // 0 = older loan without a stored schedule
            $table->decimal('net_proceeds', 14, 2)->nullable();
            $table->decimal('deductions_total', 14, 2)->default(0);      // taken out when the loan is released
            $table->decimal('fees_added', 14, 2)->default(0);            // fees added to what the borrower pays back
            $table->text('schedule')->nullable();                        // JSON rows
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['term_value', 'term_unit', 'payment_frequency', 'number_of_payments', 'net_proceeds', 'deductions_total', 'fees_added', 'schedule']);
        });
        Schema::dropIfExists('loan_product_deductions');
        Schema::table('loan_products', function (Blueprint $table) {
            $table->dropColumn([
                'category', 'term_unit', 'payment_frequency', 'interest_type', 'interest_basis', 'amortization_method', 'auto_schedule',
                'penalty_type', 'penalty_value', 'penalty_basis', 'grace_days', 'min_savings', 'min_shares', 'guarantor_required',
                'guarantors_required', 'required_documents', 'approval_level', 'release_method', 'allow_early_payment',
                'early_payment_penalty', 'early_payment_penalty_rate', 'allow_renewal', 'allow_restructuring',
            ]);
        });
        Schema::table('loan_products', function (Blueprint $table) {
            $table->renameColumn('min_term', 'min_term_months');
            $table->renameColumn('max_term', 'max_term_months');
        });
    }
};
