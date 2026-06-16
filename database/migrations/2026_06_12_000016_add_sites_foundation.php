<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('business_type');
            $table->string('address')->nullable();
            $table->string('timezone')->default(config('app.timezone', 'UTC'));
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::table('integrations', function (Blueprint $table) {
            $table->uuid('site_id')->nullable()->after('id');
            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
            $table->index('site_id');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->uuid('site_id')->nullable()->after('user_id');
            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
            $table->index('site_id');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->uuid('site_id')->nullable()->after('integration_id');
            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
            $table->index('site_id');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['site_id']);
            $table->dropIndex(['site_id']);
            $table->dropColumn('site_id');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['site_id']);
            $table->dropIndex(['site_id']);
            $table->dropColumn('site_id');
        });

        Schema::table('integrations', function (Blueprint $table) {
            $table->dropForeign(['site_id']);
            $table->dropIndex(['site_id']);
            $table->dropColumn('site_id');
        });

        Schema::dropIfExists('sites');
    }
};
