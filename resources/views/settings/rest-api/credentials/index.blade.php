@extends('layouts.librenmsv1')

@section('content')
{{-- Success/Error Messages --}}
@if ($errors->any())
    <div class="alert alert-danger alert-dismissible">
        <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if(session('success'))
    <div class="alert alert-success alert-dismissible">
        <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
        {{ session('success') }}
    </div>
@endif

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">REST API Credentials</h3>
                <div class="card-tools">
                    <button type="button" class="btn btn-sm btn-primary" data-toggle="modal" data-target="#createCredentialModal">
                        <i class="fa fa-plus"></i> Add Credential
                    </button>
                </div>
            </div>
            <div class="card-body">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Auth Type</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($credentials as $credential)
                            <tr>
                                <td>{{ $credential->name }}</td>
                                <td>{{ $credential->authenticationType->name }}</td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" data-toggle="modal" data-target="#editCredentialModal{{ $credential->id }}">
                                        <i class="fa fa-edit"></i> Edit
                                    </button>
                                    <form action="{{ route('settings.rest-api.credentials.destroy', $credential) }}" method="POST" style="display: inline-block;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this credential?')">
                                            <i class="fa fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center">No credentials configured. Click "Add Credential" to create one.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Create Credential Modal --}}
<div class="modal fade" id="createCredentialModal" tabindex="-1" role="dialog" aria-labelledby="createCredentialModalLabel">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form action="{{ route('settings.rest-api.credentials.store') }}" method="POST" id="createCredentialForm">
                @csrf
                <div class="modal-header">
                    <h4 class="modal-title" id="createCredentialModalLabel">Create REST API Credential</h4>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="create_name">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="create_name" class="form-control" placeholder="e.g., PureStorage API Token" required>
                        <small class="form-text text-muted">A descriptive name for this credential</small>
                    </div>

                    <div class="form-group">
                        <label for="create_authentication_type_id">Authentication Type <span class="text-danger">*</span></label>
                        <select name="authentication_type_id" id="create_authentication_type_id" class="form-control" required>
                            <option value="">-- Select Authentication Type --</option>
                            @foreach($authTypes as $type)
                                <option value="{{ $type->id }}">{{ $type->name }}</option>
                            @endforeach
                        </select>
                        <small class="form-text text-muted">Choose the authentication method for this API</small>
                    </div>

                    <div id="create-auth-params-container">
                        {{-- Parameters will be loaded here via AJAX --}}
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">
                        <i class="fa fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save"></i> Create Credential
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Edit Credential Modals --}}
@foreach($credentials as $credential)
<div class="modal fade" id="editCredentialModal{{ $credential->id }}" tabindex="-1" role="dialog" aria-labelledby="editCredentialModalLabel{{ $credential->id }}">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form action="{{ route('settings.rest-api.credentials.update', $credential) }}" method="POST">
                @csrf
                @method('PUT')
                <div class="modal-header">
                    <h4 class="modal-title" id="editCredentialModalLabel{{ $credential->id }}">Edit Credential: {{ $credential->name }}</h4>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="edit_name_{{ $credential->id }}">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit_name_{{ $credential->id }}" class="form-control" value="{{ old('name', $credential->name) }}" required>
                    </div>

                    <div class="form-group">
                        <label for="edit_authentication_type_id_{{ $credential->id }}">Authentication Type <span class="text-danger">*</span></label>
                        <select name="authentication_type_id" id="edit_authentication_type_id_{{ $credential->id }}" class="form-control" required>
                            <option value="">-- Select Authentication Type --</option>
                            @foreach($authTypes as $type)
                                <option value="{{ $type->id }}" @if($credential->authentication_type_id == $type->id) selected @endif>{{ $type->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div id="edit-auth-params-container-{{ $credential->id }}">
                        {{-- Load existing parameters --}}
                        @foreach($credential->params as $param)
                        <div class="form-group">
                            <label for="params_{{ $param->key }}_{{ $credential->id }}">{{ ucfirst(str_replace('_', ' ', $param->key)) }}</label>
                            @if(str_contains($param->key, 'password') || str_contains($param->key, 'token') || str_contains($param->key, 'secret'))
                                <input type="password" name="params[{{ $param->key }}]" id="params_{{ $param->key }}_{{ $credential->id }}" class="form-control" value="{{ $param->value }}">
                            @else
                                <input type="text" name="params[{{ $param->key }}]" id="params_{{ $param->key }}_{{ $credential->id }}" class="form-control" value="{{ $param->value }}">
                            @endif
                        </div>
                        @endforeach
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">
                        <i class="fa fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save"></i> Update Credential
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endforeach

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Handle Create Modal
    const createAuthTypeSelect = document.getElementById('create_authentication_type_id');
    const createParamsContainer = document.getElementById('create-auth-params-container');

    function fetchCreateParams(typeId) {
        if (!typeId) {
            createParamsContainer.innerHTML = '';
            return;
        }

        const url = "{{ route('settings.rest-api.credentials.params', ['typeId' => 'TYPE_ID_PLACEHOLDER']) }}".replace('TYPE_ID_PLACEHOLDER', typeId);

        fetch(url)
            .then(response => response.text())
            .then(html => {
                createParamsContainer.innerHTML = html;
            })
            .catch(error => {
                console.error('Error fetching auth params:', error);
                createParamsContainer.innerHTML = '<div class="alert alert-danger">Failed to load authentication parameters. Please try again.</div>';
            });
    }

    if (createAuthTypeSelect) {
        createAuthTypeSelect.addEventListener('change', function () {
            fetchCreateParams(this.value);
        });
    }

    // Handle Edit Modals
    @foreach($credentials as $credential)
    (function() {
        const editAuthTypeSelect = document.getElementById('edit_authentication_type_id_{{ $credential->id }}');
        const editParamsContainer = document.getElementById('edit-auth-params-container-{{ $credential->id }}');

        function fetchEditParams(typeId) {
            if (!typeId) {
                return;
            }

            const url = "{{ route('settings.rest-api.credentials.params', ['typeId' => 'TYPE_ID_PLACEHOLDER']) }}".replace('TYPE_ID_PLACEHOLDER', typeId);

            fetch(url)
                .then(response => response.text())
                .then(html => {
                    editParamsContainer.innerHTML = html;
                })
                .catch(error => {
                    console.error('Error fetching auth params:', error);
                });
        }

        if (editAuthTypeSelect) {
            editAuthTypeSelect.addEventListener('change', function () {
                fetchEditParams(this.value);
            });
        }
    })();
    @endforeach

    // Clear form when create modal is closed
    $('#createCredentialModal').on('hidden.bs.modal', function () {
        document.getElementById('createCredentialForm').reset();
        createParamsContainer.innerHTML = '';
    });
});
</script>
@endpush
