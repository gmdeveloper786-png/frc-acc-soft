<?php

namespace App\Enums;

enum FiscalYearStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
}
