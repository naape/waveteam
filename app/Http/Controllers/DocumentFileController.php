<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Models\ApplicantDocument;
use App\Support\Audit;
use App\Support\Documents\DocumentVault;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way an applicant document's bytes ever leave the server.
 * Every access is authorised by ApplicantDocumentPolicy and audit-logged
 * (except routine profile-photo thumbnails).
 */
class DocumentFileController extends Controller
{
    public function __invoke(Request $request, ApplicantDocument $document, DocumentVault $vault): StreamedResponse
    {
        Gate::authorize('view', $document);

        $download = $request->boolean('download');
        $file = $document->file;
        abort_if($file === null, 404);

        if ($document->document_type !== DocumentType::Photo) {
            Audit::log($download ? 'document.downloaded' : 'document.viewed', $document, meta: [
                'type' => $document->document_type->value,
                'version' => $document->version,
            ]);
        }

        return $vault->response($file, $download);
    }
}
