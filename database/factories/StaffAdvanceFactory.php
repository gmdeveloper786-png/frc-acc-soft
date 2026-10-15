<?php

namespace Database\Factories;

use App\Enums\StaffAdvanceStatus;
use App\Enums\StaffAdvanceType;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollComponent;
use App\Models\StaffAdvance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A draft register entry without a schedule. Use StaffAdvanceService to create
 * advances with a generated recovery schedule.
 *
 * @extends Factory<StaffAdvance>
 */
class StaffAdvanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(), 'branch_id' => Branch::factory(), 'type' => StaffAdvanceType::Advance,
            'principal_amount' => '1000.0000', 'instalment_count' => 1, 'first_recovery_period_id' => AccountingPeriod::factory(),
            'payroll_component_id' => PayrollComponent::factory(), 'purpose' => 'Test advance', 'status' => StaffAdvanceStatus::Draft, 'created_by' => User::factory(),
        ];
    }
}
