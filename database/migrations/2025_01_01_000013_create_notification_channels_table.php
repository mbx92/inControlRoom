<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notification channels — stores config for alert delivery
     * (Telegram bot, SMTP email, etc.)
     */
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // telegram | email | webhook
            $table->string('name'); // user-defined label
            $table->text('config'); // JSON encrypted (bot token, chat_id, SMTP creds, etc.)
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('type');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
