<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\PccVerificationStatus;
use App\Enums\PoliceClearanceStatus;
use App\Models\ApplicantDocument;
use App\Models\ApplicantProfile;
use App\Models\PoliceClearanceRecord;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class PoliceClearanceStatusTest extends TestCase
{
    use RefreshDatabase;

    private ApplicantProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01');

        $user = User::factory()->applicant()->create();
        $this->profile = ApplicantProfile::forceCreate(['user_id' => $user->id, 'full_name' => 'Test Applicant']);
    }

    private function record(PccVerificationStatus $status, ?string $validUntil = null, string $issued = '2026-01-15'): PoliceClearanceRecord
    {
        $file = StoredFile::create([
            'uuid' => (string) Str::uuid(), 'disk' => 'documents', 'path' => 'x', 'original_name' => 'pcc.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('0', 64),
        ]);
        $doc = ApplicantDocument::create([
            'applicant_profile_id' => $this->profile->id, 'document_type' => DocumentType::PoliceClearance, 'stored_file_id' => $file->id,
        ]);

        return PoliceClearanceRecord::forceCreate([
            'applicant_profile_id' => $this->profile->id,
            'applicant_document_id' => $doc->id,
            'issue_date' => $issued,
            'uploaded_at' => now(),
            'verification_status' => $status,
            'valid_until' => $validUntil,
        ]);
    }

    public function test_uploaded_certificate_is_never_valid_until_approved(): void
    {
        $record = $this->record(PccVerificationStatus::PendingVerification, '2027-06-01');

        $this->assertSame(PoliceClearanceStatus::PendingVerification, $record->displayStatus());
        $this->assertFalse($record->isValidOn(today()));
    }

    public function test_approved_certificate_states(): void
    {
        $this->assertSame(PoliceClearanceStatus::Valid, $this->record(PccVerificationStatus::Approved, '2027-06-01')->displayStatus());
        $this->assertSame(PoliceClearanceStatus::ExpiringSoon, $this->record(PccVerificationStatus::Approved, '2026-11-20')->displayStatus());
        $this->assertSame(PoliceClearanceStatus::Expired, $this->record(PccVerificationStatus::Approved, '2026-10-31')->displayStatus());
        $this->assertSame(PoliceClearanceStatus::Rejected, $this->record(PccVerificationStatus::Rejected)->displayStatus());
    }

    public function test_admin_set_renewal_date_takes_precedence_over_expiry(): void
    {
        $record = $this->record(PccVerificationStatus::Approved, '2027-06-01');
        $record->next_renewal_due = Carbon::parse('2026-11-10');
        $record->save();

        $this->assertSame(PoliceClearanceStatus::ExpiringSoon, $record->displayStatus());
    }

    public function test_validity_for_an_assignment_end_date(): void
    {
        $record = $this->record(PccVerificationStatus::Approved, '2027-01-31');

        $this->assertTrue($record->isValidOn(Carbon::parse('2027-01-31')));
        $this->assertFalse($record->isValidOn(Carbon::parse('2027-02-01')));
    }

    public function test_suggested_expiry_uses_configurable_validity(): void
    {
        $record = $this->record(PccVerificationStatus::PendingVerification, null, '2026-03-10');

        $this->assertSame('2027-03-10', $record->suggestedValidUntil()->toDateString());
    }

    public function test_profile_status_is_missing_without_certificate(): void
    {
        $this->assertSame(PoliceClearanceStatus::Missing, $this->profile->policeClearanceStatus());
    }

    public function test_status_cannot_be_mass_assigned(): void
    {
        // Verification fields are guarded; in strict mode an attempt is a hard error.
        $this->expectException(\Illuminate\Database\Eloquent\MassAssignmentException::class);

        new PoliceClearanceRecord(['verification_status' => 'approved', 'valid_until' => '2030-01-01']);
    }
}
