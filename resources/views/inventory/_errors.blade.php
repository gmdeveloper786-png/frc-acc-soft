@if($errors->any())
    <div class="alert alert-danger" role="alert">@foreach($errors->all() as $message)<span class="d-block">{{ $message }}</span>@endforeach</div>
@endif
