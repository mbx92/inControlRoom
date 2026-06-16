<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->timestamp('last_tested_at')->nullable()->after('last_synced_at');
            $table->string('last_test_status')->nullable()->after('last_tested_at');
            $table->text('last_test_message')->nullable()->after('last_test_status');
            $table->jsonb('last_test_meta')->nullable()->after('last_test_message');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn([
                'last_tested_at',
                'last_test_status',
                'last_test_message',
                'last_test_meta',
            ]);
        });
    }
};
