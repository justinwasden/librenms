@extends('layouts.app')

@section('content')
{{-- Global Alerts for Success/Error --}}
@if ($errors->any())
    <div class="alert alert-danger alert-dismissible">... errors ...</div>
@endif
@if(session('success'))
    <div class="alert alert-success alert-dismissible">... success ...</div>
@endif

<div class="row">
    {{-- Apply Template Section --}}
    <div class="col-md-12">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Apply REST API Template</h3>
            </div>
            <div class="panel-body">
                <form action="{{ route('device.rest-api.apply-template', $device) }}" method="POST" class="form-inline">
                    @csrf
                    <div class="form-group">
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

{{-- Add Custom Connection Section (Updated to match controller method) --}}
<div class="row" style="margin-top: 20px;">
    <div class="col-md-12">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Add Custom Connection</h3>
            </div>
            <div class="panel-body">
                <form action="{{ route('device.rest-api.connections.store', $device) }}" method="POST" class="form-inline">
                    @csrf
                    <div class="form-group mr-2"><input type="text" name="name" class="form-control" placeholder="Connection Name" required></div>
                    <div class="form-group mr-2"><input type="url" name="base_url" class="form-control" placeholder="Base URL (https://...)" required style="width: 400px;"></div>
                    <div class="form-group mr-2"><input type="number" name="rate_limit" class="form-control" placeholder="Rate Limit (reqs/min, default 60)" style="width: 150px;"></div>
                    <button type="submit" class="btn btn-success">Create Connection</button>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- API Connections List --}}
<div class="row" style="margin-top: 20px;">
    <div class="col-md-12">
        <div class="panel panel-default">
            <div class="panel-heading"><h3 class="panel-title">API Connections</h3></div>
            <div class="panel-body">
                @forelse($device->restApiConnections as $connection)
                    <div class="panel panel-default">
                        <div class="panel-heading">
                            <h4 class="panel-title pull-left" style="padding-top: 7.5px;">
                                {{ $connection->name }}
                                <span class="label label-{{ $connection->enabled ? 'success' : 'warning' }} ml-2">{{ $connection->enabled ? 'Enabled' : 'Disabled' }}</span>
                            </h4>
                            <div class="pull-right">
                                {{-- Apply/Edit Credentials Button --}}
                                <button type="button" class="btn btn-sm btn-warning" data-toggle="modal" data-target="#credentialModal{{ $connection->id }}">
                                    {{ $connection->credential ? 'Edit Creds' : 'Apply Creds' }}
                                </button>

                                {{-- NEW: Edit Connection Button --}}
                                <button type="button" class="btn btn-sm btn-info" data-toggle="modal" data-target="#connectionEditModal{{ $connection->id }}">
                                    Edit Connection
                                </button>

                                {{-- Existing Delete Connection Form --}}
                                <form action="{{ route('device.rest-api.connections.destroy', [$device, $connection]) }}" method="POST" style="display: inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this connection?')">Delete</button>
                                </form>
                            </div>
                            <div class="clearfix"></div>
                        </div>
                        <div class="panel-body">
                            <p><strong>Base URL:</strong> {{ $connection->base_url }}</p>
                            <p><strong>Credential:</strong> {{ $connection->credential->name ?? 'None Applied' }}</p>
                            <p><strong>Rate Limit:</strong> {{ $connection->rate_limit }} reqs/min</p>
                            <p><strong>SSL Verify:</strong> <span class="label label-{{ $connection->disable_ssl_verify ? 'danger' : 'success' }}">{{ $connection->disable_ssl_verify ? 'Disabled' : 'Enabled' }}</span></p>

                            <h5 style="margin-top: 20px;">Endpoints:
                                {{-- Button to add custom endpoint to this connection --}}
                                <button type="button" class="btn btn-xs btn-success ml-2" data-toggle="modal" data-target="#endpointAddModal{{ $connection->id }}">Add Endpoint</button>
                            </h5>
                            <table class="table table-condensed table-striped">
                                <thead>
                                    <tr><th>Name</th><th>Method</th><th>Path</th><th>Last Polled</th><th>Actions</th></tr>
                                </thead>
                                <tbody>
                                    @foreach($connection->endpoints as $endpoint)
                                        <tr>
                                            <td>{{ $endpoint->name }}</td>
                                            <td><span class="label label-info">{{ $endpoint->method }}</span></td>
                                            <td><code>{{ $endpoint->path }}</code></td>
                                            <td>{{ $endpoint->last_polled ? $endpoint->last_polled->diffForHumans() : 'Never' }}</td>
                                            <td>
                                                {{-- NEW: Edit Endpoint Action --}}
                                                <button type="button" class="btn btn-xs btn-info" data-toggle="modal" data-target="#endpointEditModal{{ $endpoint->id }}">Edit</button>

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
                    @if(!$loop->last)<hr>@endif
                @empty
                    <p>No REST API connections configured for this device.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>

{{-- MODALS --}}
@foreach($device->restApiConnections as $connection)
    {{-- 1. Connection Edit Modal (Handles Issue 2, 5, 6) --}}
    <div class="modal fade" id="connectionEditModal{{ $connection->id }}" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form action="{{ route('device.rest-api.connections.update', [$device, $connection]) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="modal-header"><h5 class="modal-title">Edit Connection: {{ $connection->name }}</h5></div>
                    <div class="modal-body">
                        <div class="form-group"><label>Name</label><input type="text" name="name" class="form-control" value="{{ $connection->name }}" required></div>
                        <div class="form-group"><label>Base URL</label><input type="url" name="base_url" class="form-control" value="{{ $connection->base_url }}" required></div>
                        <div class="form-group"><label>Rate Limit (reqs/min)</label><input type="number" name="rate_limit" class="form-control" value="{{ $connection->rate_limit ?? 60 }}" min="1"></div>

                        {{-- New Feature: Enable/Disable Connection (Goal 6) --}}
                        <div class="checkbox">
                            <label><input type="checkbox" name="enabled" value="1" @if($connection->enabled) checked @endif> **Enable Connection** (Disable stops polling all endpoints)</label>
                        </div>

                        {{-- New Feature: Disable SSL Verify (Goal 5) --}}
                        <div class="checkbox">
                            <label><input type="checkbox" name="disable_ssl_verify" value="1" @if($connection->disable_ssl_verify) checked @endif> **Disable SSL Verification** (Use caution, insecure)</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Update Connection</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- 2. Credential Modal (For updateConnectionCredential logic) --}}
    <div class="modal fade" id="credentialModal{{ $connection->id }}" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form action="{{ route('device.rest-api.connections.credentials.update', [$device, $connection]) }}" method="POST">
                    @csrf
                    <div class="modal-header"><h5 class="modal-title">Apply Credentials to {{ $connection->name }}</h5></div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="credential_id">Select Credential</label>
                            <select name="credential_id" class="form-control">
                                <option value="">None Applied</option>
                                @foreach($credentials as $credential)
                                    <option value="{{ $credential->id }}" @if($connection->credential_id == $credential->id) selected @endif>
                                        {{ $credential->name }} ({{ $credential->authenticationType->name ?? 'Custom' }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        {{-- In a full implementation, detailed credential params editing would appear here --}}
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Save Credentials</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- 3. Endpoint Add Modal (For custom endpoints) --}}
    <div class="modal fade" id="endpointAddModal{{ $connection->id }}" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                {{-- Form submission to updateConnection to add the endpoint --}}
                <form action="{{ route('device.rest-api.connections.update', [$device, $connection]) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="action_type" value="add_endpoint">
                    <div class="modal-header"><h5 class="modal-title">Add Endpoint to {{ $connection->name }}</h5></div>
                    <div class="modal-body">
                        {{-- Endpoint Fields (Name, Method, Path, Map) --}}
                        <div class="form-group"><label>Name</label><input type="text" name="endpoint_name" class="form-control" required></div>
                        <div class="form-group"><label>Method</label><select name="endpoint_method" class="form-control"><option>GET</option><option>POST</option></select></div>
                        <div class="form-group"><label>Path</label><input type="text" name="endpoint_path" class="form-control" required></div>
                        <div class="form-group"><label>Metric Map (JSON)</label><textarea name="endpoint_metric_map_json" class="form-control" rows="5" placeholder='{"metric_name": "json.path"}' required></textarea></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-success">Add Endpoint</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- 4. Endpoint Edit Modals (Goal 4) --}}
    @foreach($connection->endpoints as $endpoint)
    <div class="modal fade" id="endpointEditModal{{ $endpoint->id }}" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                {{-- This form points to the new updateEndpoint route --}}
                <form action="{{ route('device.rest-api.update-endpoint', [$device, $endpoint]) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="modal-header"><h5 class="modal-title">Edit Endpoint: {{ $endpoint->name }}</h5></div>
                    <div class="modal-body">
                        <div class="form-group"><label>Name</label><input type="text" name="name" class="form-control" value="{{ $endpoint->name }}" required></div>
                        <div class="form-group"><label>Method</label>
                            <select name="method" class="form-control">
                                @foreach(['GET', 'POST', 'PUT', 'DELETE'] as $method)
                                    <option value="{{ $method }}" @if($endpoint->method == $method) selected @endif>{{ $method }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group"><label>Path</label><input type="text" name="path" class="form-control" value="{{ $endpoint->path }}" required></div>
                        <div class="form-group"><label>Metric Map (JSON)</label>
                            <textarea name="metric_map_json" class="form-control" rows="5" required>{{ json_encode($endpoint->metric_map, JSON_PRETTY_PRINT) }}</textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Update Endpoint</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endforeach
@endforeach