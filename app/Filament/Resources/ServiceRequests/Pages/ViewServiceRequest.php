<?php

namespace App\Filament\Resources\ServiceRequests\Pages;

use App\Actions\Admin\QuotationActions;
use App\Actions\Admin\StaffRequestActions;
use App\Enums\QuotationStatus;
use App\Enums\ServiceRequestStatus;
use App\Filament\Resources\Quotations\QuotationForm;
use App\Filament\Resources\ServiceRequests\ServiceRequestResource;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewServiceRequest extends ViewRecord
{
    protected static string $resource = ServiceRequestResource::class;

    /** Eager-load everything the page shows (strict mode forbids lazy loading). */
    protected function resolveRecord(int|string $key): \Illuminate\Database\Eloquent\Model
    {
        return parent::resolveRecord($key)->load(['organization', 'submitter', 'serviceCategory', 'district', 'manager', 'skills', 'attachments.file', 'statusHistory.changer']);
    }

    public function getTitle(): string
    {
        return $this->record->reference;
    }

    private function actions(): StaffRequestActions
    {
        return app(StaffRequestActions::class);
    }

    private function can(string $permission): bool
    {
        return auth()->user()->can($permission);
    }

    protected function getHeaderActions(): array
    {
        /** @var ServiceRequest $r */
        $r = $this->record;
        $draft = $r->quotations()->where('status', QuotationStatus::Draft)->latest()->first();
        $maxKb = (int) Settings::get('uploads.max_kb');

        return [
            Action::make('matches')->label(__('Find workforce'))->icon(Heroicon::OutlinedUserGroup)->color('success')
                ->visible(fn () => $this->can('workforce.match') && ! $r->status->isClosed())
                ->url(ServiceRequestResource::getUrl('matches', ['record' => $r])),

            Action::make('reply')->label(__('Reply to client'))->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->visible(fn () => $this->can('service_requests.manage') && $r->status !== ServiceRequestStatus::Cancelled)
                ->schema([
                    Textarea::make('body')->label(__('Message'))->required()->rows(5)->maxLength(5000),
                    FileUpload::make('files')->label(__('Attachments'))->multiple()->maxFiles(3)->storeFiles(false)->maxSize($maxKb)
                        ->acceptedFileTypes(\App\Support\Documents\DocumentVault::PROJECT_TYPES),
                ])
                ->action(function (array $data) use ($r) {
                    $this->actions()->postMessage($r, auth()->user(), $data['body'], false, array_values($data['files'] ?? []));
                    Notification::make()->title(__('Reply sent; client notified'))->success()->send();
                }),

            Action::make('newQuotation')->label($draft ? __('Edit quotation draft') : __('Prepare quotation'))->icon(Heroicon::OutlinedDocumentCurrencyDollar)->color('info')
                ->visible(fn () => $this->can('quotations.manage') && in_array($r->status, QuotationActions::QUOTABLE, true))
                ->modalWidth('5xl')
                ->fillForm(fn () => $draft ? QuotationForm::fill($draft) : [
                    'items' => [['description' => '', 'quantity' => 1, 'unit' => '', 'unit_price' => null]],
                    'tax_rate' => 0, 'valid_until' => now()->addDays(30)->toDateString(), 'terms' => QuotationForm::DEFAULT_TERMS,
                ])
                ->schema(QuotationForm::schema())
                ->action(function (array $data) use ($r, $draft) {
                    $q = QuotationForm::save($r, $data, $draft);
                    Notification::make()->title(__('Draft :ref saved', ['ref' => $q->reference]))->body(__('Use “Approve & send” when ready.'))->success()->send();
                }),

            Action::make('sendQuotation')->label(__('Approve & send quotation'))->icon(Heroicon::OutlinedPaperAirplane)->color('success')
                ->visible(fn () => $draft !== null && $this->can('quotations.approve'))
                ->requiresConfirmation()
                ->modalDescription(fn () => $draft ? __('Send :ref (LKR :t) to the client as a PDF?', ['ref' => $draft->reference, 't' => number_format($draft->total, 2)]) : null)
                ->action(function () use ($draft) {
                    app(QuotationActions::class)->send($draft, auth()->user());
                    Notification::make()->title(__('Quotation sent to the client'))->success()->send();
                }),

            ActionGroup::make([
                Action::make('status')->label(__('Change status'))->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn () => $this->can('service_requests.manage') && $r->status->allowedNext() !== [])
                    ->schema([
                        Select::make('status')->required()->options(collect($r->status->allowedNext())->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])),
                        Textarea::make('note')->label(__('Note (required when cancelling; shown in history)'))->maxLength(1000),
                    ])
                    ->action(function (array $data) use ($r) {
                        $this->actions()->changeStatus($r, ServiceRequestStatus::from($data['status']), auth()->user(), $data['note'] ?? null);
                        Notification::make()->title(__('Status updated; client notified'))->success()->send();
                    }),
                Action::make('note')->label(__('Add internal note'))->icon(Heroicon::OutlinedLockClosed)
                    ->visible(fn () => $this->can('service_requests.view'))
                    ->schema([Textarea::make('body')->label(__('Note (staff only)'))->required()->rows(4)->maxLength(5000)])
                    ->action(function (array $data) use ($r) {
                        $this->actions()->postMessage($r, auth()->user(), $data['body'], true);
                        Notification::make()->title(__('Internal note added'))->success()->send();
                    }),
                Action::make('manager')->label(__('Assign manager'))->icon(Heroicon::OutlinedUserCircle)
                    ->visible(fn () => $this->can('service_requests.manage'))
                    ->fillForm(['manager' => $r->assigned_manager_id])
                    ->schema([Select::make('manager')->options(ServiceRequestResource::managerOptions())->placeholder(__('Unassigned'))])
                    ->action(function (array $data) use ($r) {
                        $this->actions()->assignManager($r, filled($data['manager']) ? User::find($data['manager']) : null, auth()->user());
                        Notification::make()->title(__('Manager updated'))->success()->send();
                    }),
                Action::make('attach')->label(__('Add attachment'))->icon(Heroicon::OutlinedPaperClip)
                    ->visible(fn () => $this->can('service_requests.manage'))
                    ->schema([
                        TextInput::make('title')->maxLength(150),
                        FileUpload::make('file')->required()->storeFiles(false)->maxSize($maxKb)->acceptedFileTypes(\App\Support\Documents\DocumentVault::PROJECT_TYPES),
                        Toggle::make('visible')->label(__('Visible to the client'))->default(true),
                    ])
                    ->action(function (array $data) use ($r) {
                        $this->actions()->addAttachment($r, auth()->user(), $data['file'], $data['title'] ?? null, (bool) $data['visible']);
                        Notification::make()->title(__('Attachment added'))->success()->send();
                    }),
                Action::make('teamVisibility')
                    ->label($r->show_team_to_client ? __('Hide team from client') : __('Show team to client'))
                    ->icon($r->show_team_to_client ? Heroicon::OutlinedEyeSlash : Heroicon::OutlinedEye)
                    ->visible(fn () => $this->can('service_requests.manage'))
                    ->requiresConfirmation()
                    ->modalDescription(__('When shown, the client sees each assigned person’s name, role and dates — no other personal data.'))
                    ->action(function () use ($r) {
                        $this->actions()->setTeamVisibility($r, ! $r->show_team_to_client, auth()->user());
                        Notification::make()->title(__('Team visibility updated'))->success()->send();
                    }),
            ])->label(__('More'))->button()->color('gray'),
        ];
    }
}
