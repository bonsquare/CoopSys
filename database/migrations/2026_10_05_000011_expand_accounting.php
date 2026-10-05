<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Accounts the system posts to automatically. They cannot be deleted or deactivated. */
    private const SYSTEM = ['1010', '1011', '1020', '1030', '2010', '2020', '3010', '4010', '4020', '4030', '5010'];

    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('entry_no')->nullable()->unique();           // JV-2026-0001 for manual entries
            $table->string('source')->default('system');                // system (posted from a transaction) | manual
            $table->string('status')->default('posted');                // posted | reversed
            $table->foreignId('reversal_of')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->string('memo')->nullable();
        });

        DB::table('accounts')->whereIn('code', self::SYSTEM)->update(['is_system' => true]);
        DB::table('accounts')->where('code', '1010')->update(['name' => 'Cash on Hand']);

        // A few more accounts that are useful for manual entries
        $now = now();
        foreach ([
            ['1040', 'Property and Equipment', 'asset'],
            ['2030', 'Accounts Payable', 'liability'],
            ['3020', 'Retained Surplus', 'equity'],
            ['5020', 'Salaries and Benefits', 'expense'],
            ['5030', 'Rent and Utilities', 'expense'],
            ['5040', 'Bank Charges', 'expense'],
        ] as [$code, $name, $type]) {
            DB::table('accounts')->insertOrIgnore(['code' => $code, 'name' => $name, 'type' => $type, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropColumn('memo');
        });
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_of');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['entry_no', 'source', 'status']);
        });
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['description', 'is_active', 'is_system']);
        });
    }
};
