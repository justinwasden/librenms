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

    #endpointsModal .col-md-3 { flex: 0 0 25%; max-width: 25%; }
    #endpointsModal .col-md-9 { flex: 0 0 75%; max-width: 75%; }
    .endpoint-dirty { border-left: 4px solid #28a745; padding-left: 10px; transition: border 0.3s; }
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
                                        <small class="form-text text-muted">Primary focus of this template (e.g., set to 'storage' for PureStorage)</small>
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

{{-- ---------------------------------------------------------------- --}}
{{-- MODALS SECTION --}}
{{-- ---------------------------------------------------------------- --}}

{{-- 1. Connection Modal --}}
<div class="modal fade" id="connectionModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form action="{{ route('settings.rest-api.templates.update', ['template' => $template->id]) }}" method="POST">
                @csrf
                @method('PUT')
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-plug"></i> Configure API Connection</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span>&times;</span>
                    </button>
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

{{-- 2. Endpoints Modal --}}
<div class="modal fade" id="endpointsModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document" x-data="endpointManager()" x-init="init()">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-tasks"></i> Manage Endpoints</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span>&times;</span>
                </button>
            </div>

            <div class="modal-body d-flex p-0">
                {{-- LEFT SIDE: Endpoint List --}}
                <div class="col-md-3 border-right p-3">
                    <button class="btn btn-success btn-block mb-3" @click="startAddingNew">
                        <i class="fas fa-plus"></i> Add New Endpoint
                    </button>

                    <template x-for="(endpoint, index) in endpoints" :key="index">
                        <div class="list-group mb-2">
                            <a href="#" class="list-group-item list-group-item-action"
                               :class="{'active': index === activeEndpointIndex}"
                               @click.prevent="selectEndpoint(index)">
                                <span x-text="endpoint.name"></span>
                            </a>
                        </div>
                    </template>
                </div>

                {{-- RIGHT SIDE: Endpoint Details --}}
                <div class="col-md-9 p-3" :class="{ 'endpoint-dirty': isFormDirty }">
                    <template x-if="activeEndpointIndex !== null || isAddingNew">
                        <form id="endpoint-detail-form" @submit.prevent="saveEndpointChanges">
                            <h6 class="mb-3" x-text="isAddingNew ? 'New Endpoint Details' : 'Edit Endpoint: ' + activeEndpointData.name"></h6>

                            <div class="form-group">
                                <label for="endpoint_name">Endpoint Name</label>
                                <input type="text" x-model="activeEndpointData.name" class="form-control" required>
                            </div>

                            <div class="form-group">
                                <label for="endpoint_path">Path</label>
                                <input type="text" x-model="activeEndpointData.path" class="form-control">
                            </div>

                            <div class="form-group">
                                <label for="endpoint_method">Method</label>
                                <select x-model="activeEndpointData.method" class="form-control">
                                    <option>GET</option>
                                    <option>POST</option>
                                    <option>PUT</option>
                                    <option>DELETE</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="metric_map">Metric Map (JSON)</label>
                                <textarea x-model="activeEndpointData.metric_map_json" class="form-control" rows="6"></textarea>
                                <div class="text-danger mt-1" x-show="activeEndpointData.metric_map_error" x-text="activeEndpointData.metric_map_error"></div>
                            </div>

                            <div class="mt-3 text-right">
                                <button type="button" class="btn btn-secondary mr-2" @click="cancelEndpointChanges">Cancel</button>
                                <button type="submit" class="btn btn-success">Save Endpoint</button>
                            </div>
                        </form>
                    </template>

                    <template x-if="activeEndpointIndex === null && !isAddingNew">
                        <div class="alert alert-warning text-center mt-5">
                            <i class="fas fa-hand-point-left fa-2x"></i><br>
                            Select an endpoint from the list or click "Add New Endpoint."
                        </div>
                    </template>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

{{-- 3. Preview Modal (optional) --}}
<div class="modal fade" id="previewModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-play"></i> Run API Test</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Preview and test API responses here...</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

{{-- ---------------------------------------------------------------- --}}
{{-- AlpineJS DATA SCRIPTS --}}
{{-- ---------------------------------------------------------------- --}}
<script>
function templateEditor() {
    return {
        init() {
            console.log('Template editor initialized.');
        }
    };
}

function endpointManager() {
    return {
        endpoints: @json($template->endpoints ?? []),
        activeEndpointIndex: null,
        activeEndpointData: {},
        isAddingNew: false,
        isFormDirty: false,

        init() {
            // Automatically select first endpoint if any exist
            if (this.endpoints.length > 0) {
                this.selectEndpoint(0);
            }
        },

        selectEndpoint(index) {
            this.activeEndpointIndex = index;
            this.isAddingNew = false;
            this.isFormDirty = false;
            this.activeEndpointData = JSON.parse(JSON.stringify(this.endpoints[index]));
        },

        startAddingNew() {
            this.activeEndpointIndex = null;
            this.isAddingNew = true;
            this.isFormDirty = false;
            this.activeEndpointData = { name: '', path: '', method: 'GET', metric_map_json: '' };
        },

        cancelEndpointChanges() {
            this.isFormDirty = false;
            if (this.isAddingNew) {
                this.activeEndpointData = {};
                this.isAddingNew = false;
            } else if (this.activeEndpointIndex !== null) {
                this.selectEndpoint(this.activeEndpointIndex);
            }
        },

        saveEndpointChanges() {
            try {
                if (this.activeEndpointData.metric_map_json) {
                    JSON.parse(this.activeEndpointData.metric_map_json);
                    this.activeEndpointData.metric_map_error = '';
                }

                if (this.isAddingNew) {
                    this.endpoints.push(JSON.parse(JSON.stringify(this.activeEndpointData)));
                    this.activeEndpointIndex = this.endpoints.length - 1;
                    this.isAddingNew = false;
                } else if (this.activeEndpointIndex !== null) {
                    this.endpoints[this.activeEndpointIndex] = JSON.parse(JSON.stringify(this.activeEndpointData));
                }

                this.isFormDirty = false;
            } catch (e) {
                this.activeEndpointData.metric_map_error = 'Invalid JSON format.';
            }
        }
    };
}

</script>
@endsection
