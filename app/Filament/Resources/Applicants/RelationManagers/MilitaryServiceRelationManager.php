<?php

namespace App\Filament\Resources\Applicants\RelationManagers;

class MilitaryServiceRelationManager extends ProfileSectionRelationManager
{
    protected static string $relationship = 'militaryServices';

    protected static string $section = 'military';
}
