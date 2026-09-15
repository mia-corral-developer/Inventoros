<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditCount;
use App\Models\Inventory\StockAuditItem;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\StockAuditRoundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-round (blind count) stock auditing.
 *
 * The expensive part to get right is the service, not the schema: atomic
 * round closing, the three resolution outcomes (agreement / tiebreak /
 * divergent), and legacy single-round compatibility.
 */
class StockAuditMultiRoundTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private ProductLocation $location;

    private User $admin;

    private User $counterA;

    private User $counterB;

    private StockAuditRoundService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // The CheckInstallation middleware redirects every web route to the
        // installer until the app is flagged as installed.
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Audit Org', 'email' => 'audit@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $this->location = ProductLocation::create([
            'organization_id' => $this->org->id, 'name' => 'Main', 'code' => 'MAIN', 'is_active' => true,
        ]);

        $adminRole = Role::create([
            'name' => 'Audit Admin', 'slug' => 'audit-admin', 'organization_id' => $this->org->id,
            'is_system' => false, 'permissions' => ['manage_stock_audits', 'view_stock_audits'],
        ]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'a@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->admin->roles()->attach($adminRole);

        $this->counterA = User::create([
            'name' => 'Counter A', 'email' => 'ca@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);

        $this->counterB = User::create([
            'name' => 'Counter B', 'email' => 'cb@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);

        $this->service = app(StockAuditRoundService::class);
    }

    private function product(string $sku, int $stock): Product
    {
        return Product::create([
            'organization_id' => $this->org->id, 'sku' => $sku, 'name' => $sku,
            'price' => 10, 'currency' => 'USD', 'stock' => $stock, 'min_stock' => 0,
            'location_id' => $this->location->id, 'is_active' => true,
        ]);
    }

    private function audit(int $roundsTotal = 2, bool $blind = true, string $status = 'in_progress'): StockAudit
    {
        return StockAudit::create([
            'organization_id' => $this->org->id,
            'audit_number' => StockAudit::generateAuditNumber($this->org->id),
            'name' => 'Audit '.$roundsTotal,
            'status' => $status,
            'audit_type' => 'full',
            'rounds_total' => $roundsTotal,
            'blind_count' => $blind,
            'created_by' => $this->admin->id,
        ]);
    }

    private function item(StockAudit $audit, Product $product, int $systemQty): StockAuditItem
    {
        return StockAuditItem::create([
            'stock_audit_id' => $audit->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'system_quantity' => $systemQty,
            'status' => 'pending',
        ]);
    }

    public function test_open_rounds_creates_n_regular_rounds(): void
    {
        $audit = $this->audit(3);

        $rounds = $this->service->openRounds($audit);

        $this->assertCount(3, $rounds);
        $this->assertSame([1, 2, 3], $rounds->pluck('round_number')->all());
        $this->assertFalse($rounds->contains('is_tiebreak', true));
        $this->assertSame(['C1', 'C2', 'C3'], $rounds->pluck('label')->all());
    }

    public function test_assignments_are_stored_per_round(): void
    {
        $audit = $this->audit(2);

        $rounds = $this->service->openRounds($audit, [
            1 => $this->counterA->id,
            2 => $this->counterB->id,
        ]);

        $this->assertSame($this->counterA->id, $rounds->firstWhere('round_number', 1)->assigned_to);
        $this->assertSame($this->counterB->id, $rounds->firstWhere('round_number', 2)->assigned_to);
    }

    public function test_record_count_keeps_rounds_separate(): void
    {
        $audit = $this->audit(2);
        $this->service->openRounds($audit);
        $item = $this->item($audit, $this->product('SKU-A', 10), 10);

        $this->service->recordCount($audit, $item, 1, 12, $this->counterA);
        $this->service->recordCount($audit, $item, 2, 9, $this->counterB);

        $this->assertSame(2, StockAuditCount::where('stock_audit_item_id', $item->id)->count());
        $this->assertSame(12, (int) StockAuditCount::where(['stock_audit_item_id' => $item->id, 'round_number' => 1])->value('counted_quantity'));
        $this->assertSame(9, (int) StockAuditCount::where(['stock_audit_item_id' => $item->id, 'round_number' => 2])->value('counted_quantity'));
    }

    public function test_record_count_overwrites_same_round(): void
    {
        $audit = $this->audit(2);
        $this->service->openRounds($audit);
        $item = $this->item($audit, $this->product('SKU-A', 10), 10);

        $this->service->recordCount($audit, $item, 1, 12, $this->counterA);
        $this->service->recordCount($audit, $item, 1, 11, $this->counterA);

        $this->assertSame(1, StockAuditCount::where('stock_audit_item_id', $item->id)->count());
        $this->assertSame(11, (int) StockAuditCount::where('stock_audit_item_id', $item->id)->value('counted_quantity'));
    }

    public function test_record_count_rejects_closed_round(): void
    {
        $audit = $this->audit(2);
        $this->service->openRounds($audit);
        $item = $this->item($audit, $this->product('SKU-A', 10), 10);

        $this->service->closeRound($audit, 1, $this->admin, true);

        $this->expectException(\RuntimeException::class);
        $this->service->recordCount($audit, $item, 1, 12, $this->counterA);
    }

    public function test_closing_a_round_twice_throws(): void
    {
        $audit = $this->audit(2);
        $this->service->openRounds($audit);

        $this->service->closeRound($audit, 1, $this->admin, true);

        $this->expectException(\RuntimeException::class);
        $this->service->closeRound($audit, 1, $this->admin, true);
    }

    public function test_only_admin_can_reopen_a_round(): void
    {
        $audit = $this->audit(2);
        $this->service->openRounds($audit);
        $this->service->closeRound($audit, 1, $this->admin, true);

        $this->expectException(\RuntimeException::class);
        $this->service->reopenRound($audit, 1, $this->counterA, false);
    }

    public function test_resolve_marks_agreement_when_rounds_match(): void
    {
        $audit = $this->audit(2);
        $this->service->openRounds($audit);
        $item = $this->item($audit, $this->product('SKU-A', 10), 10);

        $this->service->recordCount($audit, $item, 1, 11, $this->counterA);
        $this->service->recordCount($audit, $item, 2, 11, $this->counterB);

        $stats = $this->service->resolve($audit);

        $item->refresh();
        $this->assertSame(1, $stats['agreement']);
        $this->assertSame(0, $stats['divergent']);
        $this->assertSame(11, $item->resolved_quantity);
        $this->assertSame(StockAuditRoundService::METHOD_AGREEMENT, $item->resolution_method);
        $this->assertSame('verified', $item->status);
    }

    public function test_resolve_uses_tiebreak_when_it_matches_one_round(): void
    {
        $audit = $this->audit(2);
        $this->service->openRounds($audit);
        $item = $this->item($audit, $this->product('SKU-A', 10), 10);

        $this->service->recordCount($audit, $item, 1, 10, $this->counterA);
        $this->service->recordCount($audit, $item, 2, 12, $this->counterB);

        $tiebreak = $this->service->openTiebreakRound($audit);
        $this->assertTrue($tiebreak->is_tiebreak);
        $this->assertSame(3, $tiebreak->round_number);

        // Tiebreak agrees with C2 → resolved = 12, method = tiebreak.
        $this->service->recordCount($audit, $item, 3, 12, $this->admin, null, true);

        $stats = $this->service->resolve($audit);

        $item->refresh();
        $this->assertSame(1, $stats['tiebreak']);
        $this->assertSame(12, $item->resolved_quantity);
        $this->assertSame(StockAuditRoundService::METHOD_TIEBREAK, $item->resolution_method);
    }

    public function test_resolve_flags_divergent_when_tiebreak_matches_neither(): void
    {
        $audit = $this->audit(2);
        $this->service->openRounds($audit);
        $item = $this->item($audit, $this->product('SKU-A', 10), 10);

        $this->service->recordCount($audit, $item, 1, 10, $this->counterA);
        $this->service->recordCount($audit, $item, 2, 12, $this->counterB);

        $this->service->openTiebreakRound($audit);
        // Tiebreak = 3-way split → neither C1 nor C2 → needs manual resolution.
        $this->service->recordCount($audit, $item, 3, 7, $this->admin, null, true);

        $stats = $this->service->resolve($audit);

        $item->refresh();
        $this->assertSame(1, $stats['divergent']);
        $this->assertNull($item->resolved_quantity);
        $this->assertNull($item->resolution_method);
        $this->assertSame(StockAuditRoundService::ITEM_DIVERGENT, $item->status);
    }

    public function test_resolve_flags_divergent_when_a_regular_round_is_missing(): void
    {
        $audit = $this->audit(2);
        $this->service->openRounds($audit);
        $item = $this->item($audit, $this->product('SKU-A', 10), 10);

        // Only C1 counted — C2 has no capture yet.
        $this->service->recordCount($audit, $item, 1, 10, $this->counterA);

        $stats = $this->service->resolve($audit);

        $this->assertSame(1, $stats['uncounted']);
        $this->assertNull($item->fresh()->resolved_quantity);
    }

    public function test_resolve_does_not_clobber_a_manual_decision(): void
    {
        $audit = $this->audit(2);
        $this->service->openRounds($audit);
        $item = $this->item($audit, $this->product('SKU-A', 10), 10);

        $this->service->recordCount($audit, $item, 1, 10, $this->counterA);
        $this->service->recordCount($audit, $item, 2, 12, $this->counterB);

        $this->service->resolveManually($item, 11, $this->admin);

        $this->service->resolve($audit);

        $item->refresh();
        $this->assertSame(11, $item->resolved_quantity);
        $this->assertSame(StockAuditRoundService::METHOD_MANUAL, $item->resolution_method);
    }

    public function test_legacy_single_round_uses_counted_quantity(): void
    {
        $audit = $this->audit(1, false);
        $item = $this->item($audit, $this->product('SKU-A', 10), 10);
        $item->update(['counted_quantity' => 8, 'status' => 'counted']);

        // resolve() is a no-op for a single-round audit.
        $stats = $this->service->resolve($audit);
        $this->assertSame(0, $stats['resolved']);

        $this->assertSame(8, $this->service->finalQuantity($item->fresh()));
    }

    public function test_complete_creates_one_adjustment_from_resolved_quantity(): void
    {
        $product = $this->product('SKU-A', 10);
        $audit = $this->audit(2);
        $this->service->openRounds($audit);
        $item = $this->item($audit, $product, 10);

        // C1 = 10, C2 = 12 → tiebreak 12 → resolved = 12 (diff +2).
        $this->service->recordCount($audit, $item, 1, 10, $this->counterA);
        $this->service->recordCount($audit, $item, 2, 12, $this->counterB);
        $this->service->openTiebreakRound($audit);
        $this->service->recordCount($audit, $item, 3, 12, $this->admin, null, true);

        $this->actingAs($this->admin)
            ->post(route('stock-audits.complete', $audit))
            ->assertRedirect();

        $this->assertSame(1, StockAdjustment::where('reference_type', StockAudit::class)
            ->where('reference_id', $audit->id)->count());
        $this->assertSame(12, (int) $product->fresh()->stock);
        $this->assertSame('completed', $audit->fresh()->status);
    }

    public function test_complete_is_blocked_while_items_are_divergent(): void
    {
        $product = $this->product('SKU-A', 10);
        $audit = $this->audit(2);
        $this->service->openRounds($audit);
        $item = $this->item($audit, $product, 10);

        // C1 = 10, C2 = 12, tiebreak = 7 → 3-way split, unresolved.
        $this->service->recordCount($audit, $item, 1, 10, $this->counterA);
        $this->service->recordCount($audit, $item, 2, 12, $this->counterB);
        $this->service->openTiebreakRound($audit);
        $this->service->recordCount($audit, $item, 3, 7, $this->admin, null, true);

        $this->actingAs($this->admin)
            ->post(route('stock-audits.complete', $audit))
            ->assertRedirect()
            ->assertSessionHas('error');

        // Nothing was reconciled and the audit is still open.
        $this->assertSame('in_progress', $audit->fresh()->status);
        $this->assertSame(0, StockAdjustment::where('reference_id', $audit->id)->count());
        $this->assertSame(10, (int) $product->fresh()->stock);
    }

    public function test_complete_succeeds_after_a_divergent_item_is_resolved_manually(): void
    {
        $product = $this->product('SKU-A', 10);
        $audit = $this->audit(2);
        $this->service->openRounds($audit);
        $item = $this->item($audit, $product, 10);

        $this->service->recordCount($audit, $item, 1, 10, $this->counterA);
        $this->service->recordCount($audit, $item, 2, 12, $this->counterB);
        $this->service->openTiebreakRound($audit);
        $this->service->recordCount($audit, $item, 3, 7, $this->admin, null, true);

        // Admin decides 11 by hand → no longer divergent.
        $this->service->resolveManually($item, 11, $this->admin);

        $this->actingAs($this->admin)
            ->post(route('stock-audits.complete', $audit))
            ->assertRedirect();

        $this->assertSame('completed', $audit->fresh()->status);
        $this->assertSame(1, StockAdjustment::where('reference_id', $audit->id)->count());
        $this->assertSame(11, (int) $product->fresh()->stock);
    }
}