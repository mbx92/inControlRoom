<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->foreignUuid('vault_entry_id')->nullable()->after('base_url')->constrained('vault_entries')->nullOnDelete();
            $table->index(['type', 'vault_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vault_entry_id');
        });
    }
};
