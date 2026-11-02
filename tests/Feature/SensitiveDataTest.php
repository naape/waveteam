<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit;
use App\Support\BlindIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SensitiveDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_nic_is_encrypted_at_rest_and_searchable_by_blind_index(): void
    {
        $user = User::factory()->applicant()->create();
        $profile = ApplicantProfile::forceCreate(['user_id' => $user->id, 'full_name' => 'Nimal Perera', 'nic_number' => ' 199012345678 ']);

        $raw = DB::table('applicant_profiles')->where('id', $profile->id)->first();

        $this->assertStringNotContainsString('199012345678', $raw->nic_number);
        $this->assertSame('199012345678', $profile->fresh()->nic_number);
        $this->assertTrue(ApplicantProfile::findByNic('199012345678')->is($profile));
    }

    public function test_nic_normalisation_and_validation(): void
    {
        $this->assertSame('901234567V', BlindIndex::normalizeNic('901234567v'));
        $this->assertTrue(BlindIndex::isValidNic('901234567v'));
        $this->assertTrue(BlindIndex::isValidNic('199012345678'));
        $this->assertFalse(BlindIndex::isValidNic('12345'));
        $this->assertSame(BlindIndex::nic('901234567v'), BlindIndex::nic('901234567V'));
    }

    public function test_duplicate_nic_is_rejected(): void
    {
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        foreach ([1, 2] as $i) {
            ApplicantProfile::forceCreate([
                'user_id' => User::factory()->applicant()->create()->id,
                'full_name' => "Applicant {$i}",
                'nic_number' => '901234567V',
            ]);
        }
    }

    public function test_masked_nic(): void
    {
        $profile = new ApplicantProfile(['nic_number' => '199012345678']);

        $this->assertSame('••••••••5678', $profile->maskedNic());
    }

    public function test_audit_log_redacts_sensitive_values_and_is_immutable(): void
    {
        $log = Audit::log('applicant.updated', null, ['nic_number' => '901234567V'], ['nic_number' => '991234567V', 'full_name' => 'X']);

        $this->assertSame('[redacted]', $log->old_values['nic_number']);
        $this->assertSame('[redacted]', $log->new_values['nic_number']);
        $this->assertSame('X', $log->new_values['full_name']);

        $log->action = 'tampered';
        $this->assertFalse($log->save());
        $this->assertSame('applicant.updated', AuditLog::find($log->id)->action);
    }

    public function test_sensitive_attributes_are_hidden_from_serialisation(): void
    {
        $profile = new ApplicantProfile(['full_name' => 'A', 'nic_number' => '901234567V']);

        $this->assertArrayNotHasKey('nic_number', $profile->toArray());
    }
}
