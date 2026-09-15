<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add multi-round counting configuration to stock audits.
     *
     * All defaults keep legacy audits working unchanged: rounds_total = 1
     * means "one count, same as before", blind_count = false.
     */
    public function up(): void
    {
        Schema::table('stock_audits', function (Blueprint $table) {
            // Number of REGULAR counting rounds (>= 1). Legacy audits = 1.
            // A tiebreak round, when needed, is created on demand with
            // round_number = rounds_total + 1 and is_tiebreak = true.
            $table->unsignedSmallInteger('rounds_total')->default(1)->after('audit_type');
            // Blind count: counters cannot see prior counts for the same item.
            $table->boolean('blind_count')->default(false)->after('rounds_total');
            // The round currently open for capture.
            $table->unsignedSmallInteger('current_round')->default(1)->after('blind_count');
        });
    }

    public function down(): void
    {
        Schema::table('stock_audits', function (Blueprint $table) {
            $table->dropColumn(['rounds_total', 'blind_count', 'current_round']);
        });
    }
};