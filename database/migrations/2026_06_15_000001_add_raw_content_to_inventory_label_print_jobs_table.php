<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_label_print_jobs', function (Blueprint $table) {
            $table->longText('raw_content')->nullable()->after('meta');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_label_print_jobs', function (Blueprint $table) {
            $table->dropColumn('raw_content');
        });
    }
};
