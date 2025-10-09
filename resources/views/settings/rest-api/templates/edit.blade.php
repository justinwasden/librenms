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
            $templateData = is_array($template->template_data)
                ? $template->template_data
                : json_decode($template->template_data ?? '{}', true);
            $connections = $templateData['connections'] ?? [];
            $connection = $connections[0] ?? [];
            $endpoints = $connection['endpoints'] ?? [];
        @endphp
        <div x-data="endpointManager({ endpoints: @json($endpoints) })" x-init="init()">
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
                                        <label>Endpoint Name</label>
                                        <input type="text" class="form-control" x-model="selectedEndpoint.name">
                                    </div>
                                    <div class="form-group">
                                        <label>Path</label>
                                        <input type="text" class="form-control" x-model="selectedEndpoint.path">
                                    </div>
                                    <div class="form-group">
                                        <label>HTTP Method</label>
                                        <select class="form-control" x-model="selectedEndpoint.method">
                                            <option>GET</option>
                                            <option>POST</option>
                                            <option>PUT</option>
                                            <option>DELETE</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Metric Mapping (JSON)</label>
                                        <textarea class="form-control" rows="10" x-model="selectedEndpoint.metric_map_json"></textarea>
                                    </div>

                                    <div class="text-right">
                                        <button type="button" class="btn btn-primary" :disabled="!isDirty"
                                                @click="saveEndpointChanges()">
                                            <i class="fas fa-save"></i> Save Endpoint
                                        </button>
                                    </div>
                                </div>
                            </template>

                            <template x-if="selectedEndpointIndex === null">
                                <p class="text-muted text-center">Select an endpoint to edit or create a new one.</p>
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
            this.endpoints.forEach((ep, idx) => {
                this.endpoints[idx].metric_map_json =
                    typeof ep.metric_map === 'string'
                        ? ep.metric_map
                        : JSON.stringify(ep.metric_map ?? {}, null, 4);
            });
        },

        selectEndpoint(index) {
            this.selectedEndpointIndex = index;
            this.selectedEndpoint = JSON.parse(JSON.stringify(this.endpoints[index]));
            this.originalEndpoint = JSON.parse(JSON.stringify(this.endpoints[index]));
            this.isDirty = false;
        },

        addNewEndpoint() {
            const newEp = { name: 'New Endpoint', path: '', method: 'GET', metric_map: {}, metric_map_json: '{}' };
            this.endpoints.push(newEp);
            this.selectEndpoint(this.endpoints.length - 1);
            this.isDirty = true;
        },

        async saveEndpointChanges() {
            if (this.selectedEndpointIndex === null) return;

            try {
                this.selectedEndpoint.metric_map = JSON.parse(this.selectedEndpoint.metric_map_json);
            } catch {
                alert('Invalid JSON in Metric Mapping');
                return;
            }

            this.endpoints[this.selectedEndpointIndex] = { ...this.selectedEndpoint };
            this.isDirty = false;

            try {
                const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                const res = await fetch("{{ route('device.rest-api.connections.update', ['device' => $template->device_id ?? 0, 'connection' => $connection['id'] ?? 0]) }}", {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({
                        action_type: 'edit_endpoint',
                        index: this.selectedEndpointIndex,
                        endpoint: this.selectedEndpoint
                    })
                });

                const data = await res.json();
                if (data.success) {
                    alert('Endpoint saved successfully.');
                } else {
                    alert('Error saving endpoint: ' + (data.message || 'Unknown error.'));
                }
            } catch (err) {
                console.error(err);
                alert('AJAX request failed.');
            }
        },
    }
}
</script>
@endsection
