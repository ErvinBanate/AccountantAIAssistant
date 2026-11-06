<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional. Guard additions so interrupted upgrades can resume.
        Schema::table('mailbox_connections', function (Blueprint $table) {
            if (! Schema::hasColumn('mailbox_connections', 'generation')) {
                $table->uuid('generation')->nullable();
            }
            if (! Schema::hasColumn('mailbox_connections', 'sync_state')) {
                $table->json('sync_state')->nullable();
            }
            if (! Schema::hasColumn('mailbox_connections', 'sync_status')) {
                $table->string('sync_status')->default('idle');
            }
            if (! Schema::hasColumn('mailbox_connections', 'sync_error')) {
                $table->text('sync_error')->nullable();
            }
            if (! Schema::hasColumn('mailbox_connections', 'sync_requested_at')) {
                $table->timestamp('sync_requested_at')->nullable();
            }
        });
        DB::table('mailbox_connections')->whereNull('generation')->eachById(function ($connection) {
            DB::table('mailbox_connections')->where('id', $connection->id)->update(['generation' => (string) Str::uuid()]);
        });
        Schema::table('client_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('client_requests', 'gmail_mailbox_email')) {
                $table->string('gmail_mailbox_email')->nullable();
            }
            if (! Schema::hasColumn('client_requests', 'gmail_received_at')) {
                $table->timestamp('gmail_received_at')->nullable();
            }
        });
        DB::table('client_requests')->whereNotNull('gmail_message_id')->whereNull('gmail_mailbox_email')
            ->eachById(function ($row) {
                $details = json_decode($row->details, true, flags: JSON_THROW_ON_ERROR);
                DB::table('client_requests')->where('id', $row->id)->update([
                    'gmail_mailbox_email' => $details['mailbox_email'] ?? null,
                ]);
            });
        // Create the replacement first because MySQL also uses its user_id prefix for the foreign key.
        if (! Schema::hasIndex('client_requests', 'requests_mailbox_message_unique')) {
            Schema::table('client_requests', function (Blueprint $table) {
                $table->unique(['user_id', 'gmail_mailbox_email', 'gmail_message_id'], 'requests_mailbox_message_unique');
            });
        }
        if (Schema::hasIndex('client_requests', 'client_requests_user_id_gmail_message_id_unique')) {
            Schema::table('client_requests', function (Blueprint $table) {
                $table->dropUnique(['user_id', 'gmail_message_id']);
            });
        }
    }

    public function down(): void
    {
        if (DB::table('client_requests')->select('user_id', 'gmail_message_id')->whereNotNull('gmail_message_id')
            ->groupBy('user_id', 'gmail_message_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Rollback needs mailbox message IDs to be unique per workspace. Imported messages have been preserved.');
        }
        Schema::table('client_requests', function (Blueprint $table) {
            $table->unique(['user_id', 'gmail_message_id']);
        });
        Schema::table('client_requests', function (Blueprint $table) {
            $table->dropUnique('requests_mailbox_message_unique');
            $table->dropColumn(['gmail_mailbox_email', 'gmail_received_at']);
        });
        Schema::table('mailbox_connections', function (Blueprint $table) {
            $table->dropColumn(['generation', 'sync_state', 'sync_status', 'sync_error', 'sync_requested_at']);
        });
    }
};
