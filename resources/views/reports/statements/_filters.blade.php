{{-- Shared filter form. Flags: $showBranch, $showPeriod, $showCompare, $showAsOf. --}}
<section class="surface-card mb-4">
    <form class="row g-3" method="GET" data-submit-once>
        @if($showBranch ?? true)
            <div class="col-md-3">
                <label class="form-label" for="statement-branch">Branch</label>
                <select class="form-select @error('branch_id') is-invalid @enderror" id="statement-branch" name="branch_id">
                    <option value="">{{ $canViewAll ? 'All FRC branches (consolidated)' : 'All my branches' }}</option>
                    @foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach
                </select>
                @error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endif
        @if($showPeriod ?? true)
            <div class="col-md-3">
                <label class="form-label" for="statement-year">Fiscal year</label>
                <select class="form-select @error('fiscal_year_id') is-invalid @enderror" id="statement-year" name="fiscal_year_id">
                    <option value="">Current fiscal year</option>
                    @foreach($years as $year)<option value="{{ $year->id }}" @selected(request('fiscal_year_id') == $year->id)>{{ $year->name }}</option>@endforeach
                </select>
                @error('fiscal_year_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <label class="form-label" for="statement-from">From (optional)</label>
                <input class="form-control @error('from') is-invalid @enderror" id="statement-from" type="date" name="from" value="{{ request('from') }}">
                @error('from')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <label class="form-label" for="statement-to">To (optional)</label>
                <input class="form-control @error('to') is-invalid @enderror" id="statement-to" type="date" name="to" value="{{ request('to') }}">
                @error('to')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endif
        @if($showCompare ?? false)
            <div class="col-md-2">
                <label class="form-label" for="statement-compare">Comparison</label>
                <select class="form-select @error('compare') is-invalid @enderror" id="statement-compare" name="compare">
                    <option value="">No comparison</option>
                    <option value="previous_period" @selected(request('compare') === 'previous_period')>Previous equal-length period</option>
                    <option value="previous_year" @selected(request('compare') === 'previous_year')>Previous fiscal year</option>
                </select>
                @error('compare')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endif
        @if($showAsOf ?? false)
            <div class="col-md-3">
                <label class="form-label" for="statement-as-of">As of</label>
                <input class="form-control @error('as_of') is-invalid @enderror" id="statement-as-of" type="date" name="as_of" value="{{ request('as_of', now()->toDateString()) }}">
                @error('as_of')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endif
        <div class="col-12 form-actions">
            <button class="btn btn-primary">Generate</button>
            <a class="btn btn-outline-primary" href="{{ url()->current() }}">Reset</a>
            @if($showPeriod ?? true)<span class="table-subtext">A date range, when given, replaces the fiscal year.</span>@endif
        </div>
    </form>
</section>
