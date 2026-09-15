<?php

declare(strict_types=1);

namespace App\Http\Requests\StockAudit;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a new stock audit (web surface). Rules unchanged from the previous
 * inline validation in Inventory\StockAuditController::store, extended with the
 * multi-round counting configuration.
 */
final class StoreStockAuditRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'audit_type' => 'required|in:full,cycle,spot',
            'warehouse_location_id' => 'nullable|exists:product_locations,id',
            'notes' => 'nullable|string|max:2000',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',

            // Multi-round (blind count) configuration.
            'rounds_total' => 'nullable|integer|min:1|max:10',
            'blind_count' => 'nullable|boolean',
            'assignments' => 'nullable|array',
            'assignments.*' => 'nullable|integer|exists:users,id',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rounds_total.min' => 'An audit needs at least one counting round.',
            'rounds_total.max' => 'At most 10 counting rounds are supported.',
            'assignments.*.exists' => 'Every assigned counter must be a user in your organization.',
        ];
    }
}