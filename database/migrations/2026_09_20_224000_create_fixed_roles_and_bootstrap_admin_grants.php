<?php

use App\Enums\AccountState;
use App\Enums\AdminPermission;
use App\Enums\UserType;
use App\Models\PermissionGrantHistory;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            // 1. Reject unknown roles or invalid guards in roles table
            $unknownRoles = DB::table('roles')
                ->whereNotIn('name', UserType::values())
                ->orWhere('guard_name', '!=', 'web')
                ->pluck('name');

            if ($unknownRoles->isNotEmpty()) {
                throw new RuntimeException(
                    'Unexpected pre-existing roles detected in roles table: '.$unknownRoles->implode(', ')
                );
            }

            // 2. Reject existing users with invalid or unclassified user_type
            $invalidUsers = DB::table('users')
                ->whereNotIn('user_type', UserType::values())
                ->pluck('id');

            if ($invalidUsers->isNotEmpty()) {
                throw new RuntimeException(
                    'Invalid user_type detected on existing users: '.$invalidUsers->implode(', ')
                );
            }

            // 3. Reject conflicting existing role assignments
            $roleAssignments = DB::table('model_has_roles')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->join('users', 'model_has_roles.model_id', '=', 'users.id')
                ->where('model_has_roles.model_type', User::class)
                ->select('model_has_roles.model_id', 'roles.name as role_name', 'users.user_type')
                ->get();

            $multipleRoles = $roleAssignments->groupBy('model_id')->filter(fn ($assignments) => $assignments->count() > 1)->keys();
            if ($multipleRoles->isNotEmpty()) {
                throw new RuntimeException(
                    'Conflicting existing role assignments: users with multiple roles detected: '.$multipleRoles->implode(', ')
                );
            }

            $mismatchedRoles = $roleAssignments->filter(fn ($assignment) => $assignment->role_name !== $assignment->user_type);
            if ($mismatchedRoles->isNotEmpty()) {
                throw new RuntimeException(
                    'Conflicting existing role assignments: mismatched roles detected for users: '.$mismatchedRoles->pluck('model_id')->implode(', ')
                );
            }

            // 4. Create the three fixed web roles from UserType
            foreach (UserType::cases() as $userType) {
                Role::findOrCreate($userType->value, 'web');
            }

            // 5. Backfill exactly one matching role for every existing user
            $rolesByName = Role::where('guard_name', 'web')->get()->keyBy('name');
            $existingUsers = User::all();

            foreach ($existingUsers as $user) {
                $role = $rolesByName->get($user->user_type->value);
                if ($role && ! $user->hasRole($role)) {
                    $user->assignRole($role);
                }
            }

            // 6. Select the earliest active Admin by created_at, then id
            $bootstrapAdmin = User::query()
                ->where('user_type', UserType::Admin->value)
                ->where('account_state', AccountState::Active->value)
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->first();

            if ($bootstrapAdmin !== null) {
                // Explicitly grant all 13 AdminPermission catalogue items directly
                $bootstrapAdmin->syncPermissions(AdminPermission::values());

                // Record append-only bootstrap grant-history records sharing one batch identifier
                $batchId = (string) Str::uuid();
                $now = Carbon::now();
                $historyRecords = [];

                foreach (AdminPermission::cases() as $permission) {
                    $historyRecords[] = [
                        'batch_id' => $batchId,
                        'user_id' => $bootstrapAdmin->id,
                        'permission_code' => $permission->value,
                        'action' => 'grant',
                        'source' => 'system_seed',
                        'actor_user_id' => null,
                        'reason' => 'Initial bootstrap grant of complete permission catalogue to earliest active Administrator',
                        'permission_version' => 1,
                        'created_at' => $now,
                    ];
                }

                PermissionGrantHistory::insert($historyRecords);
            }

            // 7. Ensure role_has_permissions pivot remains completely empty
            DB::table('role_has_permissions')->delete();

            // 8. Clear Spatie's permission cache
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            // Delete bootstrap grant history
            PermissionGrantHistory::where('source', 'system_seed')->delete();

            // Detach direct permissions from bootstrap admin
            $admins = User::where('user_type', UserType::Admin->value)->get();
            foreach ($admins as $admin) {
                $admin->syncPermissions([]);
            }

            // Detach roles from all users
            DB::table('model_has_roles')->where('model_type', User::class)->delete();

            // Delete the fixed roles
            Role::where('guard_name', 'web')
                ->whereIn('name', UserType::values())
                ->delete();

            // Clear Spatie's permission cache
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }
};
