<?php

namespace App\Http\Controllers\Organization;

use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Http\Controllers\Controller;
use App\Models\OrganizationDocument;
use App\Rules\PhoneNumberRule;
use App\Support\Audit;
use App\Support\Documents\DocumentVault;
use App\Support\PhoneNumber;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Organisation details and supporting documents. Once WaveTeam has verified
 * the organisation, its legal identity (name, type, registration number) is
 * locked and can only be changed by WaveTeam.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $membership = $request->user()->organizationMembership()->with('organization.documents.file')->firstOrFail();

        return view('organization.profile', [
            'membership' => $membership,
            'organization' => $membership->organization,
            'identityLocked' => $membership->organization->status !== OrganizationStatus::Pending,
            'organizationTypes' => OrganizationType::options(),
            'maxMb' => round(Settings::get('uploads.max_kb') / 1024, 1),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $membership = $request->user()->organizationMembership()->with('organization')->firstOrFail();
        $organization = $membership->organization;
        $locked = $organization->status !== OrganizationStatus::Pending;

        $data = $request->validate([
            'organization_name' => [Rule::requiredIf(! $locked), 'string', 'min:2', 'max:200'],
            'organization_type' => [Rule::requiredIf(! $locked), Rule::enum(OrganizationType::class)],
            'registration_number' => ['nullable', 'string', 'max:60'],
            'address' => ['required', 'string', 'max:500'],
            'billing_address' => ['nullable', 'string', 'max:500'],
            'phone' => ['required', 'string', 'max:25', new PhoneNumberRule],
            'contact_name' => ['required', 'string', 'min:3', 'max:150'],
            'designation' => ['required', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:25', new PhoneNumberRule],
        ]);

        $before = Arr::only($organization->getAttributes(), ['name', 'organization_type', 'registration_number', 'address', 'billing_address', 'phone']);

        $organization->fill([
            'address' => $data['address'],
            'billing_address' => ($data['billing_address'] ?? null) ?: $data['address'],
            'phone' => PhoneNumber::normalize($data['phone']),
        ]);

        if (! $locked) {
            $organization->fill([
                'name' => $data['organization_name'],
                'organization_type' => $data['organization_type'],
                'registration_number' => $data['registration_number'] ?? null,
            ]);
        }
        $organization->save();

        $membership->update([
            'designation' => $data['designation'],
            'phone' => PhoneNumber::normalize($data['contact_phone'] ?? null),
        ]);
        $request->user()->forceFill(['name' => $data['contact_name']])->save();

        Audit::log('organization.updated', $organization, $before, Arr::only($organization->getAttributes(), array_keys($before)));

        return back()->with('status', __('Organisation details saved.'));
    }

    public function storeDocument(Request $request, DocumentVault $vault): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'file' => ['required', 'file', 'max:'.(int) Settings::get('uploads.max_kb')],
        ]);

        $organization = $request->user()->organizationMembership()->firstOrFail()->organization;
        $stored = $vault->store($request->file('file'), $request->user(), DocumentVault::PROJECT_TYPES);

        $doc = OrganizationDocument::create([
            'organization_id' => $organization->id,
            'title' => $data['title'],
            'stored_file_id' => $stored->id,
            'uploaded_by' => $request->user()->id,
        ]);

        Audit::log('organization.document_uploaded', $doc, meta: ['file' => $stored->uuid]);

        return back()->with('status', __('Document uploaded.'));
    }
}
