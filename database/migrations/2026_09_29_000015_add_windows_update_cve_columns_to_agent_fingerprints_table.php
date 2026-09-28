<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Windows Update state, including the pending count the agent previously could
 * not afford to collect.
 *
 * The pending count is here because it was asked for and is worth having, but
 * the honest caveat belongs with the column rather than in a comment nobody
 * reads. Collecting it means asking Windows Update to search, which took 30.9
 * seconds on the development laptop on a healthy connection. The agent gives that
 * call a hard 25-second budget in a separate process and reports nothing when it
 * expires, so this column will be NULL on any machine that is behind a slow or
 * captive network.
 *
 * NULL means "we did not get an answer". It must never be rendered as zero, and
 * that distinction is the entire reason the other four columns exist: they come
 * from the local update cache, which answers in a fraction of a second, so a
 * laptop that could not be searched still reports what it already has.
 *
 *  - windows_updates_pending / security_updates_pending: the search result.
 *    The security subset is the one that answers a CVE question - "3 updates
 *    waiting" means nothing on its own, "1 of them is a security rollup" does.
 *  - updates_succeeded / updates_failed: history outcome counts. A failed update
 *    is not protection, and it is invisible to the hotfix inventory, which lists
 *    it as though it applied. Ten failures exist on the development laptop.
 *  - last_security_update_at / security_updates_installed: when this machine last
 *    took a CVE-bearing patch. Defender definition updates are excluded, because
 *    they arrive several times a day and would make every machine look perfectly
 *    patched at all times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_fingerprints', function (Blueprint $table) {
            $table->unsignedSmallInteger('security_updates_pending')->nullable();
            $table->unsignedSmallInteger('updates_succeeded')->nullable();
            $table->unsignedSmallInteger('updates_failed')->nullable();
            $table->unsignedSmallInteger('security_updates_installed')->nullable();
            $table->date('last_security_update_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_fingerprints', function (Blueprint $table) {
            $table->dropColumn([
                'security_updates_pending',
                'updates_succeeded', 'updates_failed',
                'security_updates_installed', 'last_security_update_at',
            ]);
        });
    }
};
