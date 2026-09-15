<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw per-round captures: one row per (audit item, round).
     *
     * This is the append-only history of who counted what, in which round.
     * The audit item keeps the *resolved* number (resolved_quantity); this
     * table keeps every individual count so discrepancies stay auditable.
     */
    public function up(): void
    {
        Schema::create('stock_audit_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_audit_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('round_number');
            $table->integer('counted_quantity');
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('counted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // One capture per item per round; re-counting overwrites in place.
            $table->unique(['stock_audit_item_id', 'round_number']);
            $table->index(['stock_audit_id', 'round_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_audit_counts');
    }
};