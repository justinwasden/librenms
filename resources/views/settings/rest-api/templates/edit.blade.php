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

{{-- 2. Endpoints Modal (Wider Layout Applied Here) --}}
<div class="modal fade" id="endpointsModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <form id="endpoint-management-form" action="{{ route('settings.rest-api.templates.update', ['template' => $template->id]) }}" method="POST" x-data="endpointManager()">
                @csrf
                @method('PUT')
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-tasks"></i> Manage Endpoints</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        {{-- LEFT PANE: Endpoint List --}}
                        <div class="col-md-3 border-right">
                            <h6 class="mb-3 text-primary"><i class="fas fa-list-ul"></i> Existing Endpoints</h6>

                            @php
                                $template_data_array = is_array($template->template_data)
                                    ? $template->template_data
                                    : (json_decode($template->template_data, true) ?? []);
                                $connections = $template_data_array['connections'] ?? [];
                                $cIndex = 0;
                                $connection = $connections[$cIndex] ?? ['endpoints' => []];
                            @endphp

                            @if (!empty($connection['endpoints']))
                                <div class="list-group mb-4" style="max-height: 600px; overflow-y: auto;">
                                    @foreach ($connection['endpoints'] as $eIndex => $endpoint)
                                        <a href="#" class="list-group-item list-group-item-action"
                                           :class="{ 'active': activeEndpointIndex === '{{ $cIndex }}-{{ $eIndex }}' }"
                                           @click.prevent="openEndpoint('{{ $cIndex }}-{{ $eIndex }}', '{{ $endpoint['name'] ?? 'Unnamed Endpoint' }}', {{ json_encode($endpoint) }})">
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
                                <div class="text-muted text-center py-3">
                                    No endpoints defined.
                                </div>
                            @endif

                            {{-- "Add New" Button --}}
                            <button type="button" class="btn btn-success btn-block mt-3"
                                    @click="openNewEndpoint()">
                                <i class="fas fa-plus-circle"></i> Add New Endpoint
                            </button>
                        </div> {{-- End Left Pane --}}

                        {{-- RIGHT PANE: Detail Form --}}
                        <div class="col-md-9" :class="{ 'endpoint-dirty': isFormDirty }">
                            <template x-if="activeEndpointIndex || isAddingNew">
                                <div>
                                    <h6 class="mb-3" x-html="isAddingNew
                                        ? '<i class=\'fas fa-plus-square text-success\'></i> New Endpoint Details'
                                        : '<i class=\'fas fa-edit text-primary\'></i> Edit Endpoint: ' + activeEndpointName"></h6>

                                    <div id="endpoint-detail-container" @input="isFormDirty = true" x-html="currentEndpointFormHtml"></div>

                                    <div x-show="isFormDirty" x-transition class="mt-3 text-right">
                                        <button type="button" class="btn btn-secondary mr-2" @click="cancelEndpointChanges">
                                            <i class="fas fa-undo"></i> Cancel Changes
                                        </button>
                                        <button type="button" class="btn btn-success" @click="saveEndpointChanges">
                                            <i class="fas fa-save"></i> Save Endpoint
                                        </button>
                                    </div>
                                </div>
                            </template>

                            <template x-if="!activeEndpointIndex && !isAddingNew">
                                <div class="alert alert-warning text-center mt-5">
                                    <i class="fas fa-hand-point-left fa-2x"></i><br>
                                    Select an endpoint from the list to view its configuration, or click "Add New Endpoint."
                                </div>
                            </template>
                        </div>
                    </div>
                    <input type="hidden" name="action_type" value="update_endpoints_only">
                </div>
                <div class="modal-footer">
							    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
							</div>

                {{-- Endpoint Form Template --}}
                <template id="full-endpoint-template">
                    @php $cIndex = 0; $eIndex = '__ACTIVE_INDEX__'; @endphp
                    <div class="endpoint-form-scroll" style="max-height:70vh; overflow-y:auto;">
                        <form id="endpoint-detail-form">
                            <div class="form-group">
                                <label for="endpoint_name_{{ $cIndex }}_{{ $eIndex }}">Name</label>
                                <input type="text" name="template_data[connections][{{ $cIndex }}][endpoints][{{ $eIndex }}][name]"
                                       id="endpoint_name_{{ $cIndex }}_{{ $eIndex }}" class="form-control" value="">
                            </div>

                            <div class="form-group">
                                <label for="endpoint_path_{{ $cIndex }}_{{ $eIndex }}">Path</label>
                                <input type="text" name="template_data[connections][{{ $cIndex }}][endpoints][{{ $eIndex }}][path]"
                                       id="endpoint_path_{{ $cIndex }}_{{ $eIndex }}" class="form-control" value="">
                            </div>

                            <div class="form-group">
                                <label for="endpoint_method_{{ $cIndex }}_{{ $eIndex }}">Method</label>
                                <select name="template_data[connections][{{ $cIndex }}][endpoints][{{ $eIndex }}][method]"
                                        id="endpoint_method_{{ $cIndex }}_{{ $eIndex }}" class="form-control">
                                    <option value="GET">GET</option>
                                    <option value="POST">POST</option>
                                    <option value="PUT">PUT</option>
                                    <option value="DELETE">DELETE</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="metric_map_json_{{ $cIndex }}_{{ $eIndex }}">Metric Map JSON</label>
                                <textarea id="metric_map_json_0_{{ $endpointIndex }}"
														          class="form-control endpoint-form-scroll"
														          rows="8">{{ $endpoint['metric_map'] ?? '{}' }}</textarea>
                                <div id="jsonError_{{ $cIndex }}_{{ $eIndex }}" class="text-danger mt-1" style="display:none;"></div>
                            </div>
                        </form>
                    </div>
                </template>

            </form>
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
function endpointManager() {
    return {
        activeEndpointIndex: null,
        activeEndpointName: '',
        activeEndpointData: {},
        currentEndpointFormHtml: '',
        isAddingNew: false,
        isFormDirty: false,

        endpoints: @json($connection['endpoints'] ?? []),

        init() {
            if (this.endpoints.length > 0) {
                this.openEndpoint('0-0', this.endpoints[0].name, this.endpoints[0]);
            }

            $('#endpointsModal').on('hide.bs.modal', (e) => {
                if (this.isFormDirty && !confirm('You have unsaved changes. Close anyway?')) {
                    e.preventDefault();
                }
            });
        },

        openEndpoint(index, name, data) {
				    this.activeEndpointIndex = index;
				    this.activeEndpointName = name;
				    this.activeEndpointData = JSON.parse(JSON.stringify(data)); // deep copy
				    this.isAddingNew = false;
				    this.isFormDirty = false;

				    const templateHtml = document.querySelector('#full-endpoint-template').innerHTML;
				    this.currentEndpointFormHtml = templateHtml.replace(/__ACTIVE_INDEX__/g, index);

				    this.$nextTick(() => {
				        const cIndex = 0;
				        // Fill Name
				        const nameField = document.getElementById(`endpoint_name_${cIndex}_${index}`);
				        if (nameField) nameField.value = data.name || '';

				        // Fill Path
				        const pathField = document.getElementById(`endpoint_path_${cIndex}_${index}`);
				        if (pathField) pathField.value = data.path || '';

				        // Fill Method
				        const methodField = document.getElementById(`endpoint_method_${cIndex}_${index}`);
				        if (methodField) methodField.value = data.method || 'GET';

				        // Fill Metric Map JSON
				        const metricMapField = document.getElementById(`metric_map_json_${cIndex}_${index}`);
				        if (metricMapField) metricMapField.value = JSON.stringify(data.metric_map || {}, null, 4);

				        // Initialize JSON validation
				        this.initializeEndpointScripts(index);
				    });
				},

        openNewEndpoint() {
            const index = 'new_' + Date.now();
            this.activeEndpointIndex = index;
            this.activeEndpointName = 'New Endpoint';
            this.activeEndpointData = {name:'', path:'', method:'GET', metric_map:{}};
            this.isAddingNew = true;
            this.isFormDirty = false;

            const templateHtml = document.querySelector('#full-endpoint-template').innerHTML;
            this.currentEndpointFormHtml = templateHtml.replace(/__ACTIVE_INDEX__/g, index);

            this.$nextTick(() => {
                this.initializeEndpointScripts(index);
            });
        },

        initializeEndpointScripts(index) {
            const cIndex = 0;
            const textarea = document.getElementById(`metric_map_json_${cIndex}_${index}`);
            const errorDiv = document.getElementById(`jsonError_${cIndex}_${index}`);
            if (!textarea) return;

            textarea.oninput = () => {
                this.isFormDirty = true;
                try {
                    JSON.parse(textarea.value);
                    errorDiv.style.display = 'none';
                } catch (e) {
                    errorDiv.textContent = 'Invalid JSON: ' + e.message;
                    errorDiv.style.display = 'block';
                }
            };
        },

        cancelEndpointChanges() {
            if (!confirm('Discard changes to this endpoint?')) return;
            this.isFormDirty = false;
            if (!this.isAddingNew && this.activeEndpointIndex) {
                this.openEndpoint(this.activeEndpointIndex, this.activeEndpointName, this.activeEndpointData);
            }
        },

       saveEndpointChanges() {
				    if (!this.activeEndpointIndex) return alert('No endpoint selected.');

				    const cIndex = 0;
				    const index = this.activeEndpointIndex;

				    // Grab values from right-hand inputs
				    const name = document.getElementById(`endpoint_name_${cIndex}_${index}`)?.value || '';
				    const path = document.getElementById(`endpoint_path_${cIndex}_${index}`)?.value || '';
				    const method = document.getElementById(`endpoint_method_${cIndex}_${index}`)?.value || 'GET';
				    const metricMapField = document.getElementById(`metric_map_json_${cIndex}_${index}`);
				    let metric_map = {};
				    try {
				        // Parse JSON, but we will store the original string for formatting
				        metric_map = JSON.parse(metricMapField?.value || '{}');
				    } catch (e) {
				        alert('Metric Map JSON is invalid. Please fix before saving.');
				        return;
				    }

				    const enabledCheckbox = document.getElementById(`endpoint_enabled_${cIndex}_${index}`);
				    const enabled = enabledCheckbox?.checked ? true : false;

				    // Prepare payload
				    const payload = new FormData();
				    payload.append('template_id', '{{ $template->id }}');
				    payload.append('connection_index', cIndex);
				    payload.append('endpoint_index', index);
				    payload.append('name', name);
				    payload.append('path', path);
				    payload.append('method', method);
				    payload.append('enabled', enabled ? 1 : 0);

				    // Preserve JSON formatting exactly as entered by user
				    payload.append('metric_map', metricMapField.value);

				    // AJAX call to save this endpoint
				    fetch('/device/api-endpoints/update', {
				        method: 'POST',
				        body: payload,
				        headers: {
				            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
				        }
				    })
				    .then(r => r.json())
				    .then(data => {
				        if (data.success) {
				            this.isFormDirty = false;
				            alert(`Endpoint "${name}" saved successfully.`);
				        } else {
				            alert('Error saving endpoint: ' + (data.message || 'Unknown error'));
				        }
				    })
				    .catch(err => console.error('Save failed', err));
				}

</script>
@endsection
