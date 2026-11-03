<?php

namespace App\Filament\Resources\Applicants\RelationManagers;

class EducationRelationManager extends ProfileSectionRelationManager
{
    protected static string $relationship = 'educations';

    protected static string $section = 'education';
}
