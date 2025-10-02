@extends('layouts.librenmsv1')

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Create REST API Credential</h3>
            </div>
            <div class="card-body">
                <form action="{{ route('settings.rest-api.credentials.store') }}" method="POST">
                    @csrf
                    @include('settings.rest-api.credentials._form')
                    <button type="submit" class="btn btn-primary">Create</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const authTypeSelect = document.getElementById('authentication_type_id');
    const paramsContainer = document.getElementById('auth-params-container');

    function fetchParams(typeId) {
        if (!typeId) {
            paramsContainer.innerHTML = '';
            return;
        }

        const url = "{{ route('settings.rest-api.credentials.params', ['typeId' => 'TYPE_ID_PLACEHOLDER']) }}".replace('TYPE_ID_PLACEHOLDER', typeId);

        fetch(url)
            .then(response => response.text())
            .then(html => {
                paramsContainer.innerHTML = html;
            })
            .catch(error => console.error('Error fetching auth params:', error));
    }

    authTypeSelect.addEventListener('change', function () {
        fetchParams(this.value);
    });

    if (authTypeSelect.value) {
        fetchParams(authTypeSelect.value);
    }
});
</script>
@endpush