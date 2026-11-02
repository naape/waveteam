<?php

namespace App\Http\Controllers\Applicant;

use App\Actions\Applicant\UploadApplicantDocument;
use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Applicant document centre (police clearance has its own dedicated page).
 */
class DocumentController extends Controller
{
    /** Types an applicant uploads here, with guidance. */
    public static function uploadableTypes(): array
    {
        return [
            DocumentType::Cv->value => ['label' => __('CV / résumé'), 'hint' => __('PDF preferred')],
            DocumentType::Nic->value => ['label' => __('NIC copy (both sides)'), 'hint' => __('One PDF, or a clear photo')],
            DocumentType::EducationCert->value => ['label' => __('Educational certificate'), 'hint' => __('You can upload several')],
            DocumentType::ProfessionalCert->value => ['label' => __('Professional certificate'), 'hint' => __('You can upload several')],
            DocumentType::DrivingLicence->value => ['label' => __('Driving licence copy'), 'hint' => __('Both sides')],
            DocumentType::Other->value => ['label' => __('Other supporting document'), 'hint' => null],
        ];
    }

    public function index(Request $request): View
    {
        $profile = $request->user()->applicantProfile()->firstOrFail();

        $documents = $profile->documents()
            ->with('file')
            ->whereNotIn('document_type', [DocumentType::Photo, DocumentType::PoliceClearance])
            ->orderByDesc('is_current')->orderByDesc('created_at')
            ->get()
            ->groupBy(fn ($d) => $d->document_type->value);

        return view('applicant.documents.index', [
            'types' => self::uploadableTypes(),
            'documents' => $documents,
            'maxMb' => round(Settings::get('uploads.max_kb') / 1024, 1),
        ]);
    }

    public function store(Request $request, UploadApplicantDocument $upload): RedirectResponse
    {
        $data = $request->validate([
            'document_type' => ['required', Rule::in(array_keys(self::uploadableTypes()))],
            'title' => ['nullable', 'string', 'max:150'],
            'file' => ['required', 'file', 'max:'.(int) Settings::get('uploads.max_kb')],
        ], ['file.max' => __('The file must not be larger than :size MB.', ['size' => round(Settings::get('uploads.max_kb') / 1024, 1)])]);

        $profile = $request->user()->applicantProfile()->firstOrFail();
        $type = DocumentType::from($data['document_type']);

        $upload($profile, $type, $request->file('file'), $request->user(), $data['title'] ?? null);

        return redirect()->route('applicant.documents.index')
            ->with('status', __(':type uploaded. It will be checked by the WaveTeam team.', ['type' => self::uploadableTypes()[$type->value]['label']]));
    }
}
