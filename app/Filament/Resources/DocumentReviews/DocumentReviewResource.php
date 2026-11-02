<?php

namespace App\Filament\Resources\DocumentReviews;

use App\Actions\Admin\ApplicantReview;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\Applicants\ApplicantResource;
use App\Filament\Resources\DocumentReviews\Pages\ListDocumentReviews;
use App\Models\ApplicantDocument;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Verification queue for applicant documents (CV, NIC, certificates, licence).
 * Police clearance certificates have their own queue with renewal dates.
 */
class DocumentReviewResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = ApplicantDocument::class;

    protected static ?string $slug = 'document-verification';

    protected static ?string $modelLabel = 'document';

    protected static ?string $navigationLabel = 'Document verification';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Applicants';

    protected static ?int $navigationSort = 2;

    protected static array $permissions = ['view' => 'documents.view'];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNotIn('document_type', [DocumentType::PoliceClearance, DocumentType::Photo])
            ->where('is_current', true)
            ->with(['applicantProfile.user', 'file', 'reviewer']);
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $count = static::getEloquentQuery()->where('status', DocumentStatus::PendingVerification)->count();

        return $count ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        $review = fn () => app(ApplicantReview::class);
        $canVerify = fn () => auth()->user()->can('documents.verify');

        return $table
            ->columns([
                TextColumn::make('applicantProfile.full_name')->label(__('Applicant'))->searchable()
                    ->url(fn (ApplicantDocument $r) => ApplicantResource::getUrl('view', ['record' => $r->applicant_profile_id])),
                TextColumn::make('document_type')->label(__('Type'))->badge()->color('gray'),
                TextColumn::make('title')->state(fn (ApplicantDocument $r) => $r->title ?: $r->file?->original_name)->limit(40),
                TextColumn::make('version')->prefix('v'),
                TextColumn::make('created_at')->label(__('Uploaded'))->since()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('reviewer.name')->label(__('Reviewed by'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at')
            ->filters([
                SelectFilter::make('status')->options([
                    DocumentStatus::PendingVerification->value => DocumentStatus::PendingVerification->getLabel(),
                    DocumentStatus::Verified->value => DocumentStatus::Verified->getLabel(),
                    DocumentStatus::Rejected->value => DocumentStatus::Rejected->getLabel(),
                ])->default(DocumentStatus::PendingVerification->value),
                SelectFilter::make('document_type')->label(__('Type'))->options(collect(DocumentType::cases())
                    ->reject(fn ($t) => in_array($t, [DocumentType::PoliceClearance, DocumentType::Photo], true))
                    ->mapWithKeys(fn ($t) => [$t->value => $t->getLabel()])->all()),
            ])
            ->recordActions([
                Action::make('open')->label(__('Open'))->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                    ->url(fn (ApplicantDocument $r) => route('documents.file', $r), shouldOpenInNewTab: true),
                Action::make('verify')->label(__('Verify'))->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (ApplicantDocument $r) => $canVerify() && $r->status !== DocumentStatus::Verified)
                    ->requiresConfirmation()
                    ->action(function (ApplicantDocument $r) use ($review) {
                        $review()->reviewDocument($r, true, auth()->user());
                        Notification::make()->title(__('Document verified'))->success()->send();
                    }),
                Action::make('reject')->label(__('Reject'))->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible(fn (ApplicantDocument $r) => $canVerify() && $r->status !== DocumentStatus::Rejected)
                    ->schema([Textarea::make('reason')->label(__('Reason (sent to the applicant)'))->required()->maxLength(500)])
                    ->action(function (ApplicantDocument $r, array $data) use ($review) {
                        $review()->reviewDocument($r, false, auth()->user(), $data['reason']);
                        Notification::make()->title(__('Document rejected; applicant notified'))->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('verifyMany')->label(__('Verify selected'))->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible($canVerify)
                    ->requiresConfirmation()
                    ->modalDescription(__('Confirm you have opened and checked each selected document.'))
                    ->action(function (Collection $records) use ($review) {
                        $records->each(fn ($r) => $review()->reviewDocument($r, true, auth()->user()));
                        Notification::make()->title(__(':n documents verified', ['n' => $records->count()]))->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDocumentReviews::route('/')];
    }
}
