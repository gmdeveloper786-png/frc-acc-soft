<?php

namespace Database\Seeders;

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class FiscalYearSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $year = FiscalYear::query()->firstOrCreate(
            ['start_date' => '2026-01-01', 'end_date' => '2026-12-31'],
            ['name' => 'FY 2026', 'status' => FiscalYearStatus::Draft, 'is_current' => false],
        );
        if ($year->periods()->doesntExist()) {
            foreach (range(1, 12) as $month) {
                $date = CarbonImmutable::create(2026, $month, 1);
                $year->periods()->create(['name' => $date->format('F Y'), 'period_number' => $month, 'start_date' => $date, 'end_date' => $date->endOfMonth(), 'status' => AccountingPeriodStatus::Draft]);
            }
        }
    }
}
