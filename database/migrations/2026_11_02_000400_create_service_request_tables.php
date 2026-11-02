<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('registration_number', 60)->nullable();
            $table->enum('organization_type', ['government', 'semi_government', 'private', 'ngo', 'other']);
            $table->text('address');
            $table->text('billing_address')->nullable();
            $table->string('email', 190);
            $table->string('phone', 20);
            $table->enum('status', ['pending', 'active', 'suspended'])->default('pending')->index();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('organization_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('designation', 120)->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('organization_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->foreignId('stored_file_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('service_category_id')->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->longText('description');
            $table->unsignedSmallInteger('required_headcount')->default(1);
            $table->text('required_qualifications')->nullable();
            $table->decimal('required_experience_years', 4, 1)->default(0);
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_text', 255);
            $table->date('start_date')->nullable();
            $table->unsignedSmallInteger('expected_duration_value')->nullable();
            $table->enum('expected_duration_unit', ['days', 'weeks', 'months'])->nullable();
            $table->string('working_hours', 150)->nullable();
            $table->text('special_requirements')->nullable();
            $table->boolean('requires_police_clearance')->default(true);
            $table->decimal('budget_amount', 14, 2)->nullable();
            $table->char('budget_currency', 3)->default('LKR');
            $table->enum('status', ['submitted', 'under_review', 'quotation_preparation', 'quotation_sent', 'approved', 'team_assigned', 'in_progress', 'completed', 'cancelled'])->default('submitted');
            $table->foreignId('assigned_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('show_team_to_client')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'service_category_id']);
        });

        Schema::create('service_request_skills', function (Blueprint $table) {
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_years', 4, 1)->default(0);
            $table->primary(['service_request_id', 'skill_id']);
        });

        Schema::create('service_request_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('service_request_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->foreignId('stored_file_id')->constrained()->restrictOnDelete();
            $table->boolean('visible_to_client')->default(true);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('service_request_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('service_request_message_files', function (Blueprint $table) {
            $table->foreignId('service_request_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stored_file_id')->constrained()->restrictOnDelete();
            $table->primary(['service_request_message_id', 'stored_file_id'], 'sr_msg_file_pk');
        });

        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $table->string('reference', 30)->unique();
            $table->unsignedSmallInteger('version')->default(1);
            $table->enum('status', ['draft', 'sent', 'accepted', 'rejected', 'expired', 'superseded'])->default('draft')->index();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->char('currency', 3)->default('LKR');
            $table->date('valid_until')->nullable();
            $table->text('terms')->nullable();
            $table->foreignId('pdf_file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('client_comment')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit', 30)->nullable();
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('workforce_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('applicant_profile_id')->constrained()->restrictOnDelete();
            $table->string('role_title', 150);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->enum('status', ['proposed', 'confirmed', 'active', 'completed', 'released', 'cancelled'])->default('proposed');
            $table->foreignId('police_clearance_record_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('pcc_override')->default(false);
            $table->text('pcc_override_reason')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['applicant_profile_id', 'status', 'start_date', 'end_date'], 'assignment_clash_idx');
            $table->index(['service_request_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_assignments');
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('service_request_message_files');
        Schema::dropIfExists('service_request_messages');
        Schema::dropIfExists('service_request_attachments');
        Schema::dropIfExists('service_request_status_history');
        Schema::dropIfExists('service_request_skills');
        Schema::dropIfExists('service_requests');
        Schema::dropIfExists('organization_documents');
        Schema::dropIfExists('organization_users');
        Schema::dropIfExists('organizations');
    }
};
