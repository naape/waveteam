<?php

namespace App\Filament\Resources\MailServers\Pages;

use App\Actions\Admin\MailServerActions;
use App\Filament\Resources\MailServers\MailServerResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditMailServer extends EditRecord
{
    protected static string $resource = MailServerResource::class;

    protected function getHeaderActions(): array
    {
        return [MailServerResource::testAction()];
    }

    /** Never send the saved password back to the browser. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['password']);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(MailServerActions::class)->save($record, $data, auth()->user());
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('index');
    }
}
