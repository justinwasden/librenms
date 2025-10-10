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
        height: 100vh;
        overflow: hidden;
    }
    #endpointsModal .modal-body {
        height: calc(100vh - 120px);
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
            // IMPORTANT: We always parse from template_data JSON, NOT from device endpoints
            // This is the template blueprint, not the device-specific instances
            $endpoints = [];

            // Parse endpoints from template_data JSON
            $templateData = is_array($template->template_data)
                ? $template->template_data
                : json_decode($template->template_data ?? '{}', true);

            $connections = $templateData['connections'] ?? [];

            $connectionIndex = 0;
            foreach ($connections as $conn) {
                if (isset($conn['endpoints']) && is_array($conn['endpoints'])) {

                    foreach ($conn['endpoints'] as $idx => $ep) {
                        // Add metadata to track which connection this belongs to
                        $ep['_connection_index'] = $connectionIndex;
                        $ep['_endpoint_index'] = $idx;
                        $ep['_is_template'] = true; // Flag to indicate this is a template endpoint
                        $endpoints[] = $ep;
                    }
                }
                $connectionIndex++;
            }

        @endphp
        <div x-data="endpointManager()" x-init="loadEndpoints(@js($endpoints))">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-tasks"></i> Manage Endpoints</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>

                <div class="modal-body">
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

                                {{-- Fallback if template doesn't render --}}
                                <div x-show="endpoints.length === 0" class="text-muted p-3">
                                    No endpoints found
                                </div>
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
                                        <input type="text" class="form-control" x-model="selectedEndpoint.name"
                                               @input="checkForChanges()" required>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="form-group">
                                                <label>Path <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" x-model="selectedEndpoint.path"
                                                       @input="checkForChanges()" placeholder="/api/2.30/arrays" required>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>HTTP Method</label>
                                                <select class="form-control" x-model="selectedEndpoint.method"
                                                        @change="checkForChanges()">
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
                                        <select class="form-control" x-model="selectedEndpoint.resource_type"
                                                @change="checkForChanges()">
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
                                                  @input="checkForChanges()" placeholder='{\n  "field_name": "json.path.to.field"\n}'></textarea>
                                        <small class="form-text text-muted">Leave empty to let the system auto-learn field mappings</small>
                                    </div>

                                    <div class="text-right mt-4">
                                        <button type="button" class="btn btn-danger mr-2"
                                                @click="deleteEndpoint()"
                                                x-show="selectedEndpoint._endpoint_index !== undefined">
                                            <i class="fas fa-trash"></i> Delete Endpoint
                                        </button>
                                        <button type="button" class="btn btn-primary"
                                                @click="saveEndpointChanges()"
                                                :disabled="!isDirty"
                                                data-save-endpoint>
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

function endpointManager() {
    return {
        endpoints: [],
        selectedEndpointIndex: null,
        selectedEndpoint: {},
        originalEndpoint: {},
        isDirty: false,

        loadEndpoints(endpointsData) {
            console.log('=== Endpoint Manager Init ===');
            console.log('Raw endpoints data:', endpointsData);

            // Set endpoints from passed data
            this.endpoints = Array.isArray(endpointsData) ? endpointsData : [];

            console.log('Endpoints length:', this.endpoints.length);
            console.log('Endpoints is array?', Array.isArray(this.endpoints));

            // Ensure endpoints is an array
            if (!Array.isArray(this.endpoints)) {
                console.error('Endpoints is not an array!', typeof this.endpoints);
                this.endpoints = [];
                return;
            }

            // Process metric_map for each endpoint
            this.endpoints.forEach((ep, idx) => {
                console.log(`Processing endpoint ${idx}:`, ep.name);
                if (!this.endpoints[idx].metric_map_json) {
                    this.endpoints[idx].metric_map_json =
                        typeof ep.metric_map === 'string'
                            ? ep.metric_map
                            : JSON.stringify(ep.metric_map ?? null, null, 4) || '';
                }
            });

            console.log('Processed endpoints:', this.endpoints.length);

            // Auto-select first endpoint if available
            if (this.endpoints.length > 0) {
                console.log('Auto-selecting first endpoint');
                this.selectEndpoint(0);
            } else {
                console.log('No endpoints to select');
            }

            console.log('Init complete. selectedEndpointIndex:', this.selectedEndpointIndex);
        },

        selectEndpoint(index) {
            this.selectedEndpointIndex = index;
            this.selectedEndpoint = JSON.parse(JSON.stringify(this.endpoints[index]));
            this.originalEndpoint = JSON.parse(JSON.stringify(this.endpoints[index]));
            this.isDirty = false;
        },

        // Check if current endpoint has changes
        checkForChanges() {
            if (this.selectedEndpointIndex === null) {
                this.isDirty = false;
                return;
            }

            // For new endpoints (no _endpoint_index), always mark as dirty
            if (this.selectedEndpoint._endpoint_index === undefined) {
                this.isDirty = true;
                return;
            }

            // Compare current with original
            const current = JSON.stringify({
                name: this.selectedEndpoint.name,
                path: this.selectedEndpoint.path,
                method: this.selectedEndpoint.method,
                resource_type: this.selectedEndpoint.resource_type,
                metric_map_json: this.selectedEndpoint.metric_map_json
            });

            const original = JSON.stringify({
                name: this.originalEndpoint.name,
                path: this.originalEndpoint.path,
                method: this.originalEndpoint.method,
                resource_type: this.originalEndpoint.resource_type,
                metric_map_json: this.originalEndpoint.metric_map_json
            });

            this.isDirty = current !== original;
        },

        addNewEndpoint() {
            const newEp = {
                name: 'New Endpoint',
                path: '/api/2.30/',
                method: 'GET',
                resource_type: '',
                metric_map: null,
                metric_map_json: '',
                _connection_index: 0, // Default to first connection
                _is_template: true
                // Note: no _endpoint_index means it's new
            };
            this.endpoints.push(newEp);
            this.selectEndpoint(this.endpoints.length - 1);
            this.isDirty = true; // New endpoints are always dirty
        },

        async saveEndpointChanges() {
            if (this.selectedEndpointIndex === null || !this.isDirty) return;

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

            // Update the endpoint in the array
            this.endpoints[this.selectedEndpointIndex] = { ...this.selectedEndpoint };

            // Determine if this is a new endpoint or an update
            const isNewEndpoint = this.selectedEndpoint._endpoint_index === undefined;

            // Save to template_data JSON via API
            try {
                const templateId = {{ $template->id }};
                const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

                const url = isNewEndpoint
                    ? `/settings/rest-api/templates/${templateId}/add-endpoint`
                    : `/settings/rest-api/templates/${templateId}/update-endpoint`;

                const payload = isNewEndpoint ? {
                    connection_index: this.selectedEndpoint._connection_index || 0,
                    endpoint_data: {
                        name: this.selectedEndpoint.name,
                        path: this.selectedEndpoint.path,
                        method: this.selectedEndpoint.method,
                        resource_type: this.selectedEndpoint.resource_type || '',
                        metric_map: this.selectedEndpoint.metric_map
                    }
                } : {
                    connection_index: this.selectedEndpoint._connection_index,
                    endpoint_index: this.selectedEndpoint._endpoint_index,
                    endpoint_data: {
                        name: this.selectedEndpoint.name,
                        path: this.selectedEndpoint.path,
                        method: this.selectedEndpoint.method,
                        resource_type: this.selectedEndpoint.resource_type || '',
                        metric_map: this.selectedEndpoint.metric_map
                    }
                };

                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify(payload)
                });

                const data = await res.json();
                if (data.success) {
                    // Update the endpoint with new metadata
                    if (data.endpoint) {
                        this.endpoints[this.selectedEndpointIndex] = {
                            ...this.selectedEndpoint,
                            _connection_index: data.endpoint._connection_index,
                            _endpoint_index: data.endpoint._endpoint_index,
                            _is_template: true
                        };
                        this.selectedEndpoint = JSON.parse(JSON.stringify(this.endpoints[this.selectedEndpointIndex]));
                        this.originalEndpoint = JSON.parse(JSON.stringify(this.endpoints[this.selectedEndpointIndex]));
                    }

                    // Mark as clean and show success
                    this.isDirty = false;

                    // Show success message without alert
                    const saveBtn = document.querySelector('[data-save-endpoint]');
                    if (saveBtn) {
                        const originalText = saveBtn.innerHTML;
                        saveBtn.innerHTML = '<i class="fas fa-check"></i> Saved!';
                        saveBtn.classList.remove('btn-primary');
                        saveBtn.classList.add('btn-success');
                        setTimeout(() => {
                            saveBtn.innerHTML = originalText;
                            saveBtn.classList.remove('btn-success');
                            saveBtn.classList.add('btn-primary');
                        }, 2000);
                    }
                } else {
                    alert('Error saving endpoint: ' + (data.message || 'Unknown error'));
                }
            } catch (err) {
                console.error(err);
                alert('Failed to save endpoint. Check console for details.');
            }
        },

        async deleteEndpoint() {
            if (!confirm('Are you sure you want to delete this endpoint from the template?')) return;

            try {
                const templateId = {{ $template->id }};
                const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

                const res = await fetch(`/settings/rest-api/templates/${templateId}/delete-endpoint`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({
                        connection_index: this.selectedEndpoint._connection_index,
                        endpoint_index: this.selectedEndpoint._endpoint_index
                    })
                });

                const data = await res.json();
                if (data.success) {
                    this.endpoints.splice(this.selectedEndpointIndex, 1);
                    this.selectedEndpointIndex = null;
                    this.selectedEndpoint = {};
                    alert('Endpoint deleted from template successfully!');
                } else {
                    alert('Failed to delete endpoint: ' + (data.message || 'Unknown error'));
                }
            } catch (err) {
                console.error(err);
                alert('Failed to delete endpoint. Check console for details.');
            }
        },
    }
}
</script>
@endsection
