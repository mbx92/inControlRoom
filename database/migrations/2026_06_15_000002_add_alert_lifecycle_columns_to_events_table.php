<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('rule_key')->nullable()->after('site_id');
            $table->string('fingerprint')->nullable()->after('rule_key');
            $table->string('active_fingerprint')->nullable()->unique()->after('fingerprint');
            $table->jsonb('context')->nullable()->after('message');
            $table->timestamp('first_seen_at')->nullable()->after('status');
            $table->timestamp('last_seen_at')->nullable()->after('first_seen_at');
            $table->timestamp('resolved_at')->nullable()->after('acknowledge_comment');

            $table->index(['integration_id', 'rule_key']);
            $table->index(['status', 'severity']);
            $table->index('fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['integration_id', 'rule_key']);
            $table->dropIndex(['status', 'severity']);
            $table->dropIndex(['fingerprint']);
            $table->dropUnique(['active_fingerprint']);
            $table->dropColumn([
                'rule_key',
                'fingerprint',
                'active_fingerprint',
                'context',
                'first_seen_at',
                'last_seen_at',
                'resolved_at',
            ]);
        });
    }
};
