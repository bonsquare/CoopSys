<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('savings_account_no')->nullable()->index();   // system-generated, e.g. SA-000007
        });

        // Give existing members a number, counting separately inside each organization
        $next = [];
        foreach (DB::table('members')->orderBy('id')->get(['id', 'organization_id']) as $m) {
            $n = $next[$m->organization_id] = ($next[$m->organization_id] ?? 0) + 1;
            DB::table('members')->where('id', $m->id)->update(['savings_account_no' => sprintf('SA-%06d', $n)]);
        }
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('savings_account_no');
        });
    }
};
