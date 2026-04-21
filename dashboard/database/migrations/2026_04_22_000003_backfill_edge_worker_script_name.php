<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('dashboard_settings')
            ->where('key', 'worker_script_name')
            ->whereIn('value', ['verifysky-edge-staging', 'verifysky-edge-production', ''])
            ->update(['value' => 'verifysky-edge']);
    }

    public function down(): void
    {
        // Intentionally left blank. This is a forward-only operational backfill.
    }
};
