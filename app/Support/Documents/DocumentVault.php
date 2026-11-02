<?php

namespace App\Support\Documents;

use App\Models\StoredFile;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private, encrypted document storage.
 *
 * - File type is decided from the file's actual bytes (magic numbers), never
 *   from the client-supplied extension or MIME type.
 * - Content is encrypted with libsodium secretstream (XChaCha20-Poly1305,
 *   authenticated, chunked so large files don't need to fit in memory).
 * - Files are stored under random UUID names on a private disk outside the
 *   web root, and are only ever streamed back through authorised controllers.
 */
class DocumentVault
{
    private const CHUNK = 64 * 1024;

    /** Detected MIME => canonical extension. */
    public const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        // Office Open XML (macro-free only: .docm/.xlsm have different MIME types).
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    /** Default for personal documents. */
    public const PERSONAL_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    /** Client project attachments may also include Word / Excel files. */
    public const PROJECT_TYPES = [
        'application/pdf', 'image/jpeg', 'image/png',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** Only these are ever displayed inline; everything else is a download. */
    private const INLINE_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    public function store(UploadedFile $file, ?User $uploader, ?array $allowedMimes = null, string $field = 'file'): StoredFile
    {
        $mime = $this->validate($file, $allowedMimes, $field);
        $uuid = (string) Str::uuid();
        $path = 'documents/'.now()->format('Y/m').'/'.$uuid.'.bin';

        $plain = fopen($file->getRealPath(), 'rb');
        $sha256 = hash_file('sha256', $file->getRealPath());
        $encrypted = $this->encryptStream($plain);
        fclose($plain);

        Storage::disk($this->disk())->writeStream($path, $encrypted);
        fclose($encrypted);

        return StoredFile::create([
            'uuid' => $uuid,
            'disk' => $this->disk(),
            'path' => $path,
            'original_name' => $this->safeName($file->getClientOriginalName(), self::MIME_EXTENSIONS[$mime]),
            'mime_type' => $mime,
            'size_bytes' => $file->getSize(),
            'sha256' => $sha256,
            'is_encrypted' => true,
            'uploaded_by' => $uploader?->id,
        ]);
    }

    /**
     * Stores system-generated content (e.g. a quotation PDF) — still
     * signature-checked and encrypted like any upload.
     */
    public function storeContents(string $contents, string $filename, ?User $uploader, ?array $allowedMimes = null): StoredFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wtv');
        file_put_contents($tmp, $contents);

        try {
            return $this->store(new UploadedFile($tmp, $filename, null, null, true), $uploader, $allowedMimes);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Streams the decrypted file. Responses are never cached and, for inline
     * viewing, sandboxed so a malicious PDF cannot run script in our origin.
     */
    public function response(StoredFile $file, bool $download = false): StreamedResponse
    {
        $download = $download || ! in_array($file->mime_type, self::INLINE_TYPES, true);
        $disposition = ($download ? 'attachment' : 'inline').'; filename="'.addslashes($file->original_name).'"';

        return new StreamedResponse(function () use ($file) {
            $this->decryptTo($file, fopen('php://output', 'wb'));
        }, 200, [
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; object-src 'self'",
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }

    /** Returns the decrypted content (for tests / small files). */
    public function contents(StoredFile $file): string
    {
        $out = fopen('php://temp', 'w+b');
        $this->decryptTo($file, $out);
        rewind($out);
        $data = stream_get_contents($out);
        fclose($out);

        return $data;
    }

    // ---------------------------------------------------------------------------

    private function validate(UploadedFile $file, ?array $allowedMimes, string $field): string
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([$field => __('The upload failed. Please try again.')]);
        }

        $maxKb = (int) Settings::get('uploads.max_kb');
        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages([$field => __('The file must not be larger than :size MB.', ['size' => round($maxKb / 1024, 1)])]);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: '';
        $allowed = $allowedMimes ?? self::PERSONAL_TYPES;

        if (! in_array($mime, $allowed, true) || ! $this->signatureMatches($file->getRealPath(), $mime)) {
            $names = implode(', ', array_map(fn ($m) => strtoupper(self::MIME_EXTENSIONS[$m]), $allowed));

            throw ValidationException::withMessages([$field => __('Only :types files are accepted.', ['types' => $names])]);
        }

        return $mime;
    }

    /** Belt and braces on top of finfo: check the leading magic bytes ourselves. */
    private function signatureMatches(string $path, string $mime): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 8);

        return match ($mime) {
            'application/pdf' => str_starts_with($head, '%PDF-'),
            'image/jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'image/png' => $head === "\x89PNG\r\n\x1A\n",
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => str_starts_with($head, "PK\x03\x04"),
            default => false,
        };
    }

    /** @param resource $plain @return resource */
    private function encryptStream($plain)
    {
        $out = fopen('php://temp', 'w+b');
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key());
        fwrite($out, $header);

        $chunk = fread($plain, self::CHUNK);
        while ($chunk !== false && $chunk !== '') {
            $next = fread($plain, self::CHUNK);
            $tag = ($next === false || $next === '') ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            fwrite($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag));
            $chunk = $next;
        }

        rewind($out);

        return $out;
    }

    /** @param resource $out */
    private function decryptTo(StoredFile $file, $out): void
    {
        $in = Storage::disk($file->disk)->readStream($file->path);

        if (! $file->is_encrypted) {
            stream_copy_to_stream($in, $out);
            fclose($in);

            return;
        }

        $header = $this->readExactly($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key());
        $cipherChunk = self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

        while (! feof($in)) {
            $cipher = $this->readExactly($in, $cipherChunk);
            if ($cipher === '') {
                break;
            }

            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
            if ($result === false) {
                throw new RuntimeException("Document {$file->uuid} failed integrity check (tampered or wrong key).");
            }

            fwrite($out, $result[0]);

            if ($result[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                break;
            }
        }

        fclose($in);
    }

    /**
     * fread() on some stream wrappers may return short reads; ciphertext chunks
     * must be read whole or authentication fails.
     *
     * @param resource $in
     */
    private function readExactly($in, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && ! feof($in)) {
            $part = fread($in, $length - strlen($data));
            if ($part === false || $part === '') {
                break;
            }
            $data .= $part;
        }

        return $data;
    }

    private function key(): string
    {
        $raw = (string) config('portal.document_key');
        $key = str_starts_with($raw, 'base64:') ? base64_decode(substr($raw, 7), true) : false;

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('DOCUMENT_ENCRYPTION_KEY is missing or invalid.');
        }

        return $key;
    }

    private function disk(): string
    {
        return config('portal.documents_disk', 'documents');
    }

    /** Keep a readable, safe display name; the stored name is always the UUID. */
    private function safeName(string $original, string $extension): string
    {
        $base = Str::of(pathinfo($original, PATHINFO_FILENAME))->ascii()->replaceMatches('/[^A-Za-z0-9 _.-]/', '')->trim()->limit(80, '');

        return ($base->isEmpty() ? 'document' : $base).'.'.$extension;
    }
}
