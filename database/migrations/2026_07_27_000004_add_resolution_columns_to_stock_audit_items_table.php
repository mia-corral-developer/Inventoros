<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resolution columns on audit items.
     *
     * counted_quantity is kept as-is (single-round / legacy), resolved_quantity
     * becomes the source of truth when an audit has > 1 round. resolution_method
     * records HOW the final number was decided.
     */
    public function up(): void
    {
        Schema::table('stock_audit_items', function (Blueprint $table) {
            // Final quantity that complete() will reconcile against.
            $table->integer('resolved_quantity')->nullable()->after('counted_quantity');
            // agreement | tiebreak | manual (null = not resolved yet).
            $table->string('resolution_method')->nullable()->after('resolved_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('stock_audit_items', function (Blueprint $table) {
            $table->dropColumn(['resolved_quantity', 'resolution_method']);
        });
    }
};