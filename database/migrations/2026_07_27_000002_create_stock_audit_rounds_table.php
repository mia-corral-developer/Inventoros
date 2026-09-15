<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per counting round of a stock audit.
     *
     * Rounds are opened and closed atomically (see StockAuditRoundService),
     * mirroring the TOMFIC close_count_round / reopen_count_round RPCs.
     */
    public function up(): void
    {
        Schema::create('stock_audit_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_audit_id')->constrained()->cascadeOnDelete();
            // 1 = C1, 2 = C2, 3 = C3 (tiebreak), ...
            $table->unsignedSmallInteger('round_number');
            // Short label for UI / export: C1, C2, C3.
            $table->string('label', 8);
            // True only for the on-demand tiebreak round.
            $table->boolean('is_tiebreak')->default(false);
            // Assigned counter (null = anyone with permission may count).
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('open'); // open, closed
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['stock_audit_id', 'round_number']);
            $table->index(['stock_audit_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_audit_rounds');
    }
};