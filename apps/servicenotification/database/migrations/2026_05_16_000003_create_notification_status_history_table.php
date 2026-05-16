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
        Schema::create('notification_status_history', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id');
            $table->enum('status', NotificationStatus::values());
            $table->enum('provider', NotificationProvider::values())->nullable();
            $table->enum('provider_status', ProviderDeliveryStatus::values())->nullable();
            $table->string('reason', 512)->nullable();
            $table->timestampTz('changed_at');
            $table->timestampsTz();

            $table->foreign('notification_id')->references('id')->on('notifications')->cascadeOnDelete();
            $table->index(['notification_id', 'changed_at']);
            $table->index('status');
            $table->index('provider');
            $table->index('provider_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_status_history');
    }
};
