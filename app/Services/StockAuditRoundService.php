<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditCount;
use App\Models\Inventory\StockAuditItem;
use App\Models\Inventory\StockAuditRound;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Multi-round (blind count) auditing for stock audits.
 *
 * Ports the TOMFIC C1/C2/C3 pattern onto Inventoros' schema:
 *  - each round captures counts WITHOUT overwriting the others (raw history
 *    lives in stock_audit_counts, one row per item+round);
 *  - rounds open/close atomically (row lock), so two counters cannot
 *    overwrite each other's round;
 *  - resolve() collapses the rounds into a single resolved_quantity per item,
 *    flagging items that need a tiebreak round or manual admin resolution.
 *
 * Legacy compatibility: an audit with rounds_total <= 1 never touches this
 * path — complete() keeps using counted_quantity exactly as before.
 */
class StockAuditRoundService
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    /** Final value agreed by every regular round. */
    public const METHOD_AGREEMENT = 'agreement';

    /** Final value settled by the tiebreak round. */
    public const METHOD_TIEBREAK = 'tiebreak';

    /** Final value set by an admin by hand. */
    public const METHOD_MANUAL = 'manual';

    /** Item status when rounds disagree and no resolution exists yet. */
    public const ITEM_DIVERGENT = 'divergent';

    /**
     * Create the regular counting rounds for an audit (idempotent).
     *
     * @param  array<int,int|null>  $assignments  round_number => user id|null
     * @return \Illuminate\Database\Eloquent\Collection<int, StockAuditRound>
     */
    public function openRounds(StockAudit $audit, array $assignments = [])
    {
        return DB::transaction(function () use ($audit, $assignments) {
            $audit = StockAudit::whereKey($audit->getKey())->lockForUpdate()->firstOrFail();

            $total = max(1, (int) $audit->rounds_total);

            for ($n = 1; $n <= $total; $n++) {
                StockAuditRound::updateOrCreate(
                    ['stock_audit_id' => $audit->id, 'round_number' => $n],
                    [
                        'label' => 'C'.$n,
                        'is_tiebreak' => false,
                        'assigned_to' => $assignments[$n] ?? null,
                        'status' => self::STATUS_OPEN,
                    ]
                );
            }

            $audit->update(['current_round' => 1]);

            return $audit->rounds()->get();
        });
    }

    /**
     * Open (or return) the on-demand tiebreak round.
     *
     * The tiebreak round is the last round (round_number = highest + 1) and
     * is the only one flagged is_tiebreak = true.
     */
    public function openTiebreakRound(StockAudit $audit, ?int $assignedTo = null): StockAuditRound
    {
        return DB::transaction(function () use ($audit, $assignedTo) {
            $audit = StockAudit::whereKey($audit->getKey())->lockForUpdate()->firstOrFail();

            $existing = $audit->rounds()->where('is_tiebreak', true)->first();
            if ($existing) {
                return $existing;
            }

            $next = (int) $audit->rounds()->max('round_number') + 1;

            $round = StockAuditRound::create([
                'stock_audit_id' => $audit->id,
                'round_number' => $next,
                'label' => 'C'.$next,
                'is_tiebreak' => true,
                'assigned_to' => $assignedTo,
                'status' => self::STATUS_OPEN,
            ]);

            $audit->update(['current_round' => $next]);

            return $round;
        });
    }

    /**
     * Record one count for an item in a given round.
     *
     * Re-counting the same item in the same round overwrites in place
     * (unique item+round). The round must belong to an open round of the audit.
     *
     * @throws \RuntimeException when the round is closed or belongs elsewhere
     * @throws \InvalidArgumentException when the item does not belong to the audit
     */
    public function recordCount(
        StockAudit $audit,
        StockAuditItem $item,
        int $round,
        int $quantity,
        ?User $by = null,
        ?string $notes = null,
        bool $isAdmin = false,
    ): StockAuditCount {
        if ((int) $item->stock_audit_id !== (int) $audit->id) {
            throw new \InvalidArgumentException('Item does not belong to this audit.');
        }

        if ($quantity < 0) {
            throw new \InvalidArgumentException('Counted quantity cannot be negative.');
        }

        return DB::transaction(function () use ($audit, $item, $round, $quantity, $by, $notes, $isAdmin) {
            $roundRow = StockAuditRound::where('stock_audit_id', $audit->id)
                ->where('round_number', $round)
                ->lockForUpdate()
                ->first();

            if (! $roundRow) {
                throw new \RuntimeException("Round {$round} does not exist for this audit.");
            }

            if (! $roundRow->isOpen()) {
                throw new \RuntimeException("Round {$round} is already closed.");
            }

            // A round assigned to a specific counter may only be written by
            // that counter — unless the actor is an admin.
            if ($roundRow->assigned_to !== null
                && $by !== null
                && (int) $roundRow->assigned_to !== (int) $by->id
                && ! $isAdmin) {
                throw new \RuntimeException("Round {$round} is assigned to another counter.");
            }

            return StockAuditCount::updateOrCreate(
                ['stock_audit_item_id' => $item->id, 'round_number' => $round],
                [
                    'stock_audit_id' => $audit->id,
                    'counted_quantity' => $quantity,
                    'counted_by' => $by?->id,
                    'counted_at' => now(),
                    'notes' => $notes,
                ]
            );
        });
    }

    /**
     * Close a round atomically. Two concurrent closes serialize on the row.
     *
     * Per decision: the assigned counter may close their own round; an admin
     * may close any round.
     *
     * @throws \RuntimeException when already closed or not permitted
     */
    public function closeRound(StockAudit $audit, int $round, ?User $actor = null, bool $isAdmin = false): StockAuditRound
    {
        return DB::transaction(function () use ($audit, $round, $actor, $isAdmin) {
            $roundRow = StockAuditRound::where('stock_audit_id', $audit->id)
                ->where('round_number', $round)
                ->lockForUpdate()
                ->first();

            if (! $roundRow) {
                throw new \RuntimeException("Round {$round} does not exist for this audit.");
            }

            if (! $roundRow->isOpen()) {
                throw new \RuntimeException("Round {$round} is already closed.");
            }

            if ($roundRow->assigned_to !== null
                && $actor !== null
                && (int) $roundRow->assigned_to !== (int) $actor->id
                && ! $isAdmin) {
                throw new \RuntimeException("Round {$round} is assigned to another counter.");
            }

            $roundRow->update([
                'status' => self::STATUS_CLOSED,
                'closed_at' => now(),
            ]);

            $this->advanceCurrentRound($audit, $roundRow);

            return $roundRow;
        });
    }

    /**
     * Reopen a closed round so a counter can correct a mistake.
     *
     * Deliberately admin-only: blind-count integrity depends on counters not
     * silently reopening their own round after seeing the others.
     *
     * @throws \RuntimeException when the round is already open or not permitted
     */
    public function reopenRound(StockAudit $audit, int $round, ?User $actor = null, bool $isAdmin = false): StockAuditRound
    {
        if (! $isAdmin) {
            throw new \RuntimeException('Only an admin can reopen a closed round.');
        }

        return DB::transaction(function () use ($audit, $round) {
            $roundRow = StockAuditRound::where('stock_audit_id', $audit->id)
                ->where('round_number', $round)
                ->lockForUpdate()
                ->first();

            if (! $roundRow) {
                throw new \RuntimeException("Round {$round} does not exist for this audit.");
            }

            if ($roundRow->isOpen()) {
                throw new \RuntimeException("Round {$round} is already open.");
            }

            $roundRow->update(['status' => self::STATUS_OPEN, 'closed_at' => null]);

            return $roundRow;
        });
    }

    /**
     * Resolve every counted item into a single final quantity.
     *
     * Rules:
     *  - all regular rounds agree            → resolved, method = agreement
     *  - regular rounds disagree, tiebreak
     *    matches one of them                  → resolved, method = tiebreak
     *  - tiebreak matches none, or no
     *    tiebreak yet                        → item flagged divergent (manual)
     *
     * @return array{resolved:int,agreement:int,tiebreak:int,divergent:int,uncounted:int}
     */
    public function resolve(StockAudit $audit): array
    {
        return DB::transaction(function () use ($audit) {
            $audit = StockAudit::whereKey($audit->getKey())->lockForUpdate()->firstOrFail();
            $audit->load(['items', 'rounds']);

            $stats = ['resolved' => 0, 'agreement' => 0, 'tiebreak' => 0, 'divergent' => 0, 'uncounted' => 0];

            // Legacy / single-round audits have no round rows — nothing to do.
            if ($audit->rounds_total <= 1 || $audit->rounds->isEmpty()) {
                return $stats;
            }

            $regularRounds = $audit->rounds
                ->where('is_tiebreak', false)
                ->pluck('round_number')
                ->sort()
                ->values();

            if ($regularRounds->isEmpty()) {
                return $stats;
            }

            $tiebreakRound = $audit->rounds->firstWhere('is_tiebreak', true);

            $countsByItem = StockAuditCount::where('stock_audit_id', $audit->id)
                ->get()
                ->groupBy('stock_audit_item_id');

            foreach ($audit->items as $item) {
                $byRound = $countsByItem
                    ->get($item->id, collect())
                    ->keyBy('round_number');

                $regularValues = [];
                foreach ($regularRounds as $r) {
                    if ($byRound->has($r)) {
                        $regularValues[(int) $r] = (int) $byRound[$r]->counted_quantity;
                    }
                }

                // Not every regular round has captured this item yet.
                if (count($regularValues) < $regularRounds->count()) {
                    $stats['uncounted']++;

                    continue;
                }

                $unique = array_values(array_unique($regularValues));

                if (count($unique) === 1) {
                    $this->applyResolution($item, $unique[0], self::METHOD_AGREEMENT);
                    $stats['agreement']++;
                    $stats['resolved']++;

                    continue;
                }

                // Regular rounds disagree → needs the tiebreak value.
                if ($item->resolution_method === self::METHOD_MANUAL) {
                    // An admin already decided by hand; do not clobber it.
                    $stats['resolved']++;

                    continue;
                }

                if ($tiebreakRound && $byRound->has($tiebreakRound->round_number)) {
                    $tb = (int) $byRound[$tiebreakRound->round_number]->counted_quantity;

                    if (in_array($tb, $unique, true)) {
                        $this->applyResolution($item, $tb, self::METHOD_TIEBREAK);
                        $stats['tiebreak']++;
                        $stats['resolved']++;

                        continue;
                    }
                }

                // Unresolved: no tiebreak, or the tiebreak matched neither count.
                $item->update([
                    'resolved_quantity' => null,
                    'resolution_method' => null,
                    'status' => self::ITEM_DIVERGENT,
                ]);
                $stats['divergent']++;
            }

            return $stats;
        });
    }

    /**
     * Manually fix the resolved value of a divergent item (admin action).
     */
    public function resolveManually(StockAuditItem $item, int $quantity, ?User $by = null): StockAuditItem
    {
        if ($quantity < 0) {
            throw new \InvalidArgumentException('Resolved quantity cannot be negative.');
        }

        $item->update([
            'resolved_quantity' => $quantity,
            'resolution_method' => self::METHOD_MANUAL,
            'status' => 'verified',
        ]);

        return $item;
    }

    /**
     * The quantity complete() should reconcile against.
     *
     * resolved_quantity wins when set; otherwise the legacy counted_quantity.
     */
    public function finalQuantity(StockAuditItem $item): ?int
    {
        return $item->resolved_quantity ?? $item->counted_quantity;
    }

    /**
     * Variance view: every item with its value per round.
     *
     * @return array<int, array{
     *     item_id:int, product_id:int, label:string|null, system_quantity:int,
     *     rounds:array<int,int|null>, resolved_quantity:int|null,
     *     resolution_method:string|null, divergent:bool
     * }>
     */
    public function variance(StockAudit $audit): array
    {
        $audit->load(['items.product', 'rounds']);

        $countsByItem = StockAuditCount::where('stock_audit_id', $audit->id)
            ->get()
            ->groupBy('stock_audit_item_id');

        $roundNumbers = $audit->rounds->pluck('round_number')->sort()->values();

        return $audit->items->map(function (StockAuditItem $item) use ($countsByItem, $roundNumbers) {
            $byRound = $countsByItem->get($item->id, collect())->keyBy('round_number');

            $rounds = [];
            foreach ($roundNumbers as $r) {
                $rounds[(int) $r] = $byRound->has($r)
                    ? (int) $byRound[$r]->counted_quantity
                    : null;
            }

            return [
                'item_id' => $item->id,
                'product_id' => $item->product_id,
                'label' => $item->product?->name,
                'system_quantity' => (int) $item->system_quantity,
                'rounds' => $rounds,
                'resolved_quantity' => $item->resolved_quantity,
                'resolution_method' => $item->resolution_method,
                'divergent' => $item->status === self::ITEM_DIVERGENT,
            ];
        })->all();
    }

    /**
     * Point current_round at the next still-open round (if any).
     */
    private function advanceCurrentRound(StockAudit $audit, StockAuditRound $justClosed): void
    {
        $nextOpen = StockAuditRound::where('stock_audit_id', $audit->id)
            ->where('status', self::STATUS_OPEN)
            ->where('round_number', '>', $justClosed->round_number)
            ->orderBy('round_number')
            ->first();

        if ($nextOpen) {
            StockAudit::whereKey($audit->id)->update(['current_round' => $nextOpen->round_number]);
        }
    }

    /**
     * Write the resolved value + method on an item and mark it verified.
     */
    private function applyResolution(StockAuditItem $item, int $quantity, string $method): void
    {
        $item->update([
            'resolved_quantity' => $quantity,
            'resolution_method' => $method,
            'status' => 'verified',
        ]);
    }
}