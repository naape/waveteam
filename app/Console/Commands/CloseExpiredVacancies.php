<?php

namespace App\Console\Commands;

use App\Enums\VacancyStatus;
use App\Models\JobVacancy;
use App\Support\Audit;
use Illuminate\Console\Command;

class CloseExpiredVacancies extends Command
{
    protected $signature = 'vacancies:close-expired';

    protected $description = 'Close published vacancies whose closing date has passed.';

    public function handle(): int
    {
        $count = 0;
        JobVacancy::where('status', VacancyStatus::Published)->whereDate('closing_date', '<', today())
            ->each(function (JobVacancy $v) use (&$count) {
                $v->update(['status' => VacancyStatus::Closed]);
                Audit::log('vacancy.auto_closed', $v);
                $count++;
            });

        $this->info("Vacancies closed: {$count}");

        return self::SUCCESS;
    }
}
