<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('agent_fingerprints')
            ->select('agent_token_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('agent_token_id')
            ->groupBy('agent_token_id')
            // COUNT(*) rather than the "total" alias: PostgreSQL resolves HAVING
            // against the table, so an aggregate alias is simply not a column it
            // can see there. SQLite and MySQL both tolerate it, which is exactly
            // why this only surfaced on the second engine.
            ->havingRaw('COUNT(*) > 1')
            ->pluck('total', 'agent_token_id');

        foreach ($duplicates as $tokenId => $total) {
            // Keep the newest, drop the rest.
            //
            // The code has always assumed one fingerprint per token -
            // updateOrCreate() keys on agent_token_id and AgentApiToken reads it
            // back through hasOne()->latestOfMany('collected_at'). The newest is
            // the one that matches, because a fingerprint POST replaces the
            // software list wholesale and collected_at moves with it. Anything
            // older is a snapshot the fleet page would never have shown anyway.
            $survivor = DB::table('agent_fingerprints')
                ->where('agent_token_id', $tokenId)
                ->orderByDesc('collected_at')
                ->orderByDesc('id')
                ->value('id');

            DB::table('agent_fingerprints')
                ->where('agent_token_id', $tokenId)
                ->where('id', '!=', $survivor)
                ->delete();
        }

        // With the duplicates gone, the assumption the code already makes can be
        // enforced instead of merely believed. Without the index a retried or
        // concurrent POST produces a second row, and latestOfMany() then hides
        // the split by always answering with the newest one.
        if (! Schema::hasIndex('agent_fingerprints', ['agent_token_id'])) {
            Schema::table('agent_fingerprints', function (Blueprint $table): void {
                $table->unique('agent_token_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('agent_fingerprints', ['agent_token_id'])) {
            Schema::table('agent_fingerprints', function (Blueprint $table): void {
                $table->dropUnique(['agent_token_id']);
            });
        }
    }
};
