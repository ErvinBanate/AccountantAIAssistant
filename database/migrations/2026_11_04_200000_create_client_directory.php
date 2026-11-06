<?php

use App\Services\ClientDirectory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clients')) {
            Schema::create('clients', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('name', 500);
                $table->string('name_key', 64);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['user_id', 'name_key']);
            });
        }
        if (! Schema::hasTable('client_contacts')) {
            Schema::create('client_contacts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->string('email', 254);
                $table->timestamps();
                $table->unique(['client_id', 'email']);
            });
        }
        DB::table('workspace_sources')->eachById(function ($source) {
            app(ClientDirectory::class)->approveRecords(
                $source->user_id,
                json_decode($source->records, true, flags: JSON_THROW_ON_ERROR)
            );
        });
        // Invalidate full-mailbox jobs created before client filtering was introduced.
        DB::table('mailbox_connections')->eachById(function ($connection) {
            DB::table('mailbox_connections')->where('id', $connection->id)->update([
                'generation' => (string) Str::uuid(), 'sync_state' => null,
                'sync_status' => 'idle', 'sync_error' => null, 'sync_requested_at' => null,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_contacts');
        Schema::dropIfExists('clients');
    }
};
