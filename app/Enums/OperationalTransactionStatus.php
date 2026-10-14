<?php

namespace App\Enums;

enum OperationalTransactionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Disbursed = 'disbursed';
    case Posted = 'posted';
    case Reversed = 'reversed';
}
