<?php

namespace Database\Seeders;

use App\Services\Funds\WorkbookFundCodes;
use Illuminate\Database\Seeder;

/**
 * Loads the client workbook fund codes. For tests and UAT only: it is not
 * called by DatabaseSeeder, and real data loads the codes through the Funds
 * screen instead.
 */
class WorkbookFundCodeSeeder extends Seeder
{
    public function run(WorkbookFundCodes $codes): void
    {
        $codes->load(null);
    }
}
