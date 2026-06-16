<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_label_print_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('asset_id')->nullable();
            $table->uuid('printer_id')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('queued');
            $table->boolean('is_test')->default(false);
            $table->string('printer_name')->nullable();
            $table->string('share_path')->nullable();
            $table->string('driver_language', 16)->nullable();
            $table->string('label_identifier')->nullable();
            $table->text('qr_url')->nullable();
            $table->json('meta')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('inventory_assets')->nullOnDelete();
            $table->foreign('printer_id')->references('id')->on('label_printers')->nullOnDelete();

            $table->index(['asset_id', 'is_test', 'created_at']);
            $table->index(['status', 'is_test']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_label_print_jobs');
    }
};
