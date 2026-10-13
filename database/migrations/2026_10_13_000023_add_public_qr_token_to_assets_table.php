<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A scan target that does not require a login.
 *
 * The QR on a laptop used to encode /assets/{id}, which is behind the auth
 * middleware: the person standing at the machine with a phone - an auditor, a
 * new hire, a courier - hit a login wall and nothing else. A label whose whole
 * purpose is "scan this and see what this machine is" only worked for people
 * who already had an account.
 *
 * qr_token is an unguessable per-asset secret that opens a read-only document
 * for that one asset. It is random and independent of the asset id on purpose:
 * sequential ids would let anyone enumerate the whole register by editing the
 * URL, and a token can be rotated (the "re-create" action) if a label leaks.
 *
 * Nullable, and null means "no QR yet": an asset only gets a token when someone
 * actually asks for a label, so adding this column does not silently publish
 * 261 asset pages the moment it runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('qr_token', 64)->nullable()->unique()->after('qr_code');
            $table->timestamp('qr_generated_at')->nullable()->after('qr_token');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['qr_token', 'qr_generated_at']);
        });
    }
};
