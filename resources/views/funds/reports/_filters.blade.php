{{-- Fund report filters. Flags: $showPeriod (fiscal year / date range), $showAsOf, $showFund. --}}
<section class="surface-card mb-4">
    <form class="row g-3" method="GET" data-submit-once>
        <div class="col-md-3">
            <label class="form-label" for="fund-report-branch">Branch</label>
            <select class="form-select @error('branch_id') is-invalid @enderror" id="fund-report-branch" name="branch_id">
                <option value="">{{ $canViewAll ? 'All FRC branches (consolidated)' : 'All my branches' }}</option>
                @foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach
            </select>
            @error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        @if($showFund ?? true)
            <div class="col-md-3">
                <label class="form-label" for="fund-report-fund">Fund</label>
                <select class="form-select @error('fund_id') is-invalid @enderror" id="fund-report-fund" name="fund_id">
                    <option value="">All funds</option>
                    @foreach($funds as $fund)<option value="{{ $fund->id }}" @selected(request('fund_id') == $fund->id)>{{ $fund->label() }}</option>@endforeach
                </select>
                @error('fund_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endif
        @if($showPeriod ?? true)
            <div class="col-md-2">
                <label class="form-label" for="fund-report-year">Fiscal year</label>
                <select class="form-select @error('fiscal_year_id') is-invalid @enderror" id="fund-report-year" name="fiscal_year_id">
                    <option value="">Current fiscal year</option>
                    @foreach($years as $year)<option value="{{ $year->id }}" @selected(request('fiscal_year_id') == $year->id)>{{ $year->name }}</option>@endforeach
                </select>
                @error('fiscal_year_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <label class="form-label" for="fund-report-from">From (optional)</label>
                <input class="form-control @error('from') is-invalid @enderror" id="fund-report-from" type="date" name="from" value="{{ request('from') }}">
                @error('from')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <label class="form-label" for="fund-report-to">To (optional)</label>
                <input class="form-control @error('to') is-invalid @enderror" id="fund-report-to" type="date" name="to" value="{{ request('to') }}">
                @error('to')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endif
        @if($showAsOf ?? false)
            <div class="col-md-3">
                <label class="form-label" for="fund-report-as-of">As of</label>
                <input class="form-control @error('as_of') is-invalid @enderror" id="fund-report-as-of" type="date" name="as_of" value="{{ request('as_of', now()->toDateString()) }}">
                @error('as_of')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endif
        <div class="col-12 form-actions">
            <button class="btn btn-primary">Generate</button>
            <a class="btn btn-outline-primary" href="{{ url()->current() }}">Reset</a>
            @if($showPeriod ?? true)<span class="table-subtext">A date range, when given, replaces the fiscal year. Choosing a parent fund also covers its sub-funds.</span>@endif
        </div>
    </form>
</section>
