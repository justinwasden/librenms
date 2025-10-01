@extends('layouts.librenmsv1')

@section('title', __('Create REST API Credential'))

@section('content')
<div class="container-fluid">
    <x-panel>
        <x-slot name="title">
            <i class="fa fa-key fa-fw fa-lg" aria-hidden="true"></i> {{ __('Create REST API Credential') }}
        </x-slot>

        <form action="{{ route('settings.rest-api.credentials.store') }}" method="POST">
            @csrf
            @include('settings.rest-api.credentials._form')

            <div class="form-group">
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-check"></i> {{ __('Create') }}
                </button>
                <a href="{{ route('settings.rest-api.credentials.index') }}" class="btn btn-default">
                    <i class="fa fa-times"></i> {{ __('Cancel') }}
                </a>
            </div>
        </form>
    </x-panel>
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

        const url = "{{ route('settings.rest-api.credentials.params', ['typeId' => '__TYPE_ID__']) }}".replace('__TYPE_ID__', typeId);

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