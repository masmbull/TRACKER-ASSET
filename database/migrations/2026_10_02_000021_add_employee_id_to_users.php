<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give users an employee number.
 *
 * The FAT and BAST forms have asked for "NIK / ID Karyawan" on every document
 * for as long as they have existed, and the field has never been able to fill
 * itself in: it defaulted from `Auth::user()?->employee_id`, and there has
 * never been an `employee_id` column. So the expression was decoration, the
 * box opened empty, and whoever was preparing a document typed the same
 * number off a payroll sheet every time.
 *
 * The register already knows who somebody is - the users table just had no
 * column to keep the number in. Nullable, because an employee number is
 * something the company issues and some accounts (service accounts, the
 * importer, agents that only exist to hold an audit history) will never have
 * one. Adding it is additive: no existing row changes, and every document
 * keeps working whether or not anybody fills it in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_id', 100)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('employee_id');
        });
    }
};
