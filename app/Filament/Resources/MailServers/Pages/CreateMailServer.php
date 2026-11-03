<?php

namespace App\Filament\Resources\MailServers\Pages;

use App\Actions\Admin\MailServerActions;
use App\Filament\Resources\MailServers\MailServerResource;
use App\Models\MailServer;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMailServer extends CreateRecord
{
    protected static string $resource = MailServerResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(MailServerActions::class)->save(new MailServer, $data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
