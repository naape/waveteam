<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_vacancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_category_id')->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->string('slug', 220)->unique();
            $table->longText('description');
            $table->text('requirements')->nullable();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_text', 200)->nullable();
            $table->enum('employment_type', ['full_time', 'contract', 'project', 'daily'])->default('contract');
            $table->unsignedSmallInteger('positions')->default(1);
            $table->decimal('min_experience_years', 4, 1)->default(0);
            $table->boolean('requires_police_clearance')->default(true);
            $table->date('closing_date')->nullable();
            $table->enum('status', ['draft', 'published', 'closed', 'archived'])->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('job_vacancy_districts', function (Blueprint $table) {
            $table->foreignId('job_vacancy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->primary(['job_vacancy_id', 'district_id']);
        });

        Schema::create('job_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_vacancy_id')->constrained()->restrictOnDelete();
            $table->foreignId('applicant_profile_id')->constrained()->cascadeOnDelete();
            $table->text('cover_note')->nullable();
            $table->foreignId('cv_document_id')->nullable()->constrained('applicant_documents')->nullOnDelete();
            $table->enum('status', ['submitted', 'under_review', 'shortlisted', 'interview_scheduled', 'selected', 'rejected', 'on_hold'])->default('submitted');
            $table->timestamp('submitted_at');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['job_vacancy_id', 'applicant_profile_id']);
            $table->index(['status', 'job_vacancy_id']);
        });

        Schema::create('job_application_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();
            $table->dateTime('scheduled_at');
            $table->enum('mode', ['in_person', 'phone', 'video'])->default('in_person');
            $table->string('location_or_link')->nullable();
            $table->json('panel')->nullable();
            $table->enum('status', ['scheduled', 'completed', 'no_show', 'cancelled', 'rescheduled'])->default('scheduled');
            $table->text('outcome')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interviews');
        Schema::dropIfExists('job_application_status_history');
        Schema::dropIfExists('job_applications');
        Schema::dropIfExists('job_vacancy_districts');
        Schema::dropIfExists('job_vacancies');
    }
};
