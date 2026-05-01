<?php

use App\Support\TenantLoginPath;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('login_path', 120)->nullable()->unique()->after('status');
        });

        DB::table('tenants')
            ->orderBy('id')
            ->select(['id', 'slug'])
            ->chunk(100, function ($tenants): void {
                foreach ($tenants as $tenant) {
                    $candidate = TenantLoginPath::defaultForTenant((int) $tenant->id, (string) $tenant->slug);
                    $suffix = 1;
                    $path = $candidate;

                    while (DB::table('tenants')->where('login_path', $path)->exists()) {
                        $path = $candidate.'-'.$suffix;
                        $suffix++;
                    }

                    DB::table('tenants')
                        ->where('id', $tenant->id)
                        ->update(['login_path' => $path]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['login_path']);
            $table->dropColumn('login_path');
        });
    }
};
