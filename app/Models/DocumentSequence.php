<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['document_type', 'scope_key', 'next_number'])]
class DocumentSequence extends Model
{
    protected function casts(): array
    {
        return ['next_number' => 'integer'];
    }
}
