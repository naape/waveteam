<?php

namespace App\Filament\Resources\Applicants\RelationManagers;

class EmploymentRelationManager extends ProfileSectionRelationManager
{
    protected static string $relationship = 'employments';

    protected static string $section = 'employment';
}
