<?php

declare(strict_types=1);

use App\Domain\Notification\NotificationProvider;
use App\Domain\Notification\NotificationStatus;
use App\Domain\Notification\ProviderDeliveryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('batch_id');
            $table->string('subscriber_id', 255);
            $table->string('channel', 16);
            $table->text('message');
            $table->unsignedTinyInteger('priority');
            $table->enum('status', NotificationStatus::values());
            $table->enum('provider', NotificationProvider::values())->nullable();
            $table->enum('provider_status', ProviderDeliveryStatus::values())->nullable();
            $table->string('provider_message_id', 255)->nullable();
            $table->timestampsTz();

            $table->foreign('batch_id')->references('id')->on('notification_batches')->cascadeOnDelete();
            $table->unique(['batch_id', 'subscriber_id']);
            $table->index('subscriber_id');
            $table->index('batch_id');
            $table->index('status');
            $table->index('provider');
            $table->index('provider_status');
            $table->index('channel');
            $table->index('priority');
            $table->index(['channel', 'priority']);
            $table->index(['status', 'created_at']);
            $table->index('provider_message_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
