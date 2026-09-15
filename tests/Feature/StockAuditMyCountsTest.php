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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4 (operator view): the counter's landing list and the post-login
 * destination. A counter must only ever see the rounds assigned to them.
 */
class StockAuditMyCountsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Org', 'email' => 'o@o.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
    }

    private function user(string $email, array $permissions, string $role = 'member'): User
    {
        $u = User::create([
            'name' => $email, 'email' => $email, 'password' => bcrypt('secret-pass'),
            'organization_id' => $this->org->id, 'role' => $role,
            'email_verified_at' => now(),
        ]);
        if ($permissions) {
            $r = Role::create([
                'name' => 'role-'.$email, 'slug' => 'role-'.str_replace('@', '-', $email),
                'organization_id' => $this->org->id, 'is_system' => false, 'permissions' => $permissions,
            ]);
            $u->roles()->attach($r);
        }

        return $u;
    }

    private function auditWithRounds(array $roundAssignments): StockAudit
    {
        $loc = ProductLocation::create(['organization_id' => $this->org->id, 'name' => 'L', 'code' => 'L', 'is_active' => true]);
        $product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'S'.uniqid(), 'name' => 'P',
            'price' => 1, 'currency' => 'USD', 'stock' => 5, 'min_stock' => 0,
            'location_id' => $loc->id, 'is_active' => true,
        ]);

        $audit = StockAudit::create([
            'organization_id' => $this->org->id, 'audit_number' => 'SA-'.uniqid(),
            'name' => 'A', 'status' => 'in_progress', 'audit_type' => 'full',
            'rounds_total' => count($roundAssignments), 'blind_count' => true,
            'created_by' => $this->org->id,
        ]);
        StockAuditItem::create([
            'stock_audit_id' => $audit->id, 'product_id' => $product->id,
            'system_quantity' => 5, 'status' => 'pending',
        ]);

        foreach ($roundAssignments as $n => $uid) {
            StockAuditRound::create([
                'stock_audit_id' => $audit->id, 'round_number' => $n, 'label' => 'C'.$n,
                'is_tiebreak' => false, 'assigned_to' => $uid, 'status' => 'open',
            ]);
        }

        return $audit;
    }

    public function test_counter_sees_only_their_own_assigned_rounds(): void
    {
        $c1 = $this->user('c1@x.com', ['view_stock_audits', 'count_stock_audits']);
        $c2 = $this->user('c2@x.com', ['view_stock_audits', 'count_stock_audits']);

        $this->auditWithRounds([1 => $c1->id, 2 => $c2->id]);

        $this->actingAs($c1)
            ->get(route('my-counts'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('StockAudits/MyCounts')
                ->has('counts', 1)
                ->where('counts.0.round_label', 'C1')
            );
    }

    public function test_my_counts_requires_the_count_permission(): void
    {
        $nobody = $this->user('n@x.com', ['view_products']);

        $this->actingAs($nobody)
            ->get(route('my-counts'))
            ->assertForbidden();
    }

    public function test_rounds_of_other_organizations_are_excluded(): void
    {
        $c1 = $this->user('c1@x.com', ['view_stock_audits', 'count_stock_audits']);
        $this->auditWithRounds([1 => $c1->id]);

        $other = Organization::create(['name' => 'Other', 'email' => 'x@x.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $otherUser = User::create(['name' => 'o', 'email' => 'o@other.com', 'password' => bcrypt('x'), 'organization_id' => $other->id, 'role' => 'member']);

        // A round for a user in another org must never leak into c1's list.
        $this->actingAs($c1)
            ->get(route('my-counts'))
            ->assertInertia(fn ($page) => $page->has('counts', 1)->where('counts.0.round_label', 'C1'));

        $this->assertNotSame($c1->organization_id, $otherUser->organization_id);
    }

    public function test_counter_only_user_logs_in_to_my_counts(): void
    {
        $this->user('counter@x.com', ['view_stock_audits', 'count_stock_audits']);

        $this->post('/login', ['email' => 'counter@x.com', 'password' => 'secret-pass'])
            ->assertRedirect(route('my-counts', absolute: false));
    }

    public function test_office_user_logs_in_to_dashboard(): void
    {
        $this->user('boss@x.com', ['view_products', 'view_orders'], 'admin');

        $this->post('/login', ['email' => 'boss@x.com', 'password' => 'secret-pass'])
            ->assertRedirect(route('dashboard', absolute: false));
    }
}