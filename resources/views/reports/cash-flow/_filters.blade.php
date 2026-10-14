{{-- Shared period and branch filters of the cash statements. --}}
@foreach(['fiscal_year_id','from','to','branch_id'] as $field) @error($field)<div class="alert alert-danger">{{ $message }}</div>@enderror @endforeach
<div class="surface-card mb-4"><form class="row g-3" method="GET">
    <div class="col-md-3"><label class="form-label" for="filter_fiscal_year">Fiscal year</label><select class="form-select" id="filter_fiscal_year" name="fiscal_year_id"><option value="">Custom dates</option>@foreach($years as $option)<option value="{{ $option->id }}" @selected($year?->id === $option->id)>{{ $option->name }}</option>@endforeach</select></div>
    <div class="col-md-3"><label class="form-label" for="filter_from">From</label><input class="form-control" id="filter_from" type="date" name="from" value="{{ request('from') }}"></div>
    <div class="col-md-3"><label class="form-label" for="filter_to">To</label><input class="form-control" id="filter_to" type="date" name="to" value="{{ request('to') }}"></div>
    <div class="col-md-3"><label class="form-label" for="filter_branch">Branch</label><select class="form-select" id="filter_branch" name="branch_id">
        @if($canViewAll)<option value="all" @selected($allBranches)>All branches (consolidated)</option>@endif
        @foreach($branches as $option)<option value="{{ $option->id }}" @selected($branch?->id === $option->id)>{{ $option->name }}</option>@endforeach
    </select></div>
    <div class="col-12"><button class="btn btn-primary">Generate statement</button><span class="table-subtext d-inline ms-2">Dates, when entered, take precedence over the fiscal year. Only posted journals are counted.</span></div>
</form></div>
