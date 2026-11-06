<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('records');
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });
        Schema::create('mailbox_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->text('tokens');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
        Schema::table('client_requests', function (Blueprint $table) {
            $table->string('gmail_message_id')->nullable();
            $table->unique(['user_id', 'gmail_message_id']);
        });
    }

    public function down(): void
    {
        Schema::table('client_requests', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'gmail_message_id']);
            $table->dropColumn('gmail_message_id');
        });
        Schema::dropIfExists('mailbox_connections');
        Schema::dropIfExists('workspace_sources');
    }
};
