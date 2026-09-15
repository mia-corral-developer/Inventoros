<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Permission;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use App\Models\Inventory\StockAuditRound;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * QA fixture for the multi-round (blind count) audit feature.
 *
 * Creates three warehouse counters who can ONLY count (count_stock_audits),
 * plus an in-progress audit with two assigned rounds and a handful of items,
 * so the physical count can be exercised from the mobile capture screen.
 *
 * Re-runnable: it wipes the previous QA audit (matched by name) first.
 */
class MultiRoundDemoSeeder extends Seeder
{
    private const AUDIT_NAME = 'QA Conteo Ciego — Bodega Principal';

        /** Supplied at runtime via QA_COUNTER_PASS; falls back to a dev default. */
        private function secret(): string
        {
            return getenv('QA_COUNTER_PASS') ?: 'labqa1234';
        }

    public function run(): void
    {
        $org = Organization::firstOrFail();

        // --- Counter role: can count, cannot administer audits -------------
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

        // --- Three counters ------------------------------------------------
        $counters = [
            'c1' => 'Carlos Ramírez (C1)',
            'c2' => 'Beatriz Ospina (C2)',
            'c3' => 'Julián Torres (desempate)',
        ];

        $users = [];
        foreach ($counters as $handle => $name) {
            $user = User::updateOrCreate(
                ['email' => "{$handle}@contador.lab"],
                [
                    'name' => $name,
                    'password' => Hash::make($this->secret()),
                    'organization_id' => $org->id,
                    'role' => 'member',
                    'email_verified_at' => now(),
                ]
            );
            $user->roles()->syncWithoutDetaching([$role->id]);
            $users[$handle] = $user;
        }

        // --- Location + products -------------------------------------------
        $location = ProductLocation::firstOrCreate(
            ['organization_id' => $org->id, 'code' => 'QA-BOD'],
            ['name' => 'Bodega Principal (QA)', 'is_active' => true]
        );

        $items = [
            ['QA-BULTO-01', 'Bulto de café 25 kg', 120],
            ['QA-BULTO-02', 'Bulto de azúcar 50 kg', 80],
            ['QA-CAJA-03', 'Caja de aceite (12 und)', 45],
            ['QA-CAJA-04', 'Caja de leche en polvo (24 und)', 60],
            ['QA-UNI-05', 'Panel LED 40W', 210],
            ['QA-UNI-06', 'Guante industrial (par)', 340],
        ];

        $products = [];
        foreach ($items as [$sku, $name, $stock]) {
            $products[] = Product::updateOrCreate(
                ['organization_id' => $org->id, 'sku' => $sku],
                [
                    'name' => $name,
                    'price' => 10000,
                    'currency' => 'COP',
                    'stock' => $stock,
                    'min_stock' => 5,
                    'location_id' => $location->id,
                    'is_active' => true,
                ]
            );
        }

        // --- Fresh QA audit with two assigned rounds -----------------------
        StockAudit::where('organization_id', $org->id)
            ->where('name', self::AUDIT_NAME)
            ->forceDelete();

        $audit = StockAudit::create([
            'organization_id' => $org->id,
            'audit_number' => StockAudit::generateAuditNumber($org->id),
            'name' => self::AUDIT_NAME,
            'description' => 'Fixture de QA: 3 contadores, 2 rondas ciegas + desempate.',
            'status' => 'in_progress',
            'audit_type' => 'full',
            'rounds_total' => 2,
            'blind_count' => true,
            'warehouse_location_id' => $location->id,
            'started_at' => now(),
            'created_by' => User::where('organization_id', $org->id)->where('role', 'admin')->value('id')
                ?? $users['c1']->id,
        ]);

        foreach ($products as $product) {
            StockAuditItem::create([
                'stock_audit_id' => $audit->id,
                'product_id' => $product->id,
                'location_id' => $location->id,
                'system_quantity' => $product->stock,
                'status' => 'pending',
            ]);
        }

        // Round 1 → Carlos, Round 2 → Beatriz. Tiebreak (C3) opens on demand.
        foreach ([1 => $users['c1']->id, 2 => $users['c2']->id] as $n => $uid) {
            StockAuditRound::create([
                'stock_audit_id' => $audit->id,
                'round_number' => $n,
                'label' => 'C'.$n,
                'is_tiebreak' => false,
                'assigned_to' => $uid,
                'status' => 'open',
            ]);
        }

        $this->command?->info("QA audit #{$audit->id} «{$audit->audit_number}» ready: ".count($products).' items, 2 rounds.');
        $this->command?->info('Counters: c1@contador.lab / c2@contador.lab / c3@contador.lab');
    }
}