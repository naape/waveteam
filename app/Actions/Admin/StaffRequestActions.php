<?php

namespace App\Actions\Admin;

use App\Actions\ServiceRequests\ChangeServiceRequestStatus;
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

    public function setTeamVisibility(ServiceRequest $request, bool $visible, User $by): void
    {
        $request->forceFill(['show_team_to_client' => $visible])->save();
        Audit::log('service_request.team_visibility', $request, new: ['show_team_to_client' => $visible]);
    }

    private function notifyClient(ServiceRequest $request, string $template, array $extra = []): void
    {
        Notification::send($request->organization->users, new TemplatedNotification($template, [
            'reference' => $request->reference,
            'title' => $request->title,
        ] + $extra, route('organization.requests.show', $request), __('View request')));
    }
}
