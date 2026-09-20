<?php

use App\Enums\AdminPermission;
use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = Carbon::now();

        foreach (AdminPermission::cases() as $permission) {
            Permission::query()->updateOrCreate(
                [
                    'name' => $permission->value,
                    'guard_name' => 'web',
                ],
                [
                    'display_name' => $permission->displayName(),
                    'description' => $permission->description(),
                    'status' => 'active',
                    'introduced_at' => $now,
                    'retired_at' => null,
                ]
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', AdminPermission::values())
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
