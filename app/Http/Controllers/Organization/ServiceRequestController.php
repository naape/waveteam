<?php

namespace App\Http\Controllers\Organization;

use App\Actions\ServiceRequests\ClientRequestActions;
use App\Actions\ServiceRequests\SubmitServiceRequest;
use App\Enums\AssignmentStatus;
use App\Enums\OrganizationStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServiceRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ServiceRequestController extends Controller
{
    private const MAX_FILES = 5;

    private function organization(Request $request)
    {
        return $request->user()->organizationMembership()->with('organization')->firstOrFail()->organization;
    }

    public function index(Request $request): View
    {
        $organization = $this->organization($request);
        $filter = $request->query('status');

        $groups = [
            'open' => [ServiceRequestStatus::Submitted, ServiceRequestStatus::UnderReview, ServiceRequestStatus::QuotationPreparation, ServiceRequestStatus::QuotationSent, ServiceRequestStatus::Approved, ServiceRequestStatus::TeamAssigned, ServiceRequestStatus::InProgress],
            'completed' => [ServiceRequestStatus::Completed],
            'cancelled' => [ServiceRequestStatus::Cancelled],
        ];

        $requests = $organization->serviceRequests()
            ->with('serviceCategory')
            ->when(isset($groups[$filter]), fn ($q) => $q->whereIn('status', $groups[$filter]))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('organization.requests.index', compact('requests', 'filter'));
    }

    public function create(Request $request): View
    {
        $organization = $this->organization($request);

        return view('organization.requests.create', [
            'organization' => $organization,
            'categories' => ServiceCategory::where('is_active', true)->orderBy('sort')->pluck('name', 'id'),
            'districts' => District::orderBy('name_en')->get()->mapWithKeys(fn ($d) => [$d->id => $d->localizedName()]),
            'skills' => Skill::where('is_active', true)->with('jobCategory')->orderBy('name')->get()->groupBy(fn ($s) => $s->jobCategory?->name ?? __('Other')),
            'maxMb' => round(Settings::get('uploads.max_kb') / 1024, 1),
            'maxFiles' => self::MAX_FILES,
        ]);
    }

    public function store(Request $request, SubmitServiceRequest $submit): RedirectResponse
    {
        $organization = $this->organization($request);
        abort_if($organization->status === OrganizationStatus::Suspended, 403, __('Your organisation account is suspended.'));

        $maxKb = (int) Settings::get('uploads.max_kb');
        $data = $request->validate([
            'service_category_id' => ['required', Rule::exists('service_categories', 'id')->where('is_active', true)],
            'title' => ['required', 'string', 'min:5', 'max:200'],
            'description' => ['required', 'string', 'min:20', 'max:10000'],
            'required_headcount' => ['required', 'integer', 'min:1', 'max:500'],
            'required_qualifications' => ['nullable', 'string', 'max:2000'],
            'required_experience_years' => ['nullable', 'numeric', 'min:0', 'max:40'],
            'skills' => ['nullable', 'array', 'max:30'],
            'skills.*' => ['integer', Rule::exists('skills', 'id')->where('is_active', true)],
            'district_id' => ['required', 'exists:districts,id'],
            'location_text' => ['required', 'string', 'max:255'],
            'start_date' => ['nullable', 'date', 'after_or_equal:today'],
            'expected_duration_value' => ['nullable', 'integer', 'min:1', 'max:999'],
            'expected_duration_unit' => ['nullable', 'required_with:expected_duration_value', Rule::in(['days', 'weeks', 'months'])],
            'working_hours' => ['nullable', 'string', 'max:150'],
            'special_requirements' => ['nullable', 'string', 'max:5000'],
            'requires_police_clearance' => ['required', 'boolean'],
            'budget_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'attachments.*' => ['file', 'max:'.$maxKb],
        ], [
            'attachments.max' => __('You can attach up to :max files.', ['max' => self::MAX_FILES]),
            'attachments.*.max' => __('Each file must not be larger than :size MB.', ['size' => round($maxKb / 1024, 1)]),
        ], [
            'service_category_id' => __('service category'),
            'district_id' => __('district'),
            'location_text' => __('project location'),
            'required_headcount' => __('number of technicians'),
        ]);

        $serviceRequest = $submit($organization, $request->user(), $data, $request->file('attachments', []));

        return redirect()->route('organization.requests.show', $serviceRequest)
            ->with('status', __('Service request :ref submitted. We will review it and contact you shortly.', ['ref' => $serviceRequest->reference]));
    }

    public function show(Request $request, ServiceRequest $serviceRequest): View
    {
        Gate::authorize('view', $serviceRequest);

        $serviceRequest->load([
            'serviceCategory', 'district', 'skills', 'submitter',
            'statusHistory' => fn ($q) => $q->orderBy('created_at'),
            'attachments' => fn ($q) => $q->where('visible_to_client', true)->with('file'),
            'messages' => fn ($q) => $q->where('is_internal', false)->with(['sender', 'files'])->orderBy('created_at'),
            'quotations' => fn ($q) => $q->where('status', '!=', QuotationStatus::Draft)->with(['items' => fn ($i) => $i->orderBy('sort'), 'pdf'])->latest(),
        ]);

        // The assigned team is only shown when WaveTeam enables it; never PII
        // beyond name, role, dates and verified status.
        $team = $serviceRequest->show_team_to_client
            ? $serviceRequest->assignments()
                ->whereIn('status', [AssignmentStatus::Confirmed, AssignmentStatus::Active, AssignmentStatus::Completed])
                ->with('applicantProfile:id,full_name,verification_status')
                ->get()
            : collect();

        // Mark WaveTeam's messages as read by the client.
        $serviceRequest->messages()->where('is_internal', false)->whereNull('read_at')
            ->whereHas('sender', fn ($q) => $q->where('user_type', 'staff'))
            ->update(['read_at' => now()]);

        return view('organization.requests.show', [
            'serviceRequest' => $serviceRequest,
            'team' => $team,
            'canCancel' => in_array($serviceRequest->status, ClientRequestActions::CANCELLABLE, true),
            'maxMb' => round(Settings::get('uploads.max_kb') / 1024, 1),
        ]);
    }

    public function message(Request $request, ServiceRequest $serviceRequest, ClientRequestActions $actions): RedirectResponse
    {
        Gate::authorize('act', $serviceRequest);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'files' => ['nullable', 'array', 'max:3'],
            'files.*' => ['file', 'max:'.(int) Settings::get('uploads.max_kb')],
        ]);

        $actions->postMessage($serviceRequest, $request->user(), $data['body'], $request->file('files', []));

        return redirect()->to(route('organization.requests.show', $serviceRequest).'#messages')->with('status', __('Message sent to WaveTeam.'));
    }

    public function cancel(Request $request, ServiceRequest $serviceRequest, ClientRequestActions $actions): RedirectResponse
    {
        Gate::authorize('act', $serviceRequest);

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $actions->cancel($serviceRequest, $request->user(), $data['reason']);

        return back()->with('status', __('Service request :ref has been cancelled.', ['ref' => $serviceRequest->reference]));
    }
}
