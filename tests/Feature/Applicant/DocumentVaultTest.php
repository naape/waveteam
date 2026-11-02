<?php

namespace Tests\Feature\Applicant;

use App\Support\Documents\DocumentVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\CreatesApplicants;
use Tests\TestCase;

class DocumentVaultTest extends TestCase
{
    use CreatesApplicants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    public function test_files_are_encrypted_at_rest_and_decrypt_exactly(): void
    {
        $vault = app(DocumentVault::class);
        $upload = $this->fakePdf('My CV.pdf', 'SECRET-NIC-901234567V');
        $original = file_get_contents($upload->getRealPath());

        $file = $vault->store($upload, null);

        $raw = Storage::disk('documents')->get($file->path);
        $this->assertStringNotContainsString('SECRET-NIC', $raw);
        $this->assertStringNotContainsString('%PDF', $raw);
        $this->assertSame($original, $vault->contents($file));
        $this->assertSame(hash('sha256', $original), $file->sha256);
        $this->assertSame('application/pdf', $file->mime_type);
        $this->assertSame('My CV.pdf', $file->original_name);
        $this->assertStringEndsWith('.bin', $file->path);
        $this->assertStringNotContainsString('CV', $file->path);
    }

    public function test_large_multi_chunk_files_round_trip(): void
    {
        $vault = app(DocumentVault::class);
        $body = "%PDF-1.4\n".random_bytes(200 * 1024)."\n%%EOF";
        $file = $vault->store(UploadedFile::fake()->createWithContent('big.pdf', $body), null);

        $this->assertSame($body, $vault->contents($file));
    }

    public function test_disguised_script_is_rejected_by_content_not_extension(): void
    {
        $this->expectException(ValidationException::class);

        app(DocumentVault::class)->store(UploadedFile::fake()->createWithContent('cv.pdf', '<?php system($_GET["c"]); ?>'), null);
    }

    public function test_html_disguised_as_image_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(DocumentVault::class)->store(UploadedFile::fake()->createWithContent('photo.png', '<html><script>alert(1)</script></html>'), null);
    }

    public function test_oversized_file_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(DocumentVault::class)->store(UploadedFile::fake()->createWithContent('big.pdf', '%PDF-1.4'.str_repeat('a', 6 * 1024 * 1024)), null);
    }

    public function test_tampered_ciphertext_is_detected(): void
    {
        $vault = app(DocumentVault::class);
        $file = $vault->store($this->fakePdf(), null);

        $raw = Storage::disk('documents')->get($file->path);
        $raw[40] = chr(ord($raw[40]) ^ 0xFF);
        Storage::disk('documents')->put($file->path, $raw);

        $this->expectException(RuntimeException::class);
        $vault->contents($file);
    }

    public function test_photo_uploads_accept_only_images(): void
    {
        $this->expectException(ValidationException::class);

        app(DocumentVault::class)->store($this->fakePdf(), null, ['image/jpeg', 'image/png']);
    }
}
