<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** table => [old single-column unique to replace with (organization_id, column)] */
    private array $tables = [
        'users' => 'email',
        'members' => 'member_no',
        'loans' => 'loan_no',
        'transactions' => 'reference_no',
        'accounts' => 'code',
        'journal_entries' => 'entry_no',
        'loan_products' => 'code',
        'collections' => 'receipt_no',
        'settings' => 'key',
        'audit_logs' => null,
    ];

    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();               // the Organization ID people type at sign-in
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('status')->default('active');    // active | suspended
            $table->string('plan')->default('trial');       // see config/coop.php plans
            $table->date('trial_ends_at')->nullable();
            $table->timestamps();
        });

        foreach ($this->tables as $name => $unique) {
            Schema::table($name, function (Blueprint $table) use ($unique) {
                $table->foreignId('organization_id')->nullable()->after('id')->constrained('organizations')->cascadeOnDelete();
                if ($unique) {
                    $table->dropUnique([$unique]);
                    $table->unique(['organization_id', $unique]);
                }
            });
        }

        // An existing single-organization installation becomes the first organization
        if (DB::table('users')->exists() || DB::table('members')->exists()) {
            $name = DB::table('settings')->where('key', 'coop_name')->value('value') ?: 'My Cooperative';
            $id = DB::table('organizations')->insertGetId([
                'code' => 'CS-'.strtoupper(Str::random(6)),
                'name' => $name,
                'status' => 'active',
                'plan' => 'standard',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach (array_keys($this->tables) as $table) {
                DB::table($table)->update(['organization_id' => $id]);
            }
        } else {
            // Fresh install: earlier migrations pre-loaded a global chart of accounts that belongs to nobody.
            // Every organization now gets its own chart when it is created.
            DB::table('accounts')->whereNull('organization_id')->delete();
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name => $unique) {
            Schema::table($name, function (Blueprint $table) use ($unique) {
                if ($unique) {
                    $table->dropUnique(['organization_id', $unique]);
                    $table->unique([$unique]);
                }
                $table->dropConstrainedForeignId('organization_id');
            });
        }
        Schema::dropIfExists('organizations');
    }
};
