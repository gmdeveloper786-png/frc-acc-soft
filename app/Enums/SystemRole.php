<?php

namespace App\Enums;

enum SystemRole: string
{
    case Administrator = 'Administrator';
    case Accountant = 'Accountant';
    case Cashier = 'Cashier / Fee Clerk';
    case Approver = 'Approver / Manager';
    case Director = 'Director';
    case Management = 'Management / Board';
    case Auditor = 'Auditor';
}
