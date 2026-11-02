<?php

namespace App\Http\Controllers;

use App\Models\StoredFile;
use App\Support\Audit;
use App\Support\Documents\DocumentVault;
use App\Support\Documents\OrganizationFileAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Secure download of organisation-side files (attachments, quotation PDFs,
 * supporting documents). Addressed by the file's random UUID; every access
 * is authorised and audited.
 */
class OrganizationFileController extends Controller
{
    public function __invoke(Request $request, StoredFile $file, DocumentVault $vault): StreamedResponse
    {
        [$allowed, $context] = OrganizationFileAccess::check($request->user(), $file);

        // 404 rather than 403 so file UUIDs can't be probed for existence.
        abort_unless($allowed, 404);

        Audit::log($request->boolean('download') ? 'file.downloaded' : 'file.viewed', $file, meta: ['context' => $context]);

        return $vault->response($file, $request->boolean('download'));
    }
}
