<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Permission;
use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Operator (counter-only) user for the shared lab environment.
 * Login redirects to /my-counts because the user has ONLY count_stock_audits.
 * Re-runnable: updateOrCreate by email.
 */
class OperatorSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'edwin@whitelabel.lat';
        $pass  = getenv('OP_PASS') ?: 'Operario2026*';

        $org = Organization::firstOrFail();

        $role = Role::firstOrCreate(
            ['organization_id' => $org->id, 'slug' => 'warehouse-counter'],
            [
                'name' => 'Warehouse Counter',
                'is_system' => false,
                'permissions' => [
                    Permission::VIEW_STOCK_AUDITS->value,
                    Permission::COUNT_STOCK_AUDITS->value,
                ],
            ]
        );

        // Guarantee the role carries the count permission even if it pre-existed.
        $perms = array_values(array_unique(array_merge(
            $role->permissions ?? [],
            [Permission::VIEW_STOCK_AUDITS->value, Permission::COUNT_STOCK_AUDITS->value]
        )));
        $role->permissions = $perms;
        $role->save();

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Edwin (Operador)',
                'password' => Hash::make($pass),
                'organization_id' => $org->id,
                'role' => 'member',
                'email_verified_at' => now(),
            ]
        );
        $user->roles()->syncWithoutDetaching([$role->id]);

        $all = collect($user->roles()->get())
            ->flatMap(fn ($r) => $r->permissions ?? [])
            ->unique()->values()->all();

        $this->command?->info("USER_ID={$user->id}");
        $this->command?->info("EMAIL={$user->email}");
        $this->command?->info('ROLES=' . $user->roles()->pluck('slug')->implode(','));
        $this->command?->info('PERMS=' . implode(',', $all));
        $this->command?->info('HASH_OK=' . (Hash::check($pass, $user->password) ? 'yes' : 'no'));
    }
}
