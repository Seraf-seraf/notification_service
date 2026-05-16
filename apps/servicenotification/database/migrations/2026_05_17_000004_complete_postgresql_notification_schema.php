<?php

declare(strict_types=1);

use App\Domain\Outbox\OutboxMessageStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('caller_id', 255);
            $table->string('endpoint', 255);
            $table->string('idempotency_key', 255);
            $table->char('payload_hash', 64);
            $table->uuid('batch_id')->nullable();
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->foreign('batch_id')->references('id')->on('notification_batches')->nullOnDelete();
            $table->unique(['caller_id', 'endpoint', 'idempotency_key']);
            $table->index('batch_id');
            $table->index('expires_at');
            $table->index('created_at');
        });

        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('outbox_message_id');
            $table->uuid('batch_id');
            $table->uuid('notification_id');
            $table->string('message_type', 128);
            $table->string('exchange', 255);
            $table->string('routing_key', 255);
            $table->string('channel', 16);
            $table->unsignedTinyInteger('priority');
            $table->json('payload');
            $table->json('headers')->nullable();
            $table->enum('status', OutboxMessageStatus::values());
            $table->unsignedInteger('publish_attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('available_at');
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->foreign('batch_id')->references('id')->on('notification_batches')->cascadeOnDelete();
            $table->foreign('notification_id')->references('id')->on('notifications')->cascadeOnDelete();
            $table->unique('outbox_message_id');
            $table->index('batch_id');
            $table->index('notification_id');
            $table->index('status');
            $table->index('channel');
            $table->index('priority');
            $table->index(['status', 'available_at']);
            $table->index('created_at');
        });

        $this->createProviderMessageIdUniqueIndex();
    }

    public function down(): void
    {
        $this->dropProviderMessageIdUniqueIndex();

        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('idempotency_keys');
    }

    private function createProviderMessageIdUniqueIndex(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX notifications_provider_message_id_unique_not_null ON notifications (provider_message_id) WHERE provider_message_id IS NOT NULL');
    }

    private function dropProviderMessageIdUniqueIndex(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS notifications_provider_message_id_unique_not_null');
    }
};
