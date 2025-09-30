@extends('layouts.app')

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Edit REST API Credential</h3>
            </div>
            <div class="card-body">
                <form action="{{ route('settings.rest-api.credentials.update', $credential) }}" method="POST">
                    @csrf
                    @method('PUT')
                    @include('settings.rest-api.credentials._form')
                    <button type="submit" class="btn btn-primary">Update</button>
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
    const credentialId = {{ $credential->id }};

    function fetchParams(typeId) {
        if (!typeId) {
            paramsContainer.innerHTML = '';
            return;
        }

        // The controller will handle populating the form with existing data
        const url = `{{ route('settings.rest-api.credentials.params', ['typeId' => 'TYPE_ID_PLACEHOLDER']) }}`.replace('TYPE_ID_PLACEHOLDER', typeId) + `?credential_id=${credentialId}`;

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

    // Initial load of params
    if (authTypeSelect.value) {
        fetchParams(authTypeSelect.value);
    }
});
</script>
@endpush