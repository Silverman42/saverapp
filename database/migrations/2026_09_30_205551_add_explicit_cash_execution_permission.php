<?php

use App\Enums\AdminPermission;
use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('permission_grant_histories')->where('permission_code', AdminPermission::CashExecute->value)->where('source', 'system_seed')->exists()) {
            throw new RuntimeException('Cash execution has unprovable bootstrap authority; resolve its grant provenance before migration.');
        }
        $code = AdminPermission::CashExecute;
        $permission = Permission::query()->firstOrCreate(['name' => $code->value, 'guard_name' => 'web'],
            ['display_name' => $code->displayName(), 'description' => $code->description(), 'status' => 'active', 'introduced_at' => now()]);
        if (DB::table('role_has_permissions')->where('permission_id', $permission->id)->exists()) {
            throw new RuntimeException('Cash execution cannot be inherited from a role.');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Cash execution authority history requires a forward migration.');
    }
};
