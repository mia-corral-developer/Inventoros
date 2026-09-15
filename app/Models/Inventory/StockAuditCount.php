<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single raw capture: one item, one round, one number.
 *
 * The append-only history that lets us show the variance table (product ×
 * round) and prove who counted what before the audit is resolved.
 *
 * @property int $id
 * @property int $stock_audit_id
 * @property int $stock_audit_item_id
 * @property int $round_number
 * @property int $counted_quantity
 * @property int|null $counted_by
 * @property \Illuminate\Support\Carbon|null $counted_at
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Inventory\StockAudit $stockAudit
 * @property-read \App\Models\Inventory\StockAuditItem $item
 * @property-read \App\Models\User|null $counter
 */
class StockAuditCount extends Model
{
    protected $fillable = [
        'stock_audit_id',
        'stock_audit_item_id',
        'round_number',
        'counted_quantity',
        'counted_by',
        'counted_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'round_number' => 'integer',
            'counted_quantity' => 'integer',
            'counted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StockAudit, $this>
     */
    public function stockAudit(): BelongsTo
    {
        return $this->belongsTo(StockAudit::class);
    }

    /**
     * @return BelongsTo<StockAuditItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockAuditItem::class, 'stock_audit_item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}