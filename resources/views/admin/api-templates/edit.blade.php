@extends('layouts.librenmsv1')

@section('title', 'Edit API Template: ' . $template->name)

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif
        </div>
    </div>

    <div class="row">
        <!-- Template Settings -->
        <div class="col-md-5">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <i class="fa fa-cog"></i> Template Settings
                        @if($template->is_system)
                            <span class="label label-info">System</span>
                        @endif
                    </h3>
                </div>
                <div class="panel-body">
                    @if($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form action="{{ route('admin.api-templates.update', $template) }}" method="POST">
                        @csrf
                        @method('PUT')

                        <div class="form-group">
                            <label for="key">Template Key <span class="text-danger">*</span></label>
                            <input type="text" name="key" id="key" class="form-control"
                                   value="{{ old('key', $template->key) }}" required
                                   pattern="[a-z0-9_]+" title="Lowercase letters, numbers, and underscores only"
                                   {{ $template->is_system ? 'readonly' : '' }}>
                        </div>

                        <div class="form-group">
                            <label for="name">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="name" class="form-control"
                                   value="{{ old('name', $template->name) }}" required>
                        </div>

                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea name="description" id="description" class="form-control" rows="2">{{ old('description', $template->description) }}</textarea>
                        </div>

                        <div class="form-group">
                            <label for="auth_type">Authentication Type <span class="text-danger">*</span></label>
                            <select name="auth_type" id="auth_type" class="form-control" required>
                                @foreach($authSchemas as $schema)
                                    <option value="{{ $schema->key }}" {{ old('auth_type', $template->auth_type) == $schema->key ? 'selected' : '' }}>
                                        {{ $schema->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="base_url_pattern">Base URL Pattern</label>
                            <input type="text" name="base_url_pattern" id="base_url_pattern" class="form-control"
                                   value="{{ old('base_url_pattern', $template->base_url_pattern) }}"
                                   placeholder="https://{hostname}/api/v1">
                            <span class="help-block">Use {hostname} as placeholder</span>
                        </div>

                        <div class="form-group">
                            <label for="os_types">OS Types</label>
                            <input type="text" name="os_types" id="os_types" class="form-control"
                                   value="{{ old('os_types', implode(', ', $template->os_types ?? [])) }}"
                                   placeholder="purestorage, proxmox">
                            <span class="help-block">Comma-separated LibreNMS OS identifiers</span>
                        </div>

                        <div class="form-group">
                            <label for="capabilities">Capabilities</label>
                            <input type="text" name="capabilities" id="capabilities" class="form-control"
                                   value="{{ old('capabilities', implode(', ', $template->capabilities ?? [])) }}"
                                   placeholder="sensors, ports, inventory">
                        </div>

                        <div class="checkbox">
                            <label>
                                <input type="hidden" name="enabled" value="0">
                                <input type="checkbox" name="enabled" value="1" {{ old('enabled', $template->enabled) ? 'checked' : '' }}>
                                Enabled
                            </label>
                        </div>

                        <hr>

                        <div class="form-group">
                            <a href="{{ route('admin.api-templates.index') }}" class="btn btn-default">Back to List</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-save"></i> Save Template
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Endpoints -->
        <div class="col-md-7">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <i class="fa fa-list"></i> Endpoints
                        <span class="badge">{{ $template->endpoints->count() }}</span>
                    </h3>
                </div>
                <div class="panel-body">
                    <!-- Add Endpoint Form -->
                    <form action="{{ route('admin.api-templates.endpoints.store', $template) }}" method="POST" class="well well-sm">
                        @csrf
                        <h5><i class="fa fa-plus"></i> Add Endpoint</h5>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Capability</label>
                                    <input type="text" name="capability" class="form-control input-sm" required
                                           placeholder="sensors">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Method</label>
                                    <select name="method" class="form-control input-sm">
                                        <option value="GET">GET</option>
                                        <option value="POST">POST</option>
                                        <option value="PUT">PUT</option>
                                        <option value="PATCH">PATCH</option>
                                        <option value="DELETE">DELETE</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Path</label>
                                    <input type="text" name="path" class="form-control input-sm" required
                                           placeholder="/api/2.0/arrays">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Transform</label>
                                    <input type="text" name="transform" class="form-control input-sm"
                                           placeholder="Pure\\ArraySensors">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>For Each</label>
                                    <input type="text" name="for_each" class="form-control input-sm"
                                           placeholder="proxmox_nodes">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="checkbox">
                                    <label>
                                        <input type="hidden" name="enabled" value="0">
                                        <input type="checkbox" name="enabled" value="1" checked> Enabled
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-success btn-sm">
                                    <i class="fa fa-plus"></i> Add
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Endpoints List -->
                    <div class="table-responsive">
                        <table class="table table-striped table-condensed">
                            <thead>
                                <tr>
                                    <th style="width: 20%">Capability</th>
                                    <th style="width: 8%">Method</th>
                                    <th style="width: 30%">Path</th>
                                    <th style="width: 20%">Transform</th>
                                    <th style="width: 7%">Status</th>
                                    <th style="width: 15%">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="endpoints-list">
                                @forelse($template->endpoints as $endpoint)
                                    <tr data-id="{{ $endpoint->id }}">
                                        <td>
                                            <code>{{ $endpoint->capability }}</code>
                                            @if($endpoint->for_each)
                                                <br><small class="text-muted">foreach: {{ $endpoint->for_each }}</small>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="label label-{{ $endpoint->method == 'GET' ? 'primary' : ($endpoint->method == 'POST' ? 'success' : 'warning') }}">
                                                {{ $endpoint->method }}
                                            </span>
                                        </td>
                                        <td><code class="small">{{ Str::limit($endpoint->path, 40) }}</code></td>
                                        <td>
                                            @if($endpoint->transform)
                                                <code class="small">{{ Str::limit($endpoint->transform, 25) }}</code>
                                            @else
                                                <span class="text-muted">-</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($endpoint->enabled)
                                                <span class="label label-success">On</span>
                                            @else
                                                <span class="label label-default">Off</span>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-xs">
                                                <form action="{{ route('admin.api-templates.endpoints.toggle', $endpoint) }}" method="POST" style="display:inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-default" title="{{ $endpoint->enabled ? 'Disable' : 'Enable' }}">
                                                        <i class="fa fa-{{ $endpoint->enabled ? 'toggle-on' : 'toggle-off' }}"></i>
                                                    </button>
                                                </form>
                                                <button type="button" class="btn btn-primary btn-edit-endpoint"
                                                        data-endpoint="{{ json_encode($endpoint) }}" title="Edit">
                                                    <i class="fa fa-edit"></i>
                                                </button>
                                                <form action="{{ route('admin.api-templates.endpoints.destroy', $endpoint) }}" method="POST" style="display:inline"
                                                      onsubmit="return confirm('Delete this endpoint?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-danger" title="Delete">
                                                        <i class="fa fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">
                                            No endpoints configured. Add one above.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Endpoint Modal -->
<div class="modal fade" id="editEndpointModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="editEndpointForm" method="POST">
                @csrf
                @method('PUT')
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title">Edit Endpoint</h4>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Capability</label>
                        <input type="text" name="capability" id="edit_capability" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Method</label>
                        <select name="method" id="edit_method" class="form-control">
                            <option value="GET">GET</option>
                            <option value="POST">POST</option>
                            <option value="PUT">PUT</option>
                            <option value="PATCH">PATCH</option>
                            <option value="DELETE">DELETE</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Path</label>
                        <input type="text" name="path" id="edit_path" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Transform</label>
                        <input type="text" name="transform" id="edit_transform" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>For Each</label>
                        <input type="text" name="for_each" id="edit_for_each" class="form-control">
                    </div>
                    <div class="checkbox">
                        <label>
                            <input type="hidden" name="enabled" value="0">
                            <input type="checkbox" name="enabled" id="edit_enabled" value="1"> Enabled
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection

@section('scripts')
<script>
$(function() {
    // Edit endpoint button handler
    $('.btn-edit-endpoint').on('click', function() {
        var endpoint = $(this).data('endpoint');
        $('#edit_capability').val(endpoint.capability);
        $('#edit_method').val(endpoint.method);
        $('#edit_path').val(endpoint.path);
        $('#edit_transform').val(endpoint.transform);
        $('#edit_for_each').val(endpoint.for_each);
        $('#edit_enabled').prop('checked', endpoint.enabled);
        $('#editEndpointForm').attr('action', '{{ url("admin/api-templates/endpoints") }}/' + endpoint.id);
        $('#editEndpointModal').modal('show');
    });
});
</script>
@endsection
