<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_printers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('display_name');
            $table->boolean('enabled')->default(false);
            $table->string('smb_host');
            $table->string('share_name');
            $table->string('username');
            $table->text('password');
            $table->string('domain')->nullable();
            $table->string('driver_language', 16)->default('zpl');
            $table->boolean('is_default')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_printers');
    }
};
