<?php

namespace App\Enums;

enum AccountingPeriodStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
}
