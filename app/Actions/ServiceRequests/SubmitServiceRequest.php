<?php

namespace App\Actions\ServiceRequests;

use App\Enums\OrganizationStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\Organization;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestAttachment;
use App\Models\ServiceRequestStatusHistory;
use App\Models\User;
use App\Notifications\TemplatedNotification;
use App\Support\Audit;
use App\Support\Documents\DocumentVault;
use App\Support\StaffNotifier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitServiceRequest
{
    public function __construct(private readonly DocumentVault $vault) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     * @param  array<int, UploadedFile>  $files
     */
    public function __invoke(Organization $organization, User $submitter, array $data, array $files = []): ServiceRequest
    {
        // Pending organisations may submit (reviewed alongside the request);
        // suspended ones may not.
        if ($organization->status === OrganizationStatus::Suspended) {
            throw ValidationException::withMessages(['organization' => __('Your organisation account is suspended. Please contact WaveTeam.')]);
        }

        // Encrypt and store files first (outside the transaction: file I/O).
        $stored = [];
        foreach ($files as $i => $file) {
            $stored[] = $this->vault->store($file, $submitter, DocumentVault::PROJECT_TYPES, "attachments.{$i}");
        }

        $request = DB::transaction(function () use ($organization, $submitter, $data, $stored) {
            $request = new ServiceRequest([
                'organization_id' => $organization->id,
                'submitted_by' => $submitter->id,
                'service_category_id' => $data['service_category_id'],
                'title' => $data['title'],
                'description' => $data['description'],
                'required_headcount' => $data['required_headcount'],
                'required_qualifications' => $data['required_qualifications'] ?? null,
                'required_experience_years' => $data['required_experience_years'] ?? 0,
                'district_id' => $data['district_id'],
                'location_text' => $data['location_text'],
                'start_date' => $data['start_date'] ?? null,
                'expected_duration_value' => $data['expected_duration_value'] ?? null,
                'expected_duration_unit' => filled($data['expected_duration_value'] ?? null) ? $data['expected_duration_unit'] : null,
                'working_hours' => $data['working_hours'] ?? null,
                'special_requirements' => $data['special_requirements'] ?? null,
                'requires_police_clearance' => (bool) ($data['requires_police_clearance'] ?? true),
                'budget_amount' => $data['budget_amount'] ?? null,
            ]);
            $request->status = ServiceRequestStatus::Submitted;
            $request->save();

            if (! empty($data['skills'])) {
                $request->skills()->sync($data['skills']);
            }

            foreach ($stored as $file) {
                ServiceRequestAttachment::create([
                    'service_request_id' => $request->id,
                    'title' => $file->original_name,
                    'stored_file_id' => $file->id,
                    'visible_to_client' => true,
                    'uploaded_by' => $submitter->id,
                ]);
            }

            ServiceRequestStatusHistory::create([
                'service_request_id' => $request->id,
                'to_status' => ServiceRequestStatus::Submitted->value,
                'changed_by' => $submitter->id,
            ]);

            return $request;
        });

        Audit::log('service_request.submitted', $request, meta: ['reference' => $request->reference, 'attachments' => count($stored)]);

        $request->load('serviceCategory');
        $vars = [
            'reference' => $request->reference,
            'title' => $request->title,
            'organization_name' => $organization->name,
            'category' => $request->serviceCategory->name,
            'organization_status' => $organization->status->getLabel(),
        ];

        $submitter->notify(new TemplatedNotification('service_request.submitted', $vars, route('organization.requests.show', $request), __('View request')));
        StaffNotifier::notify('service_requests.manage', new TemplatedNotification('service_request.submitted.admin', $vars, url(config('portal.admin_path')), __('Open admin portal')));

        return $request;
    }
}
