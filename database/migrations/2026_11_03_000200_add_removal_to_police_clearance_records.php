<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff can take a certificate out of use (e.g. uploaded to the wrong person).
 * The record and its file are kept for history — never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('police_clearance_records', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable()->after('superseded_at');
            $table->foreignId('removed_by')->nullable()->after('removed_at')->constrained('users')->nullOnDelete();
            $table->string('removal_reason', 1000)->nullable()->after('removed_by');
        });
    }

    public function down(): void
    {
        Schema::table('police_clearance_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('removed_by');
            $table->dropColumn(['removed_at', 'removal_reason']);
        });
    }
};
