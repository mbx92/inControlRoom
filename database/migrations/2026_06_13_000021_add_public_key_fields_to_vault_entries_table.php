<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vault_entries', function (Blueprint $table) {
            $table->text('public_key')->nullable()->after('ciphertext');
            $table->string('fingerprint')->nullable()->after('public_key');
        });
    }

    public function down(): void
    {
        Schema::table('vault_entries', function (Blueprint $table) {
            $table->dropColumn(['public_key', 'fingerprint']);
        });
    }
};
