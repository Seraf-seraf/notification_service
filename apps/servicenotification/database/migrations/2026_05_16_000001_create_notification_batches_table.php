<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('channel', 16);
            $table->text('message');
            $table->unsignedTinyInteger('priority');
            $table->unsignedInteger('recipients_count');
            $table->string('idempotency_key', 255)->nullable();
            $table->string('request_id', 255)->nullable();
            $table->timestampsTz();

            $table->index(['channel', 'priority']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_batches');
    }
};
