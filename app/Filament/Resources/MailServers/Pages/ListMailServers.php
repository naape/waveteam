<?php

namespace App\Filament\Resources\MailServers\Pages;

use App\Filament\Resources\MailServers\MailServerResource;
use App\Models\MailServer;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMailServers extends ListRecords
{
    protected static string $resource = MailServerResource::class;

    public function getSubheading(): ?string
    {
        $active = MailServer::active();

        return $active
            ? __('Portal emails are sent through “:d” (:h).', ['d' => $active->description, 'h' => $active->host])
            : __('No server is active: emails use the .env settings (:m).', ['m' => config('mail.default')]);
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('Add email server'))];
    }
}
