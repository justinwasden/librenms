@extends('layouts.librenmsv1')
@section('content')
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
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Apply REST API Template</h3>
            </div>
            <div class="panel-body">
                <form action="{{ route('device.rest-api.apply-template', $device) }}" method="POST" class="form-inline">
                    @csrf
                    <div class="form-group">
                        <label for="template_id" class="sr-only">Template</label>
                        <select name="template_id" id="template_id" class="form-control" required style="width: 300px;">
                            <option value="">Select a Template</option>
                            @foreach($templates as $template)
                                <option value="{{ $template->id }}">{{ $template->name }} ({{ $template->vendor }})</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Apply Template</button>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- NEW SECTION: Add Custom Connection --}}
<div class="row" style="margin-top: 20px;">
    <div class="col-md-12">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Add Custom Connection</h3>
            </div>
            <div class="panel-body">
                <form action="{{ route('device.rest-api.connections.store', $device) }}" method="POST" class="form-inline">
                    @csrf
                    <div class="form-group mr-2">
                        <label for="name" class="sr-only">Name</label>
                        <input type="text" name="name" class="form-control" placeholder="Connection Name" required>
                    </div>
                    <div class="form-group mr-2">
                        <label for="base_url" class="sr-only">Base URL</label>
                        <input type="url" name="base_url" class="form-control" placeholder="Base URL (https://...)" required style="width: 400px;">
                    </div>
                    <button type="submit" class="btn btn-success">Create Connection</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row" style="margin-top: 20px;">
    <div class="col-md-12">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">API Connections</h3>
            </div>
            <div class="panel-body">
                @if($device->restApiConnections->isEmpty())
                    <p>No REST API connections configured for this device.</p>
                @else
                    @foreach($device->restApiConnections as $connection)
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <h4 class="panel-title pull-left" style="padding-top: 7.5px;">
                                    {{ $connection->name }}
                                </h4>
                                <div class="pull-right">
                                    {{-- NEW: Apply/Edit Credentials Button --}}
                                    <button type="button" class="btn btn-sm btn-warning" data-toggle="modal" data-target="#credentialModal{{ $connection->id }}">
                                        {{ $connection->credential ? 'Edit Credentials' : 'Apply Credentials' }}
                                    </button>

                                    {{-- Edit Connection (placeholder for future implementation) --}}
                                    <a href="#" class="btn btn-sm btn-info" disabled>Edit Connection</a>

                                    {{-- Existing Delete Connection Form --}}
                                    <form action="{{ route('device.rest-api.connections.destroy', [$device, $connection]) }}" method="POST" style="display: inline;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this connection and all its endpoints?')">Delete Connection</button>
                                    </form>
                                </div>
                                <div class="clearfix"></div>
                            </div>
                            <div class="panel-body">
                                <p><strong>Base URL:</strong> {{ $connection->base_url }}</p>
                                <p><strong>Credential:</strong> {{ $connection->credential->name ?? 'None Applied' }}
                                    @if($connection->credential)
                                        <a href="#" class="btn btn-xs btn-default ml-2" data-toggle="modal" data-target="#credentialModal{{ $connection->id }}">View Params</a>
                                    @endif
                                </p>
                                <p><strong>Rate Limit:</strong> {{ $connection->rate_limit }} reqs/min</p>

                                <h5 style="margin-top: 20px;">Endpoints:
                                    {{-- NEW: Button to add custom endpoint to this connection --}}
                                    <button type="button" class="btn btn-xs btn-success ml-2" data-toggle="modal" data-target="#endpointModal{{ $connection->id }}">Add Endpoint</button>
                                </h5>
                                <table class="table table-condensed table-striped">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Method</th>
                                            <th>Path</th>
                                            <th>Last Polled</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($connection->endpoints as $endpoint)
                                            <tr>
                                                <td>{{ $endpoint->name }}</td>
                                                <td><span class="label label-info">{{ $endpoint->method }}</span></td>
                                                <td><code>{{ $endpoint->path }}</code></td>
                                                <td>{{ $endpoint->last_polled ? $endpoint->last_polled->diffForHumans() : 'Never' }}</td>
                                                <td>
                                                    {{-- NEW: Delete Endpoint Action --}}
                                                    <form action="{{ route('device.rest-api.endpoints.destroy', [$device, $endpoint]) }}" method="POST" style="display: inline;">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('Delete this endpoint?')">Delete</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        @if(!$loop->last)
                            <hr>
                        @endif
                    @endforeach
                @endif
            </div>
        </div>
    </div>
</div>

{{-- MODAL PLACEHOLDERS (Credentials and Endpoints) --}}
{{-- Note: Actual modal logic requires complex AJAX/JS/PHP which cannot be fully implemented here. --}}
@foreach($device->restApiConnections as $connection)
    <div class="modal fade" id="credentialModal{{ $connection->id }}" tabindex="-1" role="dialog" aria-labelledby="credentialModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form action="{{ route('device.rest-api.connections.credentials.update', [$device, $connection]) }}" method="POST">
                    @csrf
                    @method('POST') {{-- Use POST for update as per standard practice --}}
                    <div class="modal-header">
                        <h5 class="modal-title" id="credentialModalLabel">Apply Credentials to {{ $connection->name }}</h5>
                    </div>
                    <div class="modal-body">
                        {{-- In a real implementation, this selection should trigger an AJAX call to load the required parameters based on auth_type_id --}}
                        <div class="form-group">
                            <label for="credential_id">Select Credential (Global)</label>
                            <select name="credential_id" class="form-control">
                                <option value="">No Credentials</option>
                                {{-- Loop through your global RestApiCredential::all() here --}}
                            </select>
                        </div>
                        <p>Credentials will be applied at the connection level.</p>
                        {{-- This section is where the fields dynamically loaded from RestApiCredentialController::getAuthTypeParams would appear --}}
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Save Credentials</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="endpointModal{{ $connection->id }}" tabindex="-1" role="dialog" aria-labelledby="endpointModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form action="{{ route('device.rest-api.connections.update', [$device, $connection]) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="modal-header">
                        <h5 class="modal-title" id="endpointModalLabel">Add Endpoint to {{ $connection->name }}</h5>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_endpoint">
                        <div class="form-group"><label>Name</label><input type="text" name="endpoint_name" class="form-control" required></div>
                        <div class="form-group"><label>Method</label><select name="endpoint_method" class="form-control"><option>GET</option><option>POST</option></select></div>
                        <div class="form-group"><label>Path</label><input type="text" name="endpoint_path" class="form-control" required></div>
                        <p>Map fields: <textarea name="endpoint_metric_map" class="form-control" rows="5" placeholder='{"metric_name": "json.path"}'></textarea></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-success">Add Endpoint</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endforeach

@endsection