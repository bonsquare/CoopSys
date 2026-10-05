<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('report_group')->nullable();   // the section of a financial statement the account is shown under
        });

        foreach (config('accounting.groups') as $code => $group) {
            DB::table('accounts')->where('code', $code)->update(['report_group' => $group]);
        }
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('report_group');
        });
    }
};
