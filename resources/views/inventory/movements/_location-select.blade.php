{{-- One "branch — location" list: $field, $label, $locations (grouped by branch name). --}}
<label class="form-label" for="{{ $field }}">{{ $label }}</label>
<select class="form-select @error($field) is-invalid @enderror" id="{{ $field }}" name="{{ $field }}" required>
    <option value="">Select branch location</option>
    @foreach($locations as $branchName => $branchLocations)
        <optgroup label="{{ $branchName }}">
            @foreach($branchLocations as $location)<option value="{{ $location->id }}" @selected(old($field)==$location->id)>{{ $branchName }} — {{ $location->name }}</option>@endforeach
        </optgroup>
    @endforeach
</select>
@error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
