<?php

namespace App\Http\Controllers;

use App\Models\StoredFile;
use App\Support\Audit;
use App\Support\Documents\DocumentVault;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download of a queued report export: only by the staff member who
 * requested it, only while they still hold reports.export.
 */
class ReportDownloadController extends Controller
{
    public function __invoke(Request $request, StoredFile $file, DocumentVault $vault): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user->isStaff() && $user->can('reports.export') && $file->uploaded_by === $user->id
            && str_starts_with($file->original_name, 'waveteam-'), 404);

        Audit::log('report.downloaded', $file);

        return $vault->response($file, download: true);
    }
}
