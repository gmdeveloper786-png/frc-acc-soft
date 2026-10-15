<?php

namespace App\Models;

use Database\Factories\FundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fund (General, Zakat/Charity, Donor-restricted, Grant project, Capital).
 * Funds are organisation-wide records; the branch dimension stays on the
 * journal. A grant fund may carry the grant amount, donor and period.
 */
#[Fillable(['code', 'name', 'type', 'category', 'parent_id', 'donor_name', 'grant_amount', 'start_date', 'end_date', 'restriction_notes', 'is_active', 'created_by'])]
class Fund extends Model
{
    /** @use HasFactory<FundFactory> */
    use HasFactory;

    public const TYPE_UNRESTRICTED = 'unrestricted';

    public const TYPE_RESTRICTED = 'restricted';

    /** @var array<string, string> */
    public const TYPES = [self::TYPE_UNRESTRICTED => 'Unrestricted', self::TYPE_RESTRICTED => 'Restricted'];

    public const CATEGORY_GRANT = 'grant';

    /** @var array<string, string> */
    public const CATEGORIES = ['general' => 'General', 'zakat' => 'Zakat / Charity', 'donor' => 'Donor-restricted', self::CATEGORY_GRANT => 'Grant project', 'capital' => 'Capital / Building'];

    /** Fields only a user holding grants.manage may set. */
    public const GRANT_FIELDS = ['donor_name', 'grant_amount', 'start_date', 'end_date'];

    protected function casts(): array
    {
        return ['grant_amount' => 'decimal:4', 'start_date' => 'date', 'end_date' => 'date', 'is_active' => 'boolean'];
    }

    /** @param Builder<Fund> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isRestricted(): bool
    {
        return $this->type === self::TYPE_RESTRICTED;
    }

    public function isGrant(): bool
    {
        return $this->category === self::CATEGORY_GRANT;
    }

    public function label(): string
    {
        return $this->code.' — '.$this->name;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function permittedAccounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'fund_permitted_accounts')->orderBy('code');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
