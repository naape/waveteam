<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files, applicant profile, versioned documents, police clearance and the
 * repeatable applicant child tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('disk', 40);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->index();
            $table->boolean('is_encrypted')->default(true);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('applicant_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('full_name', 150);
            $table->text('nic_number')->nullable();          // encrypted
            $table->char('nic_hash', 64)->nullable()->unique(); // blind index
            $table->text('passport_number')->nullable();     // encrypted
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other', 'prefer_not_to_say'])->nullable();
            $table->text('permanent_address')->nullable();
            $table->text('current_address')->nullable();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contact_number', 20)->nullable();
            $table->string('whatsapp_number', 20)->nullable();
            $table->string('emergency_contact_name', 150)->nullable();
            $table->string('emergency_contact_number', 20)->nullable();
            $table->foreignId('photo_file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $table->decimal('total_experience_years', 4, 1)->default(0);
            $table->enum('availability_status', ['available', 'available_from', 'engaged', 'unavailable'])->default('available');
            $table->date('available_from')->nullable();
            $table->unsignedTinyInteger('profile_completion')->default(0);
            $table->enum('verification_status', ['unverified', 'pending', 'verified', 'rejected'])->default('unverified');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->enum('applicant_status', ['active', 'on_hold', 'blacklisted', 'inactive'])->default('active');
            $table->unsignedBigInteger('current_police_clearance_id')->nullable(); // FK added below
            $table->timestamps();
            $table->softDeletes();

            $table->index(['verification_status', 'applicant_status', 'availability_status', 'district_id'], 'applicant_match_idx');
        });

        Schema::create('applicant_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_profile_id')->constrained()->cascadeOnDelete();
            $table->enum('document_type', ['cv', 'nic', 'education_cert', 'professional_cert', 'driving_licence', 'police_clearance', 'photo', 'other']);
            $table->string('title')->nullable();
            $table->foreignId('stored_file_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_current')->default(true);
            $table->enum('status', ['pending_verification', 'verified', 'rejected', 'superseded'])->default('pending_verification');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('applicant_documents')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['applicant_profile_id', 'document_type', 'is_current'], 'applicant_doc_current_idx');
        });

        Schema::create('police_clearance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicant_document_id')->constrained()->restrictOnDelete();
            $table->text('certificate_number')->nullable(); // encrypted
            $table->date('issue_date');
            $table->string('issuing_authority', 150)->nullable();
            $table->timestamp('uploaded_at');
            $table->enum('verification_status', ['pending_verification', 'approved', 'rejected'])->default('pending_verification');
            $table->date('valid_until')->nullable();
            $table->date('next_renewal_due')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['applicant_profile_id', 'verification_status', 'valid_until'], 'pcc_applicant_idx');
            $table->index(['verification_status', 'next_renewal_due'], 'pcc_renewal_idx');
        });

        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->foreign('current_police_clearance_id')->references('id')->on('police_clearance_records')->nullOnDelete();
        });

        Schema::create('police_clearance_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('police_clearance_record_id')->constrained()->cascadeOnDelete();
            $table->string('reminder_type', 20); // d30, d14, d7, expired (configurable)
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->unique(['police_clearance_record_id', 'reminder_type'], 'pcc_reminder_once');
        });

        Schema::create('applicant_educations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_profile_id')->constrained()->cascadeOnDelete();
            $table->string('qualification', 200);
            $table->string('institution', 200);
            $table->unsignedSmallInteger('year_completed')->nullable();
            $table->string('result_grade', 100)->nullable();
            $table->foreignId('document_id')->nullable()->constrained('applicant_documents')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('applicant_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_profile_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['professional', 'technical', 'training']);
            $table->string('title', 200);
            $table->string('issuing_body', 200)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('applicant_documents')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('applicant_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->decimal('years', 4, 1)->default(0);
            $table->enum('level', ['basic', 'intermediate', 'expert'])->default('intermediate');
            $table->timestamps();
            $table->unique(['applicant_profile_id', 'skill_id']);
            $table->index(['skill_id', 'years']);
        });

        Schema::create('applicant_job_categories', function (Blueprint $table) {
            $table->foreignId('applicant_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_category_id')->constrained()->cascadeOnDelete();
            $table->primary(['applicant_profile_id', 'job_category_id']);
            $table->index('job_category_id');
        });

        Schema::create('applicant_employments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_profile_id')->constrained()->cascadeOnDelete();
            $table->string('employer', 200);
            $table->string('designation', 150);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->text('responsibilities')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('applicant_languages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_profile_id')->constrained()->cascadeOnDelete();
            $table->string('language', 60);
            $table->enum('speak', ['none', 'basic', 'good', 'fluent'])->default('basic');
            $table->enum('read', ['none', 'basic', 'good', 'fluent'])->default('basic');
            $table->enum('write', ['none', 'basic', 'good', 'fluent'])->default('basic');
            $table->timestamps();
            $table->unique(['applicant_profile_id', 'language']);
        });

        Schema::create('applicant_driving_licences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_profile_id')->constrained()->cascadeOnDelete();
            $table->text('licence_number'); // encrypted
            $table->json('vehicle_classes')->nullable();
            $table->date('expires_on')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('applicant_documents')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('applicant_military_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_profile_id')->constrained()->cascadeOnDelete();
            $table->string('branch', 100);
            $table->string('rank', 100)->nullable();
            $table->date('service_from')->nullable();
            $table->date('service_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('applicant_preferred_districts', function (Blueprint $table) {
            $table->foreignId('applicant_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->primary(['applicant_profile_id', 'district_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_preferred_districts');
        Schema::dropIfExists('applicant_military_services');
        Schema::dropIfExists('applicant_driving_licences');
        Schema::dropIfExists('applicant_languages');
        Schema::dropIfExists('applicant_employments');
        Schema::dropIfExists('applicant_job_categories');
        Schema::dropIfExists('applicant_skills');
        Schema::dropIfExists('applicant_certifications');
        Schema::dropIfExists('applicant_educations');
        Schema::dropIfExists('police_clearance_reminders');
        Schema::table('applicant_profiles', fn (Blueprint $t) => $t->dropForeign(['current_police_clearance_id']));
        Schema::dropIfExists('police_clearance_records');
        Schema::dropIfExists('applicant_documents');
        Schema::dropIfExists('applicant_profiles');
        Schema::dropIfExists('stored_files');
    }
};
