<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('label_printers', function (Blueprint $table) {
            $table->string('connection_mode', 16)->default('smb')->after('enabled');
            $table->unsignedSmallInteger('lan_port')->default(9100)->after('share_name');
        });

        Schema::table('label_printers', function (Blueprint $table) {
            $table->string('username')->nullable()->change();
            $table->text('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('label_printers', function (Blueprint $table) {
            $table->dropColumn(['connection_mode', 'lan_port']);
            $table->string('username')->nullable(false)->change();
            $table->text('password')->nullable(false)->change();
        });
    }
};
