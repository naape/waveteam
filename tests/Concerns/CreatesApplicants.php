<?php

namespace Tests\Concerns;

use App\Models\ApplicantProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;

trait CreatesApplicants
{
    protected function makeApplicant(array $profile = [], array $user = []): User
    {
        $u = User::factory()->applicant()->create($user);
        ApplicantProfile::forceCreate(array_merge(['user_id' => $u->id, 'full_name' => $u->name, 'nic_number' => null], $profile));

        return $u->fresh();
    }

    protected function makeStaff(string $role, bool $mfa = true): User
    {
        $u = User::factory()->staff()->create();
        $u->assignRole($role);
        if ($mfa) {
            $u->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        }

        return $u->fresh();
    }

    protected function fakePdf(string $name = 'cv.pdf', string $body = 'Curriculum vitae of Nimal'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%âãÏÓ\n1 0 obj<<>>endobj\n% {$body}\ntrailer<<>>\n%%EOF\n");
    }
}
