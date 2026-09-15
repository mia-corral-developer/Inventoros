<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use App\Models\Inventory\StockAuditRound;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\StockAuditRoundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 + 3: the admin surface for multi-round audits — creating an audit
 * with rounds/assignments, opening the tiebreak round, and resolving a
 * divergent item by hand.
 */
class StockAuditMultiRoundEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Org', 'email' => 'org@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $role = Role::create([
            'name' => 'Audit Admin', 'slug' => 'audit-admin', 'organization_id' => $this->org->id,
            'is_system' => false,
            'permissions' => ['manage_stock_audits', 'view_stock_audits', 'create_stock_audits'],
        ]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'a@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->admin->roles()->attach($role);
    }

    private function product(string $sku, int $stock): Product
    {
        $loc = ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => 'Main', 'code' => 'MAIN', 'is_active' => true,
        ]);

        return Product::create([
            'organization_id' => $this->org->id, 'sku' => $sku, 'name' => $sku,
            'price' => 10, 'currency' => 'USD', 'stock' => $stock, 'min_stock' => 0,
            'location_id' => $loc->id, 'is_active' => true,
        ]);
    }

    public function test_store_creates_rounds_and_assignments(): void
    {
        $product = $this->product('SKU-A', 10);

        $this->actingAs($this->admin)->post(route('stock-audits.store'), [
            'name' => 'Blind count',
            'audit_type' => 'full',
            'rounds_total' => 2,
            'blind_count' => true,
            'product_ids' => [$product->id],
            'assignments' => [1 => $this->admin->id, 2 => ''],
        ])->assertRedirect();

        $audit = StockAudit::latest('id')->first();
        $this->assertSame(2, $audit->rounds_total);
        $this->assertTrue($audit->blind_count);
        $this->assertCount(2, $audit->rounds);
        $this->assertSame($this->admin->id, $audit->rounds->firstWhere('round_number', 1)->assigned_to);
        $this->assertNull($audit->rounds->firstWhere('round_number', 2)->assigned_to);
    }

    public function test_store_defaults_to_single_round(): void
    {
        $product = $this->product('SKU-B', 5);

        $this->actingAs($this->admin)->post(route('stock-audits.store'), [
            'name' => 'Plain', 'audit_type' => 'cycle', 'product_ids' => [$product->id],
        ])->assertRedirect();

        $audit = StockAudit::latest('id')->first();
        $this->assertSame(1, $audit->rounds_total);
        $this->assertCount(0, $audit->rounds); // no round rows for a single count
    }

    public function test_open_tiebreak_creates_the_next_round(): void
    {
        $audit = $this->auditWithRounds(2);

        $this->actingAs($this->admin)
            ->postJson(route('stock-audits.tiebreak', $audit))
            ->assertOk()
            ->assertJsonPath('round.round_number', 3)
            ->assertJsonPath('round.is_tiebreak', true);

        $this->assertTrue($audit->fresh()->rounds->firstWhere('round_number', 3)->is_tiebreak);
    }

    public function test_open_tiebreak_is_idempotent(): void
    {
        $audit = $this->auditWithRounds(2);

        $this->actingAs($this->admin)->postJson(route('stock-audits.tiebreak', $audit))->assertOk();
        $this->actingAs($this->admin)->postJson(route('stock-audits.tiebreak', $audit))->assertOk();

        $this->assertSame(1, $audit->fresh()->rounds->where('is_tiebreak', true)->count());
    }

    public function test_resolve_endpoint_sets_manual_resolution(): void
    {
        $audit = $this->auditWithRounds(2);
        $item = $audit->items()->first();

        $this->actingAs($this->admin)
            ->postJson(route('stock-audits.items.resolve', ['stockAudit' => $audit->id, 'item' => $item->id]), [
                'resolved_quantity' => 9,
            ])
            ->assertOk();

        $item->refresh();
        $this->assertSame(9, $item->resolved_quantity);
        $this->assertSame(StockAuditRoundService::METHOD_MANUAL, $item->resolution_method);
    }

    public function test_legacy_audit_has_no_variance_props(): void
    {
        $product = $this->product('SKU-C', 3);
        $audit = StockAudit::create([
            'organization_id' => $this->org->id, 'audit_number' => 'SA-9001', 'name' => 'Legacy',
            'status' => 'in_progress', 'audit_type' => 'full', 'rounds_total' => 1,
            'created_by' => $this->admin->id,
        ]);
        StockAuditItem::create([
            'stock_audit_id' => $audit->id, 'product_id' => $product->id, 'system_quantity' => 3,
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->get(route('stock-audits.show', $audit))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('StockAudits/Show')
                ->where('variance', [])
                ->where('rounds', [])
            );
    }

    private function auditWithRounds(int $rounds): StockAudit
    {
        $product = $this->product('SKU-'.$rounds.'-'.uniqid(), 10);

        $audit = StockAudit::create([
            'organization_id' => $this->org->id,
            'audit_number' => StockAudit::generateAuditNumber($this->org->id),
            'name' => 'Audit', 'status' => 'in_progress', 'audit_type' => 'full',
            'rounds_total' => $rounds, 'blind_count' => true, 'created_by' => $this->admin->id,
        ]);

        StockAuditItem::create([
            'stock_audit_id' => $audit->id, 'product_id' => $product->id,
            'system_quantity' => 10, 'status' => 'pending',
        ]);

        app(StockAuditRoundService::class)->openRounds($audit);

        return $audit->fresh();
    }
}