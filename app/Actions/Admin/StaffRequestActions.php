<?php

namespace App\Actions\Admin;

use App\Actions\ServiceRequests\ChangeServiceRequestStatus;
use App\Enums\AssignmentStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestAttachment;
use App\Models\ServiceRequestMessage;
use App\Models\User;
use App\Notifications\TemplatedNotification;
use App\Support\Audit;
use App\Support\Documents\DocumentVault;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * What WaveTeam staff do on a service request.
 */
class StaffRequestActions
{
    public function __construct(
        private readonly ChangeServiceRequestStatus $changeStatus,
        private readonly DocumentVault $vault,
    ) {}

    public function changeStatus(ServiceRequest $request, ServiceRequestStatus $to, User $by, ?string $note = null): void
    {
        if (! in_array($to, $request->status->allowedNext(), true)) {
            throw ValidationException::withMessages(['status' => __('A request cannot move from :from to :to.', ['from' => $request->status->getLabel(), 'to' => $to->getLabel()])]);
        }

        if ($to === ServiceRequestStatus::Cancelled && blank($note)) {
            throw ValidationException::withMessages(['note' => __('A reason is required to cancel a request.')]);
        }

        ($this->changeStatus)($request, $to, $by, $note);
        $this->notifyClient($request, 'service_request.status_changed', ['status' => $to->getLabel()]);
    }

    /** @param array<int, UploadedFile> $files */
    public function postMessage(ServiceRequest $request, User $by, string $body, bool $internal, array $files = []): ServiceRequestMessage
    {
        $stored = collect($files)->map(fn ($f, $i) => $this->vault->store($f, $by, DocumentVault::PROJECT_TYPES, "files.{$i}"));

        $message = DB::transaction(function () use ($request, $by, $body, $internal, $stored) {
            $message = ServiceRequestMessage::create([
                'service_request_id' => $request->id,
                'sender_id' => $by->id,
                'body' => $body,
                'is_internal' => $internal,
            ]);
            $message->files()->attach($stored->pluck('id'));

            return $message;
        });

        Audit::log($internal ? 'service_request.internal_note' : 'service_request.reply', $request, meta: ['message_id' => $message->id]);

        if (! $internal) {
            $this->notifyClient($request, 'service_request.message');
        }

        return $message;
    }

    public function addAttachment(ServiceRequest $request, User $by, UploadedFile $file, ?string $title, bool $visibleToClient): ServiceRequestAttachment
    {
        $stored = $this->vault->store($file, $by, DocumentVault::PROJECT_TYPES);

        $attachment = ServiceRequestAttachment::create([
            'service_request_id' => $request->id,
            'title' => $title ?: $stored->original_name,
            'stored_file_id' => $stored->id,
            'visible_to_client' => $visibleToClient,
            'uploaded_by' => $by->id,
        ]);

        Audit::log('service_request.attachment_added', $request, meta: ['attachment_id' => $attachment->id, 'visible_to_client' => $visibleToClient]);

        return $attachment;
    }

    public function assignManager(ServiceRequest $request, ?User $manager, User $by): void
    {
        if ($manager && (! $manager->isStaff() || ! $manager->can('service_requests.manage'))) {
            throw ValidationException::withMessages(['manager' => __('The manager must be a staff member who can manage service requests.')]);
        }

        $old = $request->assigned_manager_id;
        $request->forceFill(['assigned_manager_id' => $manager?->id])->save();
        Audit::log('service_request.manager_assigned', $request, ['assigned_manager_id' => $old], ['assigned_manager_id' => $manager?->id]);
    }

    /** Request fields staff may correct after submission. */
    public const EDITABLE = [
        'service_category_id', 'title', 'description', 'required_headcount', 'required_qualifications',
        'required_experience_years', 'district_id', 'location_text', 'start_date', 'expected_duration_value',
        'expected_duration_unit', 'working_hours', 'special_requirements', 'requires_police_clearance', 'budget_amount',
    ];

    /**
     * Corrects the request details. Every change is audited with the reason;
     * optionally the client is told through the request conversation.
     *
     * @param  array<string, mixed>  $data  validated form data (+ 'skills' => int[])
     * @return list<string>  the fields that changed
     */
    public function updateDetails(ServiceRequest $request, array $data, User $by, string $reason, bool $tellClient = false): array
    {
        if ($request->status->isClosed()) {
            throw ValidationException::withMessages(['title' => __('Completed or cancelled requests cannot be edited.')]);
        }

        $data['expected_duration_unit'] = filled($data['expected_duration_value'] ?? null) ? ($data['expected_duration_unit'] ?? null) : null;
        $data['required_experience_years'] = $data['required_experience_years'] ?? 0;

        $before = $request->only(self::EDITABLE) + ['skills' => $request->skills()->pluck('skills.id')->sort()->values()->all()];

        DB::transaction(function () use ($request, $data) {
            $request->fill(array_intersect_key($data, array_flip(self::EDITABLE)))->save();

            if (array_key_exists('skills', $data)) {
                // Keep each kept skill's minimum years; new skills start at 0.
                $existing = $request->skills()->pluck('service_request_skills.min_years', 'skills.id');
                $request->skills()->sync(collect($data['skills'] ?? [])->mapWithKeys(fn ($id) => [(int) $id => ['min_years' => $existing[(int) $id] ?? 0]])->all());
            }
        });

        $request->refresh();
        $after = $request->only(self::EDITABLE) + ['skills' => $request->skills()->pluck('skills.id')->sort()->values()->all()];
        $changed = array_keys(array_filter($after, fn ($v, $k) => $this->normalise($before[$k] ?? null) !== $this->normalise($v), ARRAY_FILTER_USE_BOTH));

        if (! $changed) {
            return [];
        }

        Audit::log('service_request.details_updated', $request,
            array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)),
            ['reason' => $reason, 'fields' => $changed, 'client_told' => $tellClient]);

        if ($tellClient) {
            $labels = collect($changed)->map(fn ($f) => self::fieldLabel($f))->implode(', ');
            $this->postMessage($request, $by, __("We have updated the details of your request (:fields).\n\n:reason", ['fields' => $labels, 'reason' => $reason]), internal: false);
        }

        return $changed;
    }

    public static function fieldLabel(string $field): string
    {
        return match ($field) {
            'service_category_id' => __('service type'),
            'required_headcount' => __('number of people'),
            'required_qualifications' => __('qualifications'),
            'required_experience_years' => __('experience'),
            'district_id' => __('district'),
            'location_text' => __('location'),
            'start_date' => __('start date'),
            'expected_duration_value', 'expected_duration_unit' => __('duration'),
            'requires_police_clearance' => __('police clearance requirement'),
            'budget_amount' => __('budget'),
            default => __(str_replace('_', ' ', $field)),
        };
    }

    private function normalise(mixed $v): mixed
    {
        return match (true) {
            $v instanceof \DateTimeInterface => $v->format('Y-m-d'),
            is_bool($v) => $v ? '1' : '0',
            is_array($v) => implode(',', $v),
            $v === null || $v === '' => null,
            is_numeric($v) => (string) (0 + $v),
            default => (string) $v,
        };
    }

    public function setTeamVisibility(ServiceRequest $request, bool $visible, User $by): void
    {
        $request->forceFill(['show_team_to_client' => $visible])->save();
        Audit::log('service_request.team_visibility', $request, new: ['show_team_to_client' => $visible]);
    }

    /** Assignment statuses that mean people are (or are about to be) working on the request. */
    public const OPEN_ASSIGNMENTS = [AssignmentStatus::Proposed, AssignmentStatus::Confirmed, AssignmentStatus::Active];

    /**
     * Moves the request to "Deleted" (soft delete). Nothing is erased: quotations,
     * messages, attachments and history stay and the request can be restored.
     * Refused while people are proposed/confirmed/active on it.
     */
    public function delete(ServiceRequest $request, User $by, string $reason): void
    {
        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['reason' => __('A reason is required to delete a request.')]);
        }

        $open = $request->assignments()->whereIn('status', self::OPEN_ASSIGNMENTS)->count();
        if ($open > 0) {
            throw ValidationException::withMessages(['reason' => trans_choice(
                '{1} :n person is still proposed, confirmed or active on this request. Release or cancel the assignment first.|[2,*] :n people are still proposed, confirmed or active on this request. Release or cancel their assignments first.',
                $open, ['n' => $open])]);
        }

        $request->delete();
        Audit::log('service_request.deleted', $request, meta: ['reason' => trim($reason), 'reference' => $request->reference, 'status' => $request->status->value]);
    }

    public function restore(ServiceRequest $request, User $by): void
    {
        $request->restore();
        Audit::log('service_request.restored', $request, meta: ['reference' => $request->reference]);
    }

    private function notifyClient(ServiceRequest $request, string $template, array $extra = []): void
    {
        Notification::send($request->organization->users, new TemplatedNotification($template, [
            'reference' => $request->reference,
            'title' => $request->title,
        ] + $extra, route('organization.requests.show', $request), __('View request')));
    }
}
