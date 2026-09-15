<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A counting round of a stock audit (C1, C2, tiebreak …).
 *
 * @property int $id
 * @property int $stock_audit_id
 * @property int $round_number
 * @property string $label
 * @property bool $is_tiebreak
 * @property int|null $assigned_to
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Inventory\StockAudit $stockAudit
 * @property-read \App\Models\User|null $assignee
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Inventory\StockAuditCount[] $counts
 */
class StockAuditRound extends Model
{
    protected $fillable = [
        'stock_audit_id',
        'round_number',
        'label',
        'is_tiebreak',
        'assigned_to',
        'status',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'round_number' => 'integer',
            'is_tiebreak' => 'boolean',
            'closed_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return HasMany<StockAuditCount, $this>
     */
    public function counts(): HasMany
    {
        return $this->hasMany(StockAuditCount::class, 'round_number', 'round_number')
            ->where('stock_audit_id', $this->stock_audit_id);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}