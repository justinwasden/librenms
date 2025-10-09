{{-- resources/views/settings/rest-api/templates/edit.blade.php --}}
@extends('layouts.librenmsv1')

@section('title', 'Edit REST API Template')

@push('styles')
<style>
    [x-cloak] { display: none !important; }

    .action-card {
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .action-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,.1);
    }

    #endpointsModal .modal-dialog {
        max-width: 75vw !important;
        width: 98vw !important;
        margin: 1vh auto;
    }
    #endpointsModal .modal-content {
        height: 90vh;
        overflow: hidden;
    }
    #endpointsModal .modal-body {
        height: calc(90vh - 130px);
        overflow-y: auto;
    }
    #endpointsModal .col-md-3 {
        flex: 0 0 25%;
        max-width: 25%;
    }
    #endpointsModal .col-md-9 {
        flex: 0 0 75%;
        max-width: 75%;
    }
    .endpoint-dirty {
        border-left: 4px solid #28a745;
        padding-left: 10px;
        transition: border 0.3s;
    }
</style>
@endpush

@push('scripts')
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
@endpush

@section('content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-md-10 col-lg-9 col-xl-8">
            <div x-data="templateEditor()" x-init="init()">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title">Edit Template: {{ $template->name }}</h3>
                        <a href="{{ route('settings.rest-api.templates.index') }}" class="btn btn-default btn-sm">
                            <i class="fas fa-arrow-left"></i> Back to Templates
                        </a>
                    </div>

                    <form action="{{ route('settings.rest-api.templates.update', $template->id) }}" method="POST">
                        @csrf
                        @method('PUT')
                        <div class="card-body">
                            {{-- BASIC INFO --}}
                            <h5 class="mb-3 text-info"><i class="fas fa-info-circle"></i> Basic Template Information</h5>
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <label for="name">Template Name <span class="text-danger">*</span></label>
                                    <input type="text" name="name" id="name" class="form-control" required
                                           value="{{ old('name', $template->name) }}">
                                </div>
                                <div class="col-md-6">
                                    <label for="vendor">Vendor</label>
                                    <input type="text" name="vendor" id="vendor" class="form-control"
                                           value="{{ old('vendor', $template->vendor) }}">
                                </div>
                            </div>

                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <label for="resource_type">Primary Resource Type</label>
                                    <select name="resource_type" id="resource_type" class="form-control">
                                        <option value="">-- None (Generic) --</option>
                                        <optgroup label="Standard Types">
                                            @foreach(['device','port','sensor','processor','mempool','alert','custom'] as $type)
                                                <option value="{{ $type }}" {{ old('resource_type', $template->resource_type) === $type ? 'selected' : '' }}>
                                                    {{ ucfirst($type) }}
                                                </option>
                                            @endforeach
                                        </optgroup>
                                        <optgroup label="Storage Array Types">
                                            @foreach(['array','controller','host','volume','storage'] as $type)
                                                <option value="{{ $type }}" {{ old('resource_type', $template->resource_type) === $type ? 'selected' : '' }}>
                                                    {{ ucfirst($type) }}
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    </select>
                                    <small class="form-text text-muted">Defines the main data category this template handles</small>
                                </div>
                                <div class="col-md-6">
                                    <label for="description">Description</label>
                                    <textarea name="description" id="description" class="form-control" rows="3">{{ old('description', $template->description) }}</textarea>
                                </div>
                            </div>

                            <hr class="mb-4">
                            <h5 class="mb-3"><i class="fas fa-tools"></i> Configuration Modules</h5>

                            <div class="row">
                                {{-- CONNECTION --}}
                                <div class="col-md-4 mb-3">
                                    <div class="card bg-light action-card h-100">
                                        <div class="card-body text-center">
                                            <i class="fas fa-plug fa-3x text-info mb-3"></i>
                                            <h5 class="card-title">Connection Settings</h5>
                                            <p class="text-muted"><small>Base URL, credentials, and authentication paths.</small></p>
                                            <button type="button" class="btn btn-info btn-block" data-toggle="modal" data-target="#connectionModal">
                                                <i class="fas fa-edit"></i> Configure Connection
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                {{-- ENDPOINTS --}}
                                <div class="col-md-4 mb-3">
                                    <div class="card bg-light action-card h-100">
                                        <div class="card-body text-center">
                                            <i class="fas fa-list fa-3x text-primary mb-3"></i>
                                            <h5 class="card-title">Endpoint Management</h5>
                                            <p class="text-muted"><small>Paths, methods, and metric mappings.</small></p>
                                            <button type="button" class="btn btn-primary btn-block" data-toggle="modal" data-target="#endpointsModal">
                                                <i class="fas fa-tasks"></i> Manage Endpoints
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                {{-- PREVIEW --}}
                                <div class="col-md-4 mb-3">
                                    <div class="card bg-light action-card h-100">
                                        <div class="card-body text-center">
                                            <i class="fas fa-eye fa-3x text-success mb-3"></i>
                                            <h5 class="card-title">Test & Preview</h5>
                                            <p class="text-muted"><small>Run test calls before finalizing the template.</small></p>
                                            <button type="button" class="btn btn-success btn-block" data-toggle="modal" data-target="#previewModal">
                                                <i class="fas fa-play"></i> Run Test
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card-footer text-right">
                            <a href="{{ route('settings.rest-api.templates.index') }}" class="btn btn-default">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Basic Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- CONNECTION MODAL --}}
<div class="modal fade" id="connectionModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="{{ route('settings.rest-api.templates.update', $template->id) }}" method="POST">
                @csrf @method('PUT')
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-plug"></i> Configure API Connection</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    @include('settings.rest-api.templates.partials.connection', ['template' => $template])
                    <input type="hidden" name="action_type" value="update_connection_only">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-info"><i class="fas fa-save"></i> Save Connection</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ENDPOINTS MODAL --}}
<div class="modal fade" id="endpointsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-xl" role="document">
        @php
            // Debug: Let's see what we have in the template
            $endpoints = [];
            $debugInfo = [];
            
            $debugInfo[] = 'Template ID: ' . ($template->id ?? 'none');
            $debugInfo[] = 'Template Name: ' . ($template->name ?? 'none');
            $debugInfo[] = 'Has device_id: ' . (isset($template->device_id) ? 'yes (' . $template->device_id . ')' : 'no');
            
            // Strategy 1: If template is associated with a device, get endpoints from that device
            if (isset($template->device_id) && $template->device_id) {
                $debugInfo[] = 'Using Strategy 1: device_id = ' . $template->device_id;
                $connections = \App\Models\RestApiConnection::where('device_id', $template->device_id)->get();
                $debugInfo[] = 'Found ' . $connections->count() . ' connections';
                
                foreach ($connections as $conn) {
                    $connEndpoints = \App\Models\RestApiEndpoint::where('connection_id', $conn->id)->get();
                    $debugInfo[] = 'Connection ' . $conn->id . ' has ' . $connEndpoints->count() . ' endpoints';
                    
                    foreach ($connEndpoints as $ep) {
                        $endpoints[] = [
                            'id' => $ep->id,
                            'name' => $ep->name,
                            'path' => $ep->path,
                            'method' => $ep->method ?? 'GET',
                            'resource_type' => $ep->resource_type,
                            'metric_map' => $ep->metric_map,
                            'connection_id' => $ep->connection_id
                        ];
                    }
                }
            }
            
            // Strategy 2: Try to find connections for PureStorage devices
            if (empty($endpoints)) {
                $debugInfo[] = 'Using Strategy 2: Looking for PureStorage devices';
                $pureDevices = \App\Models\Device::where('os', 'purestorage')->pluck('device_id');
                $debugInfo[] = 'Found ' . $pureDevices->count() . ' PureStorage devices: ' . $pureDevices->implode(', ');
                
                if ($pureDevices->count() > 0) {
                    $connections = \App\Models\RestApiConnection::whereIn('device_id', $pureDevices)->get();
                    $debugInfo[] = 'Found ' . $connections->count() . ' connections for PureStorage devices';
                    
                    foreach ($connections as $conn) {
                        $connEndpoints = \App\Models\RestApiEndpoint::where('connection_id', $conn->id)->get();
                        $debugInfo[] = 'Connection ' . $conn->id . ' (device ' . $conn->device_id . ') has ' . $connEndpoints->count() . ' endpoints';
                        
                        foreach ($connEndpoints as $ep) {
                            $endpoints[] = [
                                'id' => $ep->id,
                                'name' => $ep->name,
                                'path' => $ep->path,
                                'method' => $ep->method ?? 'GET',
                                'resource_type' => $ep->resource_type,
                                'metric_map' => $ep->metric_map,
                                'connection_id' => $ep->connection_id
                            ];
                        }
                    }
                }
            }
            
            // Strategy 3: Parse from template_data as fallback
            if (empty($endpoints)) {
                $debugInfo[] = 'Using Strategy 3: Parsing template_data';
                $templateData = is_array($template->template_data)
                    ? $template->template_data
                    : json_decode($template->template_data ?? '{}', true);
                $connections = $templateData['connections'] ?? [];
                $debugInfo[] = 'Found ' . count($connections) . ' connections in template_data';
                
                foreach ($connections as $conn) {
                    if (isset($conn['endpoints'])) {
                        $debugInfo[] = 'Connection has ' . count($conn['endpoints']) . ' endpoints in template_data';
                        foreach ($conn['endpoints'] as $ep) {
                            $endpoints[] = $ep;
                        }
                    }
                }
            }
            
            $debugInfo[] = 'Total endpoints loaded: ' . count($endpoints);
        @endphp
        <div x-data="endpointManager({ endpoints: @json($endpoints) })" x-init="init()">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-tasks"></i> Manage Endpoints</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>

                <div class="modal-body">
                    {{-- Debug Info --}}
                    <div class="alert alert-info mb-3">
                        <strong>Debug Information:</strong>
                        <ul class="mb-0 small">
                            @foreach($debugInfo as $info)
                                <li>{{ $info }}</li>
                            @endforeach
                        </ul>
                    </div>
                    
                    <div class="row">
                        {{-- LEFT PANEL --}}
                        <div class="col-md-3 border-right">
                            <h6 class="mb-3 text-primary"><i class="fas fa-list-ul"></i> Existing Endpoints</h6>
                            <div class="list-group" style="max-height:600px; overflow-y:auto;">
                                <template x-for="(endpoint, index) in endpoints" :key="index">
                                    <a href="#" class="list-group-item list-group-item-action"
                                       :class="{ 'active': selectedEndpointIndex === index }"
                                       @click.prevent="selectEndpoint(index)">
                                        <span class="badge badge-secondary mr-2" x-text="endpoint.method || 'GET'"></span>
                                        <span x-text="endpoint.name || endpoint.path || 'Unnamed'"></span>
                                    </a>
                                </template>
                            </div>

                            <button type="button" class="btn btn-success btn-block mt-3"
                                    @click="addNewEndpoint()">
                                <i class="fas fa-plus-circle"></i> Add New Endpoint
                            </button>
                        </div>

                        {{-- RIGHT PANEL --}}
                        <div class="col-md-9">
                            <template x-if="selectedEndpointIndex !== null">
                                <div class="endpoint-dirty">
                                    <div class="form-group">
                                        <label>Endpoint Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" x-model="selectedEndpoint.name" required>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="form-group">
                                                <label>Path <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" x-model="selectedEndpoint.path" 
                                                       placeholder="/api/2.30/arrays" required>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>HTTP Method</label>
                                                <select class="form-control" x-model="selectedEndpoint.method">
                                                    <option>GET</option>
                                                    <option>POST</option>
                                                    <option>PUT</option>
                                                    <option>DELETE</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Resource Type</label>
                                        <select class="form-control" x-model="selectedEndpoint.resource_type">
                                            <option value="">-- Auto Detect --</option>
                                            <optgroup label="Standard Types">
                                                <option value="device">Device</option>
                                                <option value="port">Port</option>
                                                <option value="sensor">Sensor</option>
                                                <option value="processor">Processor</option>
                                                <option value="mempool">Memory Pool</option>
                                                <option value="alert">Alert</option>
                                                <option value="custom">Custom</option>
                                            </optgroup>
                                            <optgroup label="Storage Array Types">
                                                <option value="array">Array</option>
                                                <option value="controller">Controller</option>
                                                <option value="host">Host</option>
                                                <option value="volume">Volume</option>
                                                <option value="storage">Storage (Legacy)</option>
                                            </optgroup>
                                        </select>
                                        <small class="form-text text-muted">Determines which database table to store data in</small>
                                    </div>
                                    <div class="form-group">
                                        <label>Metric Mapping (JSON) <small class="text-muted">- Optional, leave empty for auto-learning</small></label>
                                        <textarea class="form-control font-monospace" rows="10" x-model="selectedEndpoint.metric_map_json"
                                                  placeholder='{\n  "field_name": "json.path.to.field"\n}'></textarea>
                                        <small class="form-text text-muted">Leave empty to let the system auto-learn field mappings</small>
                                    </div>

                                    <div class="text-right mt-4">
                                        <button type="button" class="btn btn-danger mr-2" 
                                                @click="deleteEndpoint()" 
                                                x-show="selectedEndpoint.id">
                                            <i class="fas fa-trash"></i> Delete Endpoint
                                        </button>
                                        <button type="button" class="btn btn-primary" 
                                                @click="saveEndpointChanges()">
                                            <i class="fas fa-save"></i> Save Endpoint
                                        </button>
                                    </div>
                                </div>
                            </template>

                            <template x-if="selectedEndpointIndex === null">
                                <div class="text-center text-muted py-5">
                                    <i class="fas fa-arrow-left fa-3x mb-3"></i>
                                    <p>Select an endpoint from the list or create a new one.</p>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- PREVIEW MODAL --}}
<div class="modal fade" id="previewModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-eye"></i> Test Template Configuration</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                @include('settings.rest-api.templates.partials.preview', ['template' => $template])
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function templateEditor() {
    return { init() { console.log('Template Editor Loaded'); } }
}

function endpointManager({ endpoints }) {
    return {
        endpoints: endpoints || [],
        selectedEndpointIndex: null,
        selectedEndpoint: {},
        originalEndpoint: {},
        isDirty: false,

        init() {
            console.log('Loaded', this.endpoints.length, 'endpoints');
            this.endpoints.forEach((ep, idx) => {
                this.endpoints[idx].metric_map_json =
                    typeof ep.metric_map === 'string'
                        ? ep.metric_map
                        : JSON.stringify(ep.metric_map ?? {}, null, 4);
            });
            // Auto-select first endpoint if available
            if (this.endpoints.length > 0) {
                this.selectEndpoint(0);
            }
        },

        selectEndpoint(index) {
            this.selectedEndpointIndex = index;
            this.selectedEndpoint = JSON.parse(JSON.stringify(this.endpoints[index]));
            this.originalEndpoint = JSON.parse(JSON.stringify(this.endpoints[index]));
            this.isDirty = false;
        },

        addNewEndpoint() {
            const newEp = { 
                name: 'New Endpoint', 
                path: '/api/2.30/', 
                method: 'GET', 
                resource_type: '',
                metric_map: null, 
                metric_map_json: '' 
            };
            this.endpoints.push(newEp);
            this.selectEndpoint(this.endpoints.length - 1);
            this.isDirty = true;
        },

        async saveEndpointChanges() {
            if (this.selectedEndpointIndex === null) return;

            // Validate required fields
            if (!this.selectedEndpoint.name || !this.selectedEndpoint.path) {
                alert('Name and Path are required fields');
                return;
            }

            // Parse metric_map JSON if provided
            if (this.selectedEndpoint.metric_map_json && this.selectedEndpoint.metric_map_json.trim()) {
                try {
                    this.selectedEndpoint.metric_map = JSON.parse(this.selectedEndpoint.metric_map_json);
                } catch (e) {
                    alert('Invalid JSON in Metric Mapping: ' + e.message);
                    return;
                }
            } else {
                this.selectedEndpoint.metric_map = null;
            }

            this.endpoints[this.selectedEndpointIndex] = { ...this.selectedEndpoint };
            this.isDirty = false;

            // If endpoint has an ID, update via API
            if (this.selectedEndpoint.id) {
                try {
                    const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                    const res = await fetch(`/rest-api/endpoints/${this.selectedEndpoint.id}`, {
                        method: 'PUT',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                        body: JSON.stringify({
                            name: this.selectedEndpoint.name,
                            path: this.selectedEndpoint.path,
                            method: this.selectedEndpoint.method,
                            resource_type: this.selectedEndpoint.resource_type,
                            metric_map: this.selectedEndpoint.metric_map
                        })
                    });

                    const data = await res.json();
                    if (data.success || res.ok) {
                        alert('Endpoint saved successfully!');
                        location.reload(); // Reload to show updated data
                    } else {
                        alert('Error saving endpoint: ' + (data.message || 'Unknown error'));
                    }
                } catch (err) {
                    console.error(err);
                    alert('Failed to save endpoint. Check console for details.');
                }
            } else {
                alert('New endpoint created in memory. Save the template to persist.');
            }
        },

        async deleteEndpoint() {
            if (!confirm('Are you sure you want to delete this endpoint?')) return;
            
            if (this.selectedEndpoint.id) {
                try {
                    const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                    const res = await fetch(`/rest-api/endpoints/${this.selectedEndpoint.id}`, {
                        method: 'DELETE',
                        headers: { 'X-CSRF-TOKEN': token }
                    });

                    if (res.ok) {
                        this.endpoints.splice(this.selectedEndpointIndex, 1);
                        this.selectedEndpointIndex = null;
                        this.selectedEndpoint = {};
                        alert('Endpoint deleted successfully!');
                    } else {
                        alert('Failed to delete endpoint');
                    }
                } catch (err) {
                    console.error(err);
                    alert('Failed to delete endpoint');
                }
            } else {
                // Just remove from array if not saved yet
                this.endpoints.splice(this.selectedEndpointIndex, 1);
                this.selectedEndpointIndex = null;
                this.selectedEndpoint = {};
            }
        },
    }
}
</script>
@endsection
