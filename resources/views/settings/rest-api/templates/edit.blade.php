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
                    <div class="card-header">
                        <h3 class="card-title">Edit Template: {{ $template->name }}</h3>
                        <div class="card-tools">
                            <a href="{{ route('settings.rest-api.templates.index') }}" class="btn btn-default btn-sm">
                                <i class="fas fa-arrow-left"></i> Back to Templates
                            </a>
                        </div>
                    </div>

                    <form action="{{ route('settings.rest-api.templates.update', ['template' => $template->id]) }}" method="POST">
                        @csrf
                        @method('PUT')
                        <div class="card-body">
                            <h5 class="mb-3 text-info"><i class="fas fa-info-circle"></i> Basic Template Information</h5>
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="name">Template Name <span class="text-danger">*</span></label>
                                        <input type="text" name="name" id="name" class="form-control"
                                               value="{{ old('name', $template->name) }}" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="vendor">Vendor</label>
                                        <input type="text" name="vendor" id="vendor" class="form-control"
                                               value="{{ old('vendor', $template->vendor) }}">
                                    </div>
                                </div>
                            </div>

                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="resource_type">Primary Resource Type</label>
                                        <select name="resource_type" id="resource_type" class="form-control">
                                            <option value="">-- None (Generic) --</option>
                                            <option value="device" {{ old('resource_type', $template->resource_type) === 'device' ? 'selected' : '' }}>Device</option>
                                            <option value="port" {{ old('resource_type', $template->resource_type) === 'port' ? 'selected' : '' }}>Port/Interface</option>
                                            <option value="storage" {{ old('resource_type', $template->resource_type) === 'storage' ? 'selected' : '' }}>Storage/Volume</option>
                                            <option value="sensor" {{ old('resource_type', $template->resource_type) === 'sensor' ? 'selected' : '' }}>Sensor/Health</option>
                                            <option value="processor" {{ old('resource_type', $template->resource_type) === 'processor' ? 'selected' : '' }}>Processor</option>
                                            <option value="mempool" {{ old('resource_type', $template->resource_type) === 'mempool' ? 'selected' : '' }}>Memory Pool</option>
                                            <option value="alert" {{ old('resource_type', $template->resource_type) === 'alert' ? 'selected' : '' }}>Alert/Event</option>
                                            <option value="custom" {{ old('resource_type', $template->resource_type) === 'custom' ? 'selected' : '' }}>Custom/Other</option>
                                        </select>
                                        <small class="form-text text-muted">Primary focus of this template</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="description">Description</label>
                                        <textarea name="description" id="description" class="form-control" rows="3">{{ old('description', $template->description) }}</textarea>
                                    </div>
                                </div>
                            </div>

                            <hr class="mb-4" style="border-top: 2px solid #dee2e6;">
                            <h5 class="mb-3"><i class="fas fa-tools"></i> Configuration Modules</h5>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <div class="card bg-light action-card h-100">
                                        <div class="card-body text-center">
                                            <i class="fas fa-plug fa-3x text-info mb-3"></i>
                                            <h5 class="card-title">Connection Settings</h5>
                                            <p class="card-text text-muted"><small>Base URL, Credentials, Login Path, and SSL.</small></p>
                                            <button type="button" class="btn btn-info btn-block mt-3" data-toggle="modal" data-target="#connectionModal">
                                                <i class="fas fa-edit"></i> Configure Connection
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="card bg-light action-card h-100">
                                        <div class="card-body text-center">
                                            <i class="fas fa-list fa-3x text-primary mb-3"></i>
                                            <h5 class="card-title">Endpoint Management</h5>
                                            <p class="card-text text-muted"><small>Paths, Methods, Metric Mapping, and Intervals.</small></p>
                                            <button type="button" class="btn btn-primary btn-block mt-3" data-toggle="modal" data-target="#endpointsModal">
                                                <i class="fas fa-tasks"></i> Manage Endpoints
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="card bg-light action-card h-100">
                                        <div class="card-body text-center">
                                            <i class="fas fa-eye fa-3x text-success mb-3"></i>
                                            <h5 class="card-title">Test & Preview</h5>
                                            <p class="card-text text-muted"><small>Verify API calls against a device before saving.</small></p>
                                            <button type="button" class="btn btn-success btn-block mt-3" data-toggle="modal" data-target="#previewModal">
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

{{-- Connection Modal --}}
<div class="modal fade" id="connectionModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form action="{{ route('settings.rest-api.templates.update', ['template' => $template->id]) }}" method="POST">
                @csrf
                @method('PUT')
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-plug"></i> Configure API Connection</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
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

{{-- Endpoints Modal --}}
<div class="modal fade" id="endpointsModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div x-data="endpointManager()" x-init="init()">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-tasks"></i> Manage Endpoints</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        {{-- Left Pane --}}
                        <div class="col-md-3 border-right">
                            <h6 class="mb-3 text-primary"><i class="fas fa-list-ul"></i> Existing Endpoints</h6>
                            @php
                                $template_data_array = is_array($template->template_data) ? $template->template_data : (json_decode($template->template_data, true) ?? []);
                                $connections = $template_data_array['connections'] ?? [];
                                $cIndex = 0;
                                $connection = $connections[$cIndex] ?? [];
                            @endphp

                            @if(!empty($connection))
                                <div class="alert alert-info py-2">
                                    Connection: **{{ $connection['name'] ?? 'Unnamed Connection' }}**
                                </div>
                                <div class="list-group mb-4" style="max-height:600px; overflow-y:auto;">
                                    @foreach ($connection['endpoints'] ?? [] as $eIndex => $endpoint)
                                        <a href="#" class="list-group-item list-group-item-action"
                                           :class="{ 'active': selectedEndpointIndex === {{ $eIndex }} }"
                                           @click.prevent="selectEndpoint({{ $eIndex }})">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1">
                                                    <span class="badge badge-secondary mr-1">{{ strtoupper($endpoint['method'] ?? 'GET') }}</span>
                                                    {{ $endpoint['name'] ?? 'Unnamed Endpoint' }}
                                                </h6>
                                                <small class="text-{{ ($endpoint['enabled'] ?? true) ? 'success' : 'danger' }}">
                                                    {{ ($endpoint['enabled'] ?? true) ? 'Enabled' : 'Disabled' }}
                                                </small>
                                            </div>
                                            <small>{{ $endpoint['path'] ?? 'No Path' }}</small>
                                        </a>
                                    @endforeach
                                </div>
                            @else
                                <div class="text-muted text-center py-3">No endpoints defined.</div>
                            @endif
                            <button type="button" class="btn btn-success btn-block mt-3" @click="addNewEndpoint()">
                                <i class="fas fa-plus-circle"></i> Add New Endpoint
                            </button>
                        </div>

                        {{-- Right Pane --}}
                        <div class="col-md-9" id="endpoint-detail-container">
                            <template x-if="selectedEndpointIndex !== null">
                                <div>
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
                                            <option value="GET">GET</option>
                                            <option value="POST">POST</option>
                                            <option value="PUT">PUT</option>
                                            <option value="DELETE">DELETE</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Metric Mapping (JSON)</label>
                                        <textarea class="form-control" rows="10" x-model="selectedEndpoint.metric_map_json"></textarea>
                                    </div>
                                    <div class="form-group text-right">
                                        <button type="button" class="btn btn-primary" @click="saveEndpointChanges()">
                                            <i class="fas fa-save"></i> Save Endpoint
                                        </button>
                                    </div>
                                </div>
                            </template>
                            <template x-if="selectedEndpointIndex === null">
                                <p class="text-muted">Select an endpoint from the left to edit its details.</p>
                            </template>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Preview Modal --}}
<div class="modal fade" id="previewModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-eye"></i> Test Template Configuration</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                @include('settings.rest-api.templates.partials.preview', ['template' => $template])
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function templateEditor() {
    return {
        templateData: @json($template->template_data),
        init() {
            // any initialization logic for templateEditor
        }
    }
}

function endpointManager() {
    // Keep existing helpers exactly as-is
    const escapeHtml = str => str ? str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;') : '';
    const hydrateForm = (html, data, index) => {
        const cIndex = 0;
        const safeIndex = index.toString().replace(/'/g, '\\\'');
        html = html.replace(/__ACTIVE_INDEX__/g, safeIndex);
        const formKeys = ['name','path','method','resource_type','poll_interval','description','response_path'];
        formKeys.forEach(key=>{
            let value = data[key]!==undefined?String(data[key]):(key==='poll_interval'?'300':'');
            let escapedValue = escapeHtml(value);
            html = html.replace(
                new RegExp(`name="template_data\\[connections\\]\\[${cIndex}\\]\\[endpoints\\]\\[${safeIndex}\\]\\[${key}\\]"\\s*value=".*?"`),
                `name="template_data[connections][${cIndex}][endpoints][${safeIndex}][${key}]" value="${escapedValue}"`
            );
            if(key==='description'){
                html = html.replace(
                    new RegExp(`name="template_data\\[connections\\]\\[${cIndex}\\]\\[endpoints\\]\\[${safeIndex}\\]\\[${key}\\]">.*?<\\/textarea>`, 's'),
                    `name="template_data[connections][${cIndex}][endpoints][${safeIndex}][${key}]">${escapedValue}</textarea>`
                );
            }
        });
        return html;
    }

    return {
        endpoints: @json($connection['endpoints'] ?? []),
        selectedEndpointIndex: null,
        selectedEndpoint: {},
        init() {
            // Initialize metric_map_json for each endpoint
            this.endpoints.forEach((ep, idx) => {
                this.endpoints[idx].metric_map_json =
                    typeof ep.metric_map === 'string'
                        ? ep.metric_map
                        : JSON.stringify(ep.metric_map ?? {}, null, 4);
            });
        },
        selectEndpoint(index) {
            this.selectedEndpointIndex = index;
            this.selectedEndpoint = {...this.endpoints[index]};
        },
        addNewEndpoint() {
            const newEp = { name:'New Endpoint', path:'', method:'GET', metric_map:{}, metric_map_json:'{}' };
            this.endpoints.push(newEp);
            this.selectedEndpointIndex = this.endpoints.length-1;
            this.selectedEndpoint = {...newEp};
        },
        async saveEndpointChanges() {
            if (this.selectedEndpointIndex === null) return;

            // Validate JSON
            try {
                this.selectedEndpoint.metric_map = JSON.parse(this.selectedEndpoint.metric_map_json);
            } catch(e){
                alert('Metric Mapping JSON is invalid. Fix before saving.');
                return;
            }

            // Update local Alpine array
            this.endpoints[this.selectedEndpointIndex] = {...this.selectedEndpoint};

            console.log('Endpoint saved locally:', this.selectedEndpoint);

            // AJAX save
            try {
                const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                const response = await fetch("{{ route('device.rest-api.connections.update', ['device' => $template->device_id ?? 0, 'connection' => $connection['id'] ?? 0]) }}", {
								    method: "PUT",
								    headers: {
								        "Content-Type": "application/json",
								        "X-CSRF-TOKEN": token
								    },
								    body: JSON.stringify({
								        action_type: 'edit_endpoint',
								        index: this.selectedEndpointIndex,
								        endpoint: this.selectedEndpoint
								    })
								});

                const data = await response.json();
                if(data.success){
                    alert('Endpoint saved successfully.');
                } else {
                    alert('Error saving endpoint: ' + (data.message || 'Unknown error'));
                }
            } catch (err) {
                console.error(err);
                alert('AJAX request failed. Check console.');
            }
        }
    };
}
</script>
