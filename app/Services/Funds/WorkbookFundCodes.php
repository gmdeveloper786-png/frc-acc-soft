<?php

namespace App\Services\Funds;

use App\Models\Fund;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * The fund codes of the client workbook (docs/Chart-of-Accounts.xlsx, sheet
 * "Fund Codes"), offered as a one-click load on the Funds screen. Nothing is
 * created automatically. Loading only adds codes that do not exist yet and
 * never changes an existing fund. Grant sub-codes (GRT-01, GRT-02 ...) are
 * one per real grant and are created by the user, never invented here.
 */
class WorkbookFundCodes
{
    /** @var list<array{code: string, name: string, type: string, category: string, restriction_notes: string}> */
    public const CODES = [
        ['code' => 'GEN', 'name' => 'General Fund', 'type' => Fund::TYPE_UNRESTRICTED, 'category' => 'general', 'restriction_notes' => 'Day-to-day operations; no donor restriction'],
        ['code' => 'DNR', 'name' => 'Donor-Restricted Fund', 'type' => Fund::TYPE_RESTRICTED, 'category' => 'donor', 'restriction_notes' => 'Donations given for a stated purpose'],
        ['code' => 'ZKT', 'name' => 'Zakat / Charity Fund', 'type' => Fund::TYPE_RESTRICTED, 'category' => 'zakat', 'restriction_notes' => '[Confirm] if applicable; usable only for permitted purposes'],
        ['code' => 'GRT', 'name' => 'Grant Project Fund', 'type' => Fund::TYPE_RESTRICTED, 'category' => Fund::CATEGORY_GRANT, 'restriction_notes' => 'One sub-code per grant, e.g. GRT-01, GRT-02'],
        ['code' => 'CAP', 'name' => 'Capital / Building Fund', 'type' => Fund::TYPE_RESTRICTED, 'category' => 'capital', 'restriction_notes' => 'Funds for construction, equipment and fixed assets'],
    ];

    public function __construct(private AuditLogger $audit) {}

    /** @return list<string> Codes of the workbook that are not yet funds. */
    public function missing(): array
    {
        $existing = Fund::query()->whereIn('code', array_column(self::CODES, 'code'))->pluck('code')->all();

        return array_values(array_diff(array_column(self::CODES, 'code'), $existing));
    }

    /**
     * Create the workbook fund codes that are missing. A null actor is only
     * used by the test/UAT seeder.
     *
     * @return list<string> Codes created.
     */
    public function load(?User $actor): array
    {
        if ($actor !== null) {
            abort_unless($actor->is_active && $actor->can('funds.manage'), 403);
        }

        return DB::transaction(function () use ($actor): array {
            $created = [];
            foreach (self::CODES as $definition) {
                if (Fund::query()->where('code', $definition['code'])->lockForUpdate()->exists()) {
                    continue;
                }
                $fund = Fund::query()->create([...$definition, 'is_active' => true, 'created_by' => $actor?->id]);
                $this->audit->log('funds.created', $fund, [...$definition, 'source' => 'workbook fund codes'], $actor);
                $created[] = $definition['code'];
            }

            return $created;
        });
    }
}
