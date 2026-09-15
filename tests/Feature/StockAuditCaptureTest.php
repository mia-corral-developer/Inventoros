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
 * Phase 4: the mobile counter surface. A counter needs only count_stock_audits,
 * sees only their own numbers, and cannot reopen a closed round.
 */
class StockAuditCaptureTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $counterA;

    private User $counterB;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Org', 'email' => 'org@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $countRole = Role::create([
            'name' => 'Counter', 'slug' => 'counter', 'organization_id' => $this->org->id,
            'is_system' => false, 'permissions' => ['count_stock_audits', 'view_stock_audits'],
        ]);

        $this->counterA = User::create([
            'name' => 'Counter A', 'email' => 'a@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $this->counterA->roles()->attach($countRole);

        $this->counterB = User::create([
            'name' => 'Counter B', 'email' => 'b@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $this->counterB->roles()->attach($countRole);
    }

    private function auditWithRounds(int $rounds = 2): StockAudit
    {
        $loc = ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => 'Main', 'code' => 'MAIN', 'is_active' => true,
        ]);
        $product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'SKU-1', 'name' => 'Widget',
            'price' => 10, 'currency' => 'USD', 'stock' => 10, 'min_stock' => 0,
            'location_id' => $loc->id, 'is_active' => true,
        ]);

        $audit = StockAudit::create([
            'organization_id' => $this->org->id,
            'audit_number' => StockAudit::generateAuditNumber($this->org->id),
            'name' => 'Blind', 'status' => 'in_progress', 'audit_type' => 'full',
            'rounds_total' => $rounds, 'blind_count' => true, 'created_by' => $this->counterA->id,
        ]);
        StockAuditItem::create([
            'stock_audit_id' => $audit->id, 'product_id' => $product->id,
            'system_quantity' => 10, 'status' => 'pending',
        ]);

        app(StockAuditRoundService::class)->openRounds($audit, [1 => $this->counterA->id, 2 => $this->counterB->id]);

        return $audit->fresh();
    }

    public function test_capture_requires_count_permission(): void
    {
        $audit = $this->auditWithRounds();

        $stranger = User::create([
            'name' => 'Nope', 'email' => 'n@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);

        // No count_stock_audits → 403 from the middleware.
        $this->actingAs($stranger)->get(route('stock-audits.capture', $audit))->assertForbidden();
    }

    public function test_capture_resolves_the_users_own_assigned_round(): void
    {
        $audit = $this->auditWithRounds();

        $this->actingAs($this->counterA)
            ->get(route('stock-audits.capture', $audit))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('StockAudits/Capture')
                ->where('activeRound.round_number', 1)
            );
    }

    public function test_capture_only_exposes_my_own_count(): void
    {
        $audit = $this->auditWithRounds();
        $item = $audit->items()->first();
        $service = app(StockAuditRoundService::class);

        // A counts 10 in round 1, B counts 12 in round 2.
        $service->recordCount($audit, $item, 1, 10, $this->counterA);
        $service->recordCount($audit, $item, 2, 12, $this->counterB);

        // Counter A must see ONLY their own number (10), never B's 12.
        $this->actingAs($this->counterA)
            ->get(route('stock-audits.capture', $audit))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('items.0.my_count', 10)
            );
    }

    public function test_record_round_count_saves_for_the_assigned_counter(): void
    {
        $audit = $this->auditWithRounds();
        $item = $audit->items()->first();

        $this->actingAs($this->counterA)
            ->postJson(route('stock-audits.rounds.count', ['stockAudit' => $audit->id, 'round' => 1]), [
                'item_id' => $item->id,
                'counted_quantity' => 8,
            ])
            ->assertOk()
            ->assertJsonPath('count.counted_quantity', 8);

        $this->assertDatabaseHas('stock_audit_counts', [
            'stock_audit_item_id' => $item->id, 'round_number' => 1, 'counted_quantity' => 8,
        ]);
    }

    public function test_record_round_count_rejects_another_counters_round(): void
    {
        $audit = $this->auditWithRounds();
        $item = $audit->items()->first();

        // Counter A tries to write into round 2, which belongs to B.
        $this->actingAs($this->counterA)
            ->postJson(route('stock-audits.rounds.count', ['stockAudit' => $audit->id, 'round' => 2]), [
                'item_id' => $item->id,
                'counted_quantity' => 8,
            ])
            ->assertStatus(422);
    }

    public function test_record_round_count_rejects_a_closed_round(): void
    {
        $audit = $this->auditWithRounds();
        $item = $audit->items()->first();
        app(StockAuditRoundService::class)->closeRound($audit, 1, $this->counterA);

        $this->actingAs($this->counterA)
            ->postJson(route('stock-audits.rounds.count', ['stockAudit' => $audit->id, 'round' => 1]), [
                'item_id' => $item->id, 'counted_quantity' => 8,
            ])
            ->assertStatus(422);
    }

    public function test_assigned_counter_can_close_their_round(): void
    {
        $audit = $this->auditWithRounds();

        $this->actingAs($this->counterA)
            ->postJson(route('stock-audits.rounds.close', ['stockAudit' => $audit->id, 'round' => 1]))
            ->assertOk();

        $this->assertSame('closed', $audit->fresh()->rounds->firstWhere('round_number', 1)->status);
    }

    public function test_counter_cannot_reopen_a_closed_round(): void
    {
        $audit = $this->auditWithRounds();
        app(StockAuditRoundService::class)->closeRound($audit, 1, $this->counterA);

        // Reopen is gated by manage_stock_audits — a plain counter has 403.
        $this->actingAs($this->counterA)
            ->postJson(route('stock-audits.rounds.reopen', ['stockAudit' => $audit->id, 'round' => 1]))
            ->assertForbidden();
    }

    public function test_capture_redirects_for_a_single_round_audit(): void
    {
        $audit = $this->auditWithRounds(2);
        $audit->update(['rounds_total' => 1]);

        $this->actingAs($this->counterA)
            ->get(route('stock-audits.capture', $audit))
            ->assertRedirect(route('stock-audits.show', $audit));
    }
}