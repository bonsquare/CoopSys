<?php

use App\Models\Loan;
use App\Models\Transaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A collection is one receipt: it can hold several allocation lines
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no')->unique();
            $table->date('collection_date');
            $table->foreignId('member_id')->constrained();
            $table->string('collection_type')->default('loan_payment');
            $table->string('payment_method')->default('cash');
            $table->string('payment_ref')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);      // net money received (or paid out)
            $table->string('direction')->default('in');              // in = money received, out = money paid out
            $table->decimal('cash_tendered', 14, 2)->nullable();
            $table->decimal('change_due', 14, 2)->default(0);
            $table->string('remarks')->nullable();
            $table->string('status')->default('draft');              // draft | posted | cancelled
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('posted_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['status', 'collection_date']);
        });

        Schema::create('collection_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(1);
            $table->string('allocation_type');
            $table->foreignId('loan_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->decimal('due_amount', 14, 2)->nullable();        // what was due when the row was suggested
            $table->decimal('balance_after', 14, 2)->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('collection_id')->nullable()->constrained()->nullOnDelete();
            $table->text('breakdown')->nullable();                   // how an older lump-sum loan payment was split
        });

        // Loans keep track of what has been paid toward each part
        Schema::table('loans', function (Blueprint $table) {
            $table->decimal('principal_paid', 14, 2)->default(0);
            $table->decimal('interest_paid', 14, 2)->default(0);
            $table->decimal('fees_paid', 14, 2)->default(0);
            $table->decimal('penalty_paid', 14, 2)->default(0);
        });

        // New accounts used by the new kinds of collection
        $now = now();
        foreach ([
            ['1011', 'Cash in Bank', 'asset'],
            ['1030', 'Other Receivables', 'asset'],
            ['2020', 'Insurance Payable', 'liability'],
            ['4030', 'Penalty Income', 'income'],
        ] as [$code, $name, $type]) {
            DB::table('accounts')->insertOrIgnore(['code' => $code, 'name' => $name, 'type' => $type, 'created_at' => $now, 'updated_at' => $now]);
        }

        // Work out the parts already paid on existing loans from their payments
        foreach (Loan::whereIn('status', ['released', 'paid'])->get() as $loan) {
            foreach (Transaction::where('loan_id', $loan->id)->where('type', 'loan_payment')->where('status', 'completed')->orderBy('id')->get() as $payment) {
                [$principal, $interest, $fees] = $loan->splitPayment((float) $payment->amount);
                $loan->principal_paid += $principal;
                $loan->interest_paid += $interest;
                $loan->fees_paid += $fees;
                $payment->update(['breakdown' => json_encode(['principal' => $principal, 'interest' => $interest, 'fees' => $fees])]);
            }
            $loan->save();
        }
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['principal_paid', 'interest_paid', 'fees_paid', 'penalty_paid']);
        });
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collection_id');
            $table->dropColumn('breakdown');
        });
        Schema::dropIfExists('collection_lines');
        Schema::dropIfExists('collections');
    }
};
