<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $validAssetIds = DB::table('inventory_assets')->pluck('id');

        if ($validAssetIds->isNotEmpty()) {
            DB::table('agents')
                ->whereNotNull('inventory_asset_id')
                ->whereNotIn('inventory_asset_id', $validAssetIds)
                ->update(['inventory_asset_id' => null]);
        } else {
            DB::table('agents')
                ->whereNotNull('inventory_asset_id')
                ->update(['inventory_asset_id' => null]);
        }

        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('inventory_asset_id');
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->foreignUuid('inventory_asset_id')
                ->nullable()
                ->after('agent_token_hash')
                ->constrained('inventory_assets')
                ->nullOnDelete();

            $table->unique('inventory_asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropForeign(['inventory_asset_id']);
            $table->dropUnique(['inventory_asset_id']);
            $table->dropColumn('inventory_asset_id');
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->string('inventory_asset_id')->nullable()->after('agent_token_hash');
        });
    }
};
