<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockAudit\StoreStockAuditRequest;
use App\Http\Requests\StockAudit\UpdateStockAuditRequest;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditCount;
use App\Models\Inventory\StockAuditItem;
use App\Models\User;
use App\Services\StockAuditRoundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing stock audits and cycle counting.
 *
 * Handles listing, creating, viewing, editing, starting, completing,
 * and counting stock audit records for inventory management.
 */
class StockAuditController extends Controller
{
    /**
     * Display a listing of stock audits.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $query = StockAudit::with(['warehouseLocation', 'creator'])
            ->withCount('items')
            ->forOrganization($organizationId)
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('audit_number', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->when($request->input('status'), function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($request->input('audit_type'), function ($query, $type) {
                $query->where('audit_type', $type);
            })
            ->latest();

        $audits = $query->paginate(20)->withQueryString();

        return Inertia::render('StockAudits/Index', [
            'audits' => $audits,
            'filters' => $request->only(['search', 'status', 'audit_type']),
            'statuses' => [
                'draft' => 'Draft',
                'in_progress' => 'In Progress',
                'completed' => 'Completed',
                'cancelled' => 'Cancelled',
            ],
            'auditTypes' => [
                'full' => 'Full Audit',
                'cycle' => 'Cycle Count',
                'spot' => 'Spot Check',
            ],
        ]);
    }

    /**
     * Show the form for creating a new stock audit.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function create(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $locations = ProductLocation::forOrganization($organizationId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $products = Product::forOrganization($organizationId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'stock']);

        return Inertia::render('StockAudits/Create', [
            'locations' => $locations,
            'products' => $products,
            'users' => $this->selectableCounters($organizationId),
            'auditTypes' => [
                'full' => 'Full Audit',
                'cycle' => 'Cycle Count',
                'spot' => 'Spot Check',
            ],
        ]);
    }

    /**
     * Users that can be assigned to a counting round: anyone in the org.
     *
     * Deliberately not gated on manage_stock_audits — a counter only needs to
     * be able to record counts, not to administer audits.
     *
     * @return \Illuminate\Support\Collection<int, array{id:int,name:string}>
     */
    private function selectableCounters(int $organizationId)
    {
        return User::where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Normalise the per-round counter map coming from the form.
     *
     * The form sends `assignments: { "1": 5, "2": "" }`; empty values mean
     * "any counter may take this round", so they collapse to null.
     *
     * @param  array<int|string, int|string|null>  $assignments
     * @return array<int, int|null>
     */
    private function normalizeAssignments(array $assignments): array
    {
        $out = [];
        foreach ($assignments as $round => $userId) {
            $round = (int) $round;
            if ($round < 1) {
                continue;
            }
            $out[$round] = ($userId === '' || $userId === null) ? null : (int) $userId;
        }

        return $out;
    }

    /**
     * Store a newly created stock audit.
     *
     * @param  Request  $request  The incoming HTTP request
     * @return RedirectResponse
     */
    public function store(StoreStockAuditRequest $request)
    {
        $validated = $request->validated();

        $organizationId = $request->user()->organization_id;

        // Verify location belongs to organization if provided
        if (! empty($validated['warehouse_location_id'])) {
            ProductLocation::where('id', $validated['warehouse_location_id'])
                ->forOrganization($organizationId)
                ->firstOrFail();
        }

        $audit = DB::transaction(function () use ($validated, $organizationId, $request) {
            $roundsTotal = (int) ($validated['rounds_total'] ?? 1);

            $audit = StockAudit::create([
                'organization_id' => $organizationId,
                'audit_number' => StockAudit::generateAuditNumber($organizationId),
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'status' => 'draft',
                'audit_type' => $validated['audit_type'],
                'rounds_total' => max(1, $roundsTotal),
                'blind_count' => (bool) ($validated['blind_count'] ?? ($roundsTotal > 1)),
                'warehouse_location_id' => $validated['warehouse_location_id'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            // Get products to include in the audit
            $productQuery = Product::forOrganization($organizationId)->active();

            if (! empty($validated['product_ids'])) {
                // Specific products selected
                $productQuery->whereIn('id', $validated['product_ids']);
            } elseif (! empty($validated['warehouse_location_id'])) {
                // Filter by location
                $productQuery->where('location_id', $validated['warehouse_location_id']);
            }

            $products = $productQuery->get();

            foreach ($products as $product) {
                StockAuditItem::create([
                    'stock_audit_id' => $audit->id,
                    'product_id' => $product->id,
                    'location_id' => $product->location_id,
                    'system_quantity' => $product->stock,
                    'status' => 'pending',
                ]);
            }

            // Multi-round: open the regular counting rounds now so counters can
            // be assigned while the audit is still a draft.
            if ($audit->isMultiRound()) {
                app(StockAuditRoundService::class)->openRounds($audit, $this->normalizeAssignments($validated['assignments'] ?? []));
            }

            return $audit;
        });

        return redirect()->route('stock-audits.show', $audit)
            ->with('success', 'Stock audit created successfully with '.$audit->items()->count().' items.');
    }

    /**
     * Display the specified stock audit.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  StockAudit  $stockAudit  The stock audit to display
     */
    public function show(Request $request, StockAudit $stockAudit): Response
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        $stockAudit->load([
            'warehouseLocation',
            'creator',
            'items.product',
            'items.variant',
            'items.location',
            'items.countedByUser',
            'rounds.assignee',
        ]);

        // Calculate summary stats
        $totalItems = $stockAudit->items->count();
        $countedItems = $stockAudit->items->where('status', '!=', 'pending')->count();
        $discrepancies = $stockAudit->items->where('counted_quantity', '!=', null)
            ->filter(fn ($item) => $item->counted_quantity !== $item->system_quantity)
            ->count();

        $roundService = app(StockAuditRoundService::class);

        // Variance view (product × round) — only meaningful for multi-round
        // audits; legacy audits return an empty array.
        $variance = $stockAudit->isMultiRound() ? $roundService->variance($stockAudit) : [];

        $divergentCount = $stockAudit->isMultiRound()
            ? $stockAudit->items->where('status', StockAuditRoundService::ITEM_DIVERGENT)->count()
            : 0;

        return Inertia::render('StockAudits/Show', [
            'audit' => $stockAudit,
            'summary' => [
                'total_items' => $totalItems,
                'counted_items' => $countedItems,
                'discrepancies' => $discrepancies,
                'progress' => $totalItems > 0 ? round(($countedItems / $totalItems) * 100) : 0,
                'divergent_items' => $divergentCount,
            ],
            'variance' => $variance,
            'rounds' => $stockAudit->rounds->map(fn ($r) => [
                'id' => $r->id,
                'round_number' => $r->round_number,
                'label' => $r->label,
                'is_tiebreak' => $r->is_tiebreak,
                'status' => $r->status,
                'assigned_to' => $r->assigned_to,
                'assignee_name' => $r->assignee?->name,
            ])->values(),
            'canManageAudits' => $request->user()->hasAnyPermission(['manage_stock_audits']),
        ]);
    }

    /**
     * Show the form for editing a stock audit.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  StockAudit  $stockAudit  The stock audit to edit
     * @return Response
     */
    public function edit(Request $request, StockAudit $stockAudit): Response|RedirectResponse
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if ($stockAudit->status !== 'draft') {
            return redirect()->route('stock-audits.show', $stockAudit)
                ->with('error', 'Only draft audits can be edited.');
        }

        $organizationId = $request->user()->organization_id;

        $locations = ProductLocation::forOrganization($organizationId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return Inertia::render('StockAudits/Edit', [
            'audit' => $stockAudit,
            'locations' => $locations,
            'users' => $this->selectableCounters($organizationId),
            'assignments' => $stockAudit->rounds
                ->mapWithKeys(fn ($r) => [$r->round_number => $r->assigned_to])
                ->all(),
            'auditTypes' => [
                'full' => 'Full Audit',
                'cycle' => 'Cycle Count',
                'spot' => 'Spot Check',
            ],
        ]);
    }

    /**
     * Update the specified stock audit.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  StockAudit  $stockAudit  The stock audit to update
     * @return RedirectResponse
     */
    public function update(UpdateStockAuditRequest $request, StockAudit $stockAudit)
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if ($stockAudit->status !== 'draft') {
            return redirect()->route('stock-audits.show', $stockAudit)
                ->with('error', 'Only draft audits can be edited.');
        }

        $validated = $request->validated();

        $organizationId = $request->user()->organization_id;

        // Verify location belongs to organization if provided
        if (! empty($validated['warehouse_location_id'])) {
            ProductLocation::where('id', $validated['warehouse_location_id'])
                ->forOrganization($organizationId)
                ->firstOrFail();
        }

        $stockAudit->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'audit_type' => $validated['audit_type'],
            'rounds_total' => max(1, (int) ($validated['rounds_total'] ?? 1)),
            'blind_count' => (bool) ($validated['blind_count'] ?? false),
            'warehouse_location_id' => $validated['warehouse_location_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        // Re-sync the round rows + assignments. Safe here: only drafts are
        // editable and drafts can have no counts yet.
        $service = app(StockAuditRoundService::class);
        if ($stockAudit->isMultiRound()) {
            $service->openRounds($stockAudit->fresh(), $this->normalizeAssignments($validated['assignments'] ?? []));
        } else {
            // Dropped back to a single round: remove any previously opened rounds.
            $stockAudit->rounds()->delete();
        }

        return redirect()->route('stock-audits.show', $stockAudit)
            ->with('success', 'Stock audit updated successfully.');
    }

    /**
     * Delete a stock audit (only if draft).
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  StockAudit  $stockAudit  The stock audit to delete
     * @return RedirectResponse
     */
    public function destroy(Request $request, StockAudit $stockAudit)
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if ($stockAudit->status !== 'draft') {
            return redirect()->route('stock-audits.show', $stockAudit)
                ->with('error', 'Only draft audits can be deleted.');
        }

        $stockAudit->delete();

        return redirect()->route('stock-audits.index')
            ->with('success', 'Stock audit deleted successfully.');
    }

    /**
     * Start a stock audit (transition from draft to in_progress).
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  StockAudit  $stockAudit  The stock audit to start
     * @return RedirectResponse
     */
    public function start(Request $request, StockAudit $stockAudit)
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if ($stockAudit->status !== 'draft') {
            return redirect()->route('stock-audits.show', $stockAudit)
                ->with('error', 'Only draft audits can be started.');
        }

        if ($stockAudit->items()->count() === 0) {
            return redirect()->route('stock-audits.show', $stockAudit)
                ->with('error', 'Cannot start an audit with no items.');
        }

        // Refresh system quantities from current stock levels
        DB::transaction(function () use ($stockAudit) {
            foreach ($stockAudit->items as $item) {
                $currentStock = $item->product->stock;
                $item->update(['system_quantity' => $currentStock]);
            }

            // Guarantee the round rows exist before counting begins (audits
            // created before a round was configured, or drafts edited down/up).
            if ($stockAudit->isMultiRound()) {
                app(StockAuditRoundService::class)->openRounds($stockAudit);
            }

            $stockAudit->update([
                'status' => 'in_progress',
                'started_at' => now(),
            ]);
        });

        return redirect()->route('stock-audits.show', $stockAudit)
            ->with('success', 'Stock audit started. System quantities have been recorded.');
    }

    /**
     * Complete a stock audit and create stock adjustments for discrepancies.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  StockAudit  $stockAudit  The stock audit to complete
     * @return RedirectResponse
     */
    public function complete(Request $request, StockAudit $stockAudit)
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if ($stockAudit->status !== 'in_progress') {
            return redirect()->route('stock-audits.show', $stockAudit)
                ->with('error', 'Only in-progress audits can be completed.');
        }

        $adjustmentsCreated = 0;

        try {
            DB::transaction(function () use ($stockAudit, &$adjustmentsCreated) {
                // Lock and re-read the audit so two concurrent completions
                // serialize on this row; the second waits, then sees the
                // 'completed' status and is rejected below — otherwise both
                // would re-apply every recount adjustment (double write).
                $stockAudit = StockAudit::whereKey($stockAudit->getKey())->lockForUpdate()->firstOrFail();

                if ($stockAudit->status !== 'in_progress') {
                    throw new \RuntimeException('Only in-progress audits can be completed.');
                }

                $stockAudit->load('items.product');

                // Multi-round audits: collapse the C1/C2/(C3) counts into a
                // single resolved_quantity per item before reconciling. Legacy
                // single-round audits skip this entirely (no round rows).
                $roundService = app(StockAuditRoundService::class);
                if ($stockAudit->isMultiRound()) {
                    $roundService->resolve($stockAudit);
                    // resolve() writes resolved_quantity on fresh rows; the
                    // in-memory collection loaded above is stale, so re-read it
                    // or every item would look uncounted and be skipped.
                    $stockAudit->load('items.product');

                    // Fail closed: an item whose rounds disagree and has no
                    // tiebreak agreement must be resolved before the audit can
                    // be completed. Completing silently would skip its stock
                    // adjustment — exactly the shrinkage the multi-count exists
                    // to catch.
                    $divergent = $stockAudit->items
                        ->where('status', StockAuditRoundService::ITEM_DIVERGENT)
                        ->count();

                    if ($divergent > 0) {
                        throw new \RuntimeException(
                            "Cannot complete: {$divergent} item(s) still have unresolved counts. ".
                            'Run the tiebreak round or resolve them manually first.'
                        );
                    }
                }

                foreach ($stockAudit->items as $item) {
                    // Skip items that haven't been counted (resolved wins when set)
                    $final = $roundService->finalQuantity($item);
                    if ($final === null) {
                        continue;
                    }

                    $discrepancy = $final - $item->system_quantity;

                    // Update the discrepancy field
                    $item->update([
                        'discrepancy' => $discrepancy,
                        'status' => 'adjusted',
                    ]);

                    // Create stock adjustment if there's a discrepancy
                    if ($discrepancy !== 0) {
                        StockAdjustment::adjust(
                            product: $item->product,
                            quantity: $discrepancy,
                            type: 'recount',
                            reason: "Stock audit: {$stockAudit->audit_number}",
                            notes: "Audit '{$stockAudit->name}' - System: {$item->system_quantity}, Counted: {$final}",
                            reference: $stockAudit,
                        );

                        $adjustmentsCreated++;
                    }
                }

                $stockAudit->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
            });
        } catch (\RuntimeException $e) {
            return redirect()->route('stock-audits.show', $stockAudit)
                ->with('error', $e->getMessage());
        }

        $message = 'Stock audit completed.';
        if ($adjustmentsCreated > 0) {
            $message .= " {$adjustmentsCreated} stock adjustment(s) created for discrepancies.";
        } else {
            $message .= ' No discrepancies found.';
        }

        return redirect()->route('stock-audits.show', $stockAudit)
            ->with('success', $message);
    }

    /**
     * Update the count for an individual audit item (AJAX endpoint).
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  StockAudit  $stockAudit  The stock audit
     * @param  StockAuditItem  $item  The audit item to update
     */
    public function updateCount(Request $request, StockAudit $stockAudit, StockAuditItem $item): JsonResponse
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($stockAudit->status !== 'in_progress') {
            return response()->json(['message' => 'Audit is not in progress'], 422);
        }

        if ($item->stock_audit_id !== $stockAudit->id) {
            return response()->json(['message' => 'Item does not belong to this audit'], 422);
        }

        $validated = $request->validate([
            'counted_quantity' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $discrepancy = $validated['counted_quantity'] - $item->system_quantity;

        $item->update([
            'counted_quantity' => $validated['counted_quantity'],
            'discrepancy' => $discrepancy,
            'status' => 'counted',
            'counted_by' => $request->user()->id,
            'counted_at' => now(),
            'notes' => $validated['notes'] ?? $item->notes,
        ]);

        return response()->json([
            'message' => 'Count updated successfully',
            'item' => $item->fresh(['product', 'countedByUser']),
        ]);
    }

    /**
     * Open the on-demand tiebreak round (C3) for a multi-round audit.
     *
     * Called by an admin from the variance view when regular rounds disagree.
     */
    public function openTiebreak(Request $request, StockAudit $stockAudit): JsonResponse
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! $stockAudit->isMultiRound()) {
            return response()->json(['message' => 'This audit does not use multiple rounds.'], 422);
        }

        if ($stockAudit->status !== 'in_progress') {
            return response()->json(['message' => 'The audit is not in progress.'], 422);
        }

        $round = app(StockAuditRoundService::class)->openTiebreakRound($stockAudit);

        return response()->json([
            'message' => "Tiebreak round {$round->label} is now open.",
            'round' => [
                'round_number' => $round->round_number,
                'label' => $round->label,
                'is_tiebreak' => $round->is_tiebreak,
            ],
        ]);
    }

    /**
     * Resolve a divergent item by hand (admin decision).
     *
     * The whole point of the multi-count is to surface shrinkage — so an item
     * that stays divergent can only be closed by an explicit human decision,
     * never silently.
     */
    public function resolveItem(Request $request, StockAudit $stockAudit, StockAuditItem $item): JsonResponse
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($item->stock_audit_id !== $stockAudit->id) {
            return response()->json(['message' => 'Item does not belong to this audit'], 422);
        }

        if ($stockAudit->status !== 'in_progress') {
            return response()->json(['message' => 'The audit is not in progress.'], 422);
        }

        $validated = $request->validate([
            'resolved_quantity' => 'required|integer|min:0',
        ]);

        app(StockAuditRoundService::class)->resolveManually(
            $item,
            (int) $validated['resolved_quantity'],
            $request->user(),
        );

        return response()->json([
            'message' => 'Item resolved.',
            'item' => $item->fresh(['product']),
        ]);
    }

    /**
     * Mobile capture screen for a counter (Phase 4).
     *
     * Shows ONLY the rounds this user may count and only THEIR OWN previous
     * counts for the active round — never another counter's numbers, so the
     * blind count actually stays blind.
     */
    public function capture(Request $request, StockAudit $stockAudit): Response|RedirectResponse
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            abort(403, 'Unauthorized action.');
        }

        if (! $stockAudit->isMultiRound()) {
            return redirect()->route('stock-audits.show', $stockAudit)
                ->with('error', 'This audit is a single count — use the main screen.');
        }

        if ($stockAudit->status !== 'in_progress') {
            return redirect()->route('stock-audits.show', $stockAudit)
                ->with('error', 'Only in-progress audits can be counted.');
        }

        $user = $request->user();
        $audit = $stockAudit->load(['rounds.assignee']);
        $rounds = $audit->rounds;

        if ($rounds->isEmpty()) {
            return redirect()->route('stock-audits.show', $audit)
                ->with('error', 'No counting rounds configured for this audit.');
        }

        // Pick the active round: explicit ?round=, else the user's own open
        // round, else the audit's current round, else the first open one.
        $requested = (int) $request->query('round', 0);
        $active = $requested ? $rounds->firstWhere('round_number', $requested) : null;
        $active ??= $rounds->first(fn ($r) => $r->status === 'open' && $r->assigned_to === $user->id);
        $active ??= $rounds->firstWhere('round_number', $audit->current_round);
        $active ??= $rounds->firstWhere('status', 'open');
        $active ??= $rounds->first();

        // The counter's own captures for the active round.
        $myCounts = StockAuditCount::where('stock_audit_id', $audit->id)
            ->where('round_number', $active->round_number)
            ->where('counted_by', $user->id)
            ->get()
            ->keyBy('stock_audit_item_id');

        $blind = (bool) $audit->blind_count;

        $items = $audit->items()->with(['product', 'location'])->get()->map(function ($item) use ($myCounts) {
            $count = $myCounts->get($item->id);

            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->product?->name,
                'sku' => $item->product?->sku,
                'barcode' => $item->product?->barcode,
                'location' => $item->location?->name,
                'my_count' => $count ? (int) $count->counted_quantity : null,
                'counted_at' => optional($count)->counted_at,
            ];
        })->values();

        $isAdmin = $user->hasAnyPermission([\App\Enums\Permission::MANAGE_STOCK_AUDITS->value]);

        return Inertia::render('StockAudits/Capture', [
            'audit' => [
                'id' => $audit->id,
                'audit_number' => $audit->audit_number,
                'name' => $audit->name,
                'blind_count' => $audit->blind_count,
                'rounds_total' => $audit->rounds_total,
                'status' => $audit->status,
            ],
            'rounds' => $rounds->map(fn ($r) => [
                'round_number' => $r->round_number,
                'label' => $r->label,
                'is_tiebreak' => $r->is_tiebreak,
                'status' => $r->status,
                'assigned_to' => $r->assigned_to,
                'assignee_name' => $r->assignee?->name,
                'is_mine' => (int) $r->assigned_to === (int) $user->id,
            ])->values(),
            'activeRound' => [
                'round_number' => $active->round_number,
                'label' => $active->label,
                'is_tiebreak' => $active->is_tiebreak,
                'status' => $active->status,
            ],
            'items' => $items,
            'blind' => $blind,
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * Record a count for one item in one round (mobile capture, JSON).
     */
    public function recordRoundCount(Request $request, StockAudit $stockAudit, int $round): JsonResponse
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($stockAudit->status !== 'in_progress') {
            return response()->json(['message' => 'The audit is not in progress.'], 422);
        }

        $validated = $request->validate([
            'item_id' => 'required|integer',
            'counted_quantity' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $item = StockAuditItem::where('stock_audit_id', $stockAudit->id)
            ->whereKey($validated['item_id'])
            ->first();

        if (! $item) {
            return response()->json(['message' => 'Item does not belong to this audit'], 422);
        }

        $isAdmin = $request->user()->hasAnyPermission([\App\Enums\Permission::MANAGE_STOCK_AUDITS->value]);

        try {
            $count = app(StockAuditRoundService::class)->recordCount(
                $stockAudit,
                $item,
                $round,
                (int) $validated['counted_quantity'],
                $request->user(),
                $validated['notes'] ?? null,
                $isAdmin,
            );
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Count saved.',
            'count' => [
                'round_number' => $count->round_number,
                'counted_quantity' => (int) $count->counted_quantity,
            ],
        ]);
    }

    /**
     * Close a round. The assigned counter may close their own round; an admin
     * may close any (JSON).
     */
    public function closeRound(Request $request, StockAudit $stockAudit, int $round): JsonResponse
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $isAdmin = $request->user()->hasAnyPermission([\App\Enums\Permission::MANAGE_STOCK_AUDITS->value]);

        try {
            $roundRow = app(StockAuditRoundService::class)->closeRound($stockAudit, $round, $request->user(), $isAdmin);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "Round {$roundRow->label} closed."]);
    }

    /**
     * Reopen a closed round (admin only; JSON).
     */
    public function reopenRound(Request $request, StockAudit $stockAudit, int $round): JsonResponse
    {
        if ($stockAudit->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $isAdmin = $request->user()->hasAnyPermission([\App\Enums\Permission::MANAGE_STOCK_AUDITS->value]);

        try {
            $roundRow = app(StockAuditRoundService::class)->reopenRound($stockAudit, $round, $request->user(), $isAdmin);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "Round {$roundRow->label} reopened."]);
    }
}
