<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel in-app (database channel) notifications
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80);
            $table->enum('channel', ['mail', 'sms', 'whatsapp'])->default('mail');
            $table->char('locale', 2)->default('en');
            $table->string('name', 150);
            $table->string('subject')->nullable();
            $table->text('body');
            $table->json('placeholders')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_mandatory')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['key', 'channel', 'locale']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('template_key', 80);
            $table->enum('channel', ['mail', 'sms', 'whatsapp']);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'template_key', 'channel']);
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template_key', 80)->nullable();
            $table->enum('channel', ['mail', 'sms', 'whatsapp', 'database']);
            $table->string('recipient', 190);
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued')->index();
            $table->text('error')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        // Append-only audit trail (no updated_at by design).
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 20)->nullable(); // applicant / org_contact / staff / system
            $table->string('action', 80)->index();
            $table->nullableMorphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('notifications');
    }
};
