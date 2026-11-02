<?php

namespace App\Console\Commands;

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Support\Audit;
use Illuminate\Console\Command;

class ExpireQuotations extends Command
{
    protected $signature = 'quotations:expire';

    protected $description = 'Mark sent quotations past their validity date as expired.';

    public function handle(): int
    {
        $count = 0;
        Quotation::where('status', QuotationStatus::Sent)->whereDate('valid_until', '<', today())
            ->each(function (Quotation $q) use (&$count) {
                $q->forceFill(['status' => QuotationStatus::Expired])->save();
                Audit::log('quotation.expired', $q);
                $count++;
            });

        $this->info("Quotations expired: {$count}");

        return self::SUCCESS;
    }
}
