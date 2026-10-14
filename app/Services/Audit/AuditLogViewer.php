<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read-only access to the audit trail. Entries that belong to a branch are
 * limited to the viewer's branches; entries without a branch (settings, roles,
 * user administration, authority changes) are visible to every holder of
 * `audit_logs.view`. Nothing in this class writes.
 */
class AuditLogViewer
{
    public const PER_PAGE = 25;

    /** Event suffixes that mean the system refused the attempted action. */
    public const REFUSED_SUFFIXES = ['denied', 'blocked', 'rejected'];

    /**
     * Shortcuts to sensitive financial actions. Patterns are SQL LIKE patterns
     * over real event names written by the services.
     *
     * @var array<string, array{label: string, patterns: list<string>}>
     */
    public const QUICK_FILTERS = [
        'posting' => ['label' => 'Journal posting', 'patterns' => ['journal.posted']],
        'reversal' => ['label' => 'Journal reversal', 'patterns' => ['journal.reversed']],
        'po_approval' => ['label' => 'PO approvals', 'patterns' => ['purchase!_orders.approved', 'purchase!_orders.approval!_denied', 'purchase!_bills.po!_exception!_%']],
        'director_authority' => ['label' => 'Director authority', 'patterns' => ['approval!_authority.%', 'approval!_delegations.%']],
        'approval_settings' => ['label' => 'Approval settings', 'patterns' => ['approval!_settings.changed']],
        'disbursement' => ['label' => 'Disbursements', 'patterns' => ['%.disbursed', 'payroll.payment!_raised', 'payroll.paid']],
        'gl_mapping' => ['label' => 'GL mapping changes', 'patterns' => ['account!_mappings.%']],
        'coa_import' => ['label' => 'COA import', 'patterns' => ['account!_import.%']],
        'payroll_approval' => ['label' => 'Payroll approvals', 'patterns' => ['payroll.approved', 'payroll.rejected', 'payroll.posted', 'payroll.reversed']],
        'depreciation' => ['label' => 'Depreciation posting', 'patterns' => ['depreciation.posted', 'depreciation.reversed']],
    ];

    public function __construct(private BranchAccess $access) {}

    /** @return Builder<AuditLog> Entries the user may see. */
    public function visible(User $user): Builder
    {
        return AuditLog::query()->where(fn (Builder $query) => $query
            ->whereNull('audit_logs.branch_id')
            ->orWhereIn('audit_logs.branch_id', $this->access->scope($user)->select('branches.id')));
    }

    public function canView(User $user, AuditLog $log): bool
    {
        return $log->branch_id === null || $this->access->scope($user)->whereKey($log->branch_id)->exists();
    }

    /**
     * @param  array{from?: ?string, to?: ?string, actor_id?: int|string|null, module?: ?string, event?: ?string, record_type?: ?string, record_id?: int|string|null, branch_id?: int|string|null, result?: ?string, search?: ?string, quick?: ?string}  $filters
     * @return Builder<AuditLog>
     */
    public function filtered(User $user, array $filters): Builder
    {
        $query = $this->visible($user);

        if (filled($filters['branch_id'] ?? null)) {
            abort_unless($this->access->scope($user)->whereKey($filters['branch_id'])->exists(), 403);
            $query->where('audit_logs.branch_id', (int) $filters['branch_id']);
        }
        if (filled($filters['from'] ?? null)) {
            $query->whereDate('audit_logs.created_at', '>=', $filters['from']);
        }
        if (filled($filters['to'] ?? null)) {
            $query->whereDate('audit_logs.created_at', '<=', $filters['to']);
        }
        if (filled($filters['actor_id'] ?? null)) {
            $query->where('audit_logs.actor_id', (int) $filters['actor_id']);
        }
        if (filled($filters['module'] ?? null)) {
            $query->whereRaw("audit_logs.event LIKE ? ESCAPE '!'", [$this->escape($filters['module']).'.%']);
        }
        if (filled($filters['event'] ?? null)) {
            $query->where('audit_logs.event', $filters['event']);
        }
        if (filled($filters['record_type'] ?? null)) {
            $query->where('audit_logs.auditable_type', $filters['record_type']);
        }
        if (filled($filters['record_id'] ?? null)) {
            $query->where('audit_logs.auditable_id', (int) $filters['record_id']);
        }
        if (filled($filters['result'] ?? null)) {
            $this->applyResult($query, $filters['result'] === 'refused');
        }
        if (isset(self::QUICK_FILTERS[$filters['quick'] ?? ''])) {
            $patterns = self::QUICK_FILTERS[$filters['quick']]['patterns'];
            $query->where(function (Builder $events) use ($patterns): void {
                foreach ($patterns as $pattern) {
                    $events->orWhereRaw("audit_logs.event LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }
        if (filled($filters['search'] ?? null)) {
            $this->applySearch($query, $user, (string) $filters['search']);
        }

        return $query;
    }

    /**
     * Filter choices taken from the entries the user can actually see.
     *
     * @return array{modules: list<string>, events: list<string>, record_types: list<string>, actors: Collection<int, User>, branches: Collection<int, Branch>}
     */
    public function options(User $user): array
    {
        $events = $this->visible($user)->distinct()->orderBy('event')->pluck('event');

        return [
            'modules' => $events->map(fn (string $event): string => AuditLogPresenter::module($event))->unique()->sort()->values()->all(),
            'events' => $events->all(),
            'record_types' => $this->visible($user)->whereNotNull('auditable_type')->distinct()->orderBy('auditable_type')->pluck('auditable_type')->all(),
            'actors' => User::query()->whereIn('id', $this->visible($user)->whereNotNull('actor_id')->select('actor_id'))->orderBy('name')->get(['id', 'name']),
            'branches' => $this->access->scope($user)->orderBy('name')->get(['id', 'name']),
        ];
    }

    /** @param Builder<AuditLog> $query */
    public function payrollSensitive(Builder $query): void
    {
        $query->where('audit_logs.event', 'like', 'payroll%')
            ->orWhere('audit_logs.event', 'like', 'employees.%')
            ->orWhereRaw("COALESCE(audit_logs.auditable_type, '') LIKE ?", ['%Payroll%'])
            ->orWhereRaw("COALESCE(audit_logs.auditable_type, '') LIKE ?", ['%Employee%']);
    }

    /** @param Builder<AuditLog> $query */
    private function applyResult(Builder $query, bool $refused): void
    {
        $query->where(function (Builder $events) use ($refused): void {
            foreach (self::REFUSED_SUFFIXES as $suffix) {
                $refused
                    ? $events->orWhere('audit_logs.event', 'like', '%'.$suffix)
                    : $events->where('audit_logs.event', 'not like', '%'.$suffix);
            }
        });
    }

    /**
     * Free text over the event name and the recorded properties. Viewers
     * without `payroll.view_sensitive` cannot search the properties of payroll
     * and employee entries, so masked amounts cannot be discovered by guessing.
     *
     * @param  Builder<AuditLog>  $query
     */
    private function applySearch(Builder $query, User $user, string $search): void
    {
        $term = '%'.$this->escape(mb_strtolower(trim($search))).'%';
        $maySearchPayroll = $user->can('payroll.view_sensitive');

        $query->where(function (Builder $match) use ($term, $maySearchPayroll): void {
            $match->whereRaw("LOWER(audit_logs.event) LIKE ? ESCAPE '!'", [$term])
                ->orWhere(function (Builder $properties) use ($term, $maySearchPayroll): void {
                    $properties->whereRaw("LOWER(CAST(audit_logs.properties AS CHAR)) LIKE ? ESCAPE '!'", [$term]);
                    if (! $maySearchPayroll) {
                        $properties->whereNot(fn (Builder $payroll) => $this->payrollSensitive($payroll));
                    }
                });
        });
    }

    private function escape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
