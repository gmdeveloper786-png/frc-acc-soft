<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\JournalPostingService;
use App\Services\JournalReversalService;
use App\Services\JournalWorkflowService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Administrator->value);
    $this->checker = User::factory()->create();
    $this->checker->assignRole(SystemRole::Administrator->value);
    $this->poster = User::factory()->create();
    $this->poster->assignRole(SystemRole::Administrator->value);
    $this->auditor = User::factory()->create();
    $this->auditor->assignRole(SystemRole::Auditor->value);
    $year = FiscalYear::factory()->create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $year->id, 'name' => 'January 2026', 'period_number' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->branch = Branch::factory()->create();
    $this->auditor->branches()->attach($this->branch);
    $debit = Account::factory()->create(['code' => '111001']);
    $credit = Account::factory()->create(['code' => '411001', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->payload = [
        'voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => '2026-01-15',
        'fiscal_year_id' => $year->id, 'accounting_period_id' => $this->period->id, 'branch_id' => $this->branch->id,
        'narration' => 'Journal label fixture', 'reference_number' => 'UAT-LABEL',
        'lines' => [['account_id' => $debit->id, 'debit' => '100.0000', 'credit' => '0'], ['account_id' => $credit->id, 'debit' => '0', 'credit' => '100.0000']],
    ];
    $this->workflow = app(JournalWorkflowService::class);
});

/** The journal's detail page and its row on the listing, as the given user sees them. */
function journalScreens(object $test, JournalEntry $journal, User $viewer): array
{
    $test->actingAs($viewer);
    $show = $test->get(route('journals.show', $journal))->assertOk()->getContent();
    $index = $test->get(route('journals.index'))->assertOk()->getContent();
    preg_match('#<tr><td>[^<]*</td><td>[^<]*</td><td>[^<]*</td><td>'.preg_quote($journal->narration, '#').'.*?</tr>#s', $index, $row);

    return [$show, $row[0] ?? ''];
}

test('unposted journals are labelled by their real status and never show a voucher number', function () {
    $journal = $this->workflow->create($this->payload, $this->maker);
    $cases = [
        'Draft' => fn () => null,
        'Submitted' => fn () => $this->workflow->submit($journal->fresh(), $this->maker),
        'Approved' => fn () => $this->actingAs($this->checker) && $this->workflow->approve($journal->fresh(), $this->checker),
        'Returned' => fn () => $this->workflow->reject($journal->fresh(), $this->checker, 'Wrong narration, please correct'),
    ];

    foreach ($cases as $label => $transition) {
        $transition();
        $journal->refresh();
        [$show, $row] = journalScreens($this, $journal, $this->checker);

        expect($journal->voucher_number)->toBeNull()
            ->and($journal->displayTitle())->toBe("{$label} Journal #{$journal->id}")
            ->and($show)->toContain("<h1>{$label} Journal #{$journal->id}</h1>")
            ->and($show)->toContain("<p class=\"page-description\">{$label} •")
            ->and($row)->toContain("<td>{$label} #{$journal->id}</td>")
            ->and($row)->toContain(">{$label}</span>")
            ->and($show.$row)->not->toContain('JV-2026');
        if ($label !== 'Draft') {
            expect($show)->not->toContain('Draft Journal #')->and($row)->not->toContain('Draft #');
        }
    }
});

test('action buttons follow the status and the viewer permissions', function () {
    $journal = $this->workflow->create($this->payload, $this->maker);
    $buttons = fn (User $viewer) => journalScreens($this, $journal->fresh(), $viewer)[0];

    $draft = $buttons($this->maker);
    expect($draft)->toContain('>Submit</button>')->toContain('Edit Draft')->not->toContain('>Approve</button>')->not->toContain('Post Journal');

    $this->workflow->submit($journal->fresh(), $this->maker);
    $submitted = $buttons($this->checker);
    expect($submitted)->toContain('>Approve</button>')->toContain('>Return</button>')->not->toContain('>Submit</button>')->not->toContain('Post Journal')->not->toContain('Edit Draft');

    $this->workflow->approve($journal->fresh(), $this->checker);
    // The approver may return it but never post it; a third user posts.
    $approved = $buttons($this->checker);
    expect($approved)->not->toContain('Post Journal')->toContain('>Return</button>')->not->toContain('>Approve</button>')
        ->and($buttons($this->maker))->not->toContain('Post Journal')
        ->and($buttons($this->poster))->toContain('Post Journal');

    // A read-only Auditor sees the status but no action at all.
    expect($buttons($this->auditor))->toContain("<h1>Approved Journal #{$journal->id}</h1>")
        ->not->toContain('Post Journal')->not->toContain('>Return</button>')->not->toContain('Edit Draft');
});

test('posted and reversed journals keep their real voucher numbers with an accurate reversal indicator', function () {
    $this->actingAs($this->maker);
    $journal = $this->workflow->create($this->payload, $this->maker);
    $this->workflow->submit($journal, $this->maker);
    $this->actingAs($this->checker);
    $this->workflow->approve($journal->fresh(), $this->checker);
    $posted = app(JournalPostingService::class)->post($journal->fresh(), $this->poster);

    [$show, $row] = journalScreens($this, $posted, $this->checker);
    expect($posted->voucher_number)->toStartWith('JV-')
        ->and($show)->toContain("<h1>{$posted->voucher_number}</h1>")->toContain('<p class="page-description">Posted •')->toContain('Request Reversal')
        ->and($row)->toContain("<td>{$posted->voucher_number}</td>")->toContain('>Posted</span>')
        ->and($show)->not->toContain('Journal #'.$posted->id);

    $reversal = app(JournalReversalService::class)->reverse($posted, $this->period, '2026-01-20', 'Entry recorded in error', $this->checker);
    $original = $posted->fresh();
    [$show, $row] = journalScreens($this, $original, $this->checker);
    expect($original->status)->toBe(JournalStatus::Reversed)
        ->and($original->voucher_number)->toBe($posted->voucher_number)
        ->and($show)->toContain("<h1>{$posted->voucher_number}</h1>")->toContain('<p class="page-description">Reversed •')
        ->toContain('This posted voucher has been reversed by <a href="'.route('journals.show', $reversal).'">'.$reversal->voucher_number.'</a>')
        ->not->toContain('Request Reversal')
        ->and($row)->toContain("<td>{$posted->voucher_number}</td>")->toContain('status-badge danger">Reversed</span>');

    $reversalPage = journalScreens($this, $reversal->fresh(), $this->checker)[0];
    expect($reversalPage)->toContain("<h1>{$reversal->voucher_number}</h1>")
        ->toContain('This journal reverses <a href="'.route('journals.show', $original).'">'.$posted->voucher_number.'</a>');
});
