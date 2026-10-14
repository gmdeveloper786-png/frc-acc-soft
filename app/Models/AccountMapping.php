<?php

namespace App\Models;

use App\Enums\AccountMappingKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['mapping_key', 'account_id', 'updated_by'])]
class AccountMapping extends Model
{
    protected function casts(): array
    {
        return ['mapping_key' => AccountMappingKey::class];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
