@extends('layouts.librenmsv1')

@section('title', 'Edit REST API Template')

@push('styles')
<style>
    /* Retained for Alpine/Modal functionality */
    [x-cloak] { display: none !important; }

    /* Enhanced Card Layout for Actions */
    .action-card {
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .action-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,.1);
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

                    {{-- Main Form for Basic Info --}}
                    <form action="{{ route('settings.rest-api.templates.update', ['template' => $template->id]) }}" method="POST">
                        @csrf
                        @method('PUT')

                        <div class="card-body">
                            <h5 class="mb-3 text-info"><i class="fas fa-info-circle"></i> Basic Template Information</h5>

                            {{-- Template Basic Info --}}
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

                            {{-- Resource Type and Description --}}
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

                            {{-- Action Card Group --}}
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
                    {{-- The content of connection.blade.php --}}
                    @include('settings.rest-api.templates.partials.connection', ['template' => $template])

                    {{-- Hidden field to ensure connection data is processed without side effects on basic fields --}}
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
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            {{-- Form for Endpoints, using a two-pane layout with Alpine --}}
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
                        {{-- LEFT PANE: Endpoint List (Smaller width) --}}
                        <div class="col-md-5 border-right">
                            <h6 class="mb-3 text-primary"><i class="fas fa-list-ul"></i> Existing Endpoints</h6>

                            @php
                                $template_data_array = is_array($template->template_data)
                                    ? $template->template_data
                                    : (json_decode($template->template_data, true) ?? []);
                                $connections = $template_data_array['connections'] ?? [];
                                $cIndex = 0; // Assuming the primary connection is index 0
                            @endphp

                            @if (isset($connections[$cIndex]))
                                <div class="alert alert-info py-2">
                                    Connection: **{{ $connections[$cIndex]['name'] ?? 'Unnamed Connection' }}**
                                </div>

                                {{-- List of Existing Endpoints --}}
                                <div class="list-group mb-4" style="max-height: 600px; overflow-y: auto;">
                                    @php $connection = $connections[$cIndex]; @endphp
                                    @if (!empty($connection['endpoints']))
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
                                    @else
                                        <div class="text-muted text-center py-3">
                                            No endpoints defined.
                                        </div>
                                    @endif
                                </div>
                            @else
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-triangle"></i> No connection configured.
                                </div>
                            @endif

                            {{-- "Add New" Button --}}
                            <button type="button" class="btn btn-success btn-block mt-3"
                                    @click="openNewEndpoint()">
                                <i class="fas fa-plus-circle"></i> Add New Endpoint
                            </button>
                        </div> {{-- End Left Pane --}}

                        {{-- RIGHT PANE: Detail Form (Wider width) --}}
                        <div class="col-md-7">
                            <div x-show="activeEndpointIndex || isAddingNew" x-cloak>
                                <h6 class="mb-3" x-html="isAddingNew ? '<i class=\"fas fa-plus-square text-success\"></i> New Endpoint Details' : '<i class=\"fas fa-edit text-primary\"></i> Edit Endpoint: ' + activeEndpointName"></h6>

                                {{-- The dynamically rendered form based on selection --}}
                                <div id="endpoint-detail-container" x-html="currentEndpointFormHtml">
                                    {{-- Initial Load Placeholder --}}
                                    <div class="alert alert-warning text-center">Select an endpoint or click 'Add New Endpoint' to begin editing.</div>
                                </div>

                            </div>
                            <div x-show="!activeEndpointIndex && !isAddingNew">
                                <div class="alert alert-warning text-center mt-5">
                                    <i class="fas fa-hand-point-left fa-2x"></i><br>
                                    Select an endpoint from the list to view its configuration, or click "Add New Endpoint."
                                </div>
                            </div>
                        </div> {{-- End Right Pane --}}
                    </div>

                    {{-- Hidden field to ensure only endpoint data is processed --}}
                    <input type="hidden" name="action_type" value="update_endpoints_only">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    {{-- A full save is needed to persist all endpoint changes --}}
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save All Endpoints</button>
                </div>

                {{-- Hidden Template for ALL new endpoint additions, ensuring all fields are present --}}
                <template id="full-endpoint-template">
                    @php
                        $js_placeholder_index = '__ACTIVE_INDEX__'; // Using a generic placeholder
                        $js_connection_index = 0;
                    @endphp
                    @include('settings.rest-api.templates.partials.endpoint-form', [
                        'connectionIndex' => $js_connection_index,
                        'endpointIndex' => $js_placeholder_index,
                        'endpoint' => [], // Empty array for initial state
                    ])
                </template>

            </form>
        </div>
    </div>
</div>

{{-- 3. Preview Modal (Test Template) --}}
<div class="modal fade" id="previewModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-eye"></i> Test Template Configuration</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                {{-- The content of preview.blade.php --}}
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
        openEndpoint: null,
        templateData: @json($template->template_data),

        init() {
            // Initialization for the main page
        },
    }
}

// Alpine Data for Endpoint Management within the Modal (Two-Pane Logic)
function endpointManager() {
    return {
        activeEndpointIndex: null,
        activeEndpointData: {},
        activeEndpointName: '',
        isAddingNew: false,
        newEndpointCount: 0,
        currentEndpointFormHtml: '',

        init() {
            // Load the empty form template on modal open (or initialization)
            this.currentEndpointFormHtml = ''; // Initially empty, will show a message
        },

        // Open an existing endpoint for editing
        openEndpoint(index, name, data) {
            this.activeEndpointIndex = index;
            this.activeEndpointName = name;
            this.activeEndpointData = data;
            this.isAddingNew = false;

            this.currentEndpointFormHtml = this.generateEndpointFormHtml(index, data);

            // Re-run the JSON validation script for the newly rendered form
            this.$nextTick(() => {
                this.initializeEndpointScripts(index);
            });
        },

        // Start the process of adding a new endpoint
        openNewEndpoint() {
            // Ensure unique ID for this session
            const index = 'new_' + Date.now() + '_' + this.newEndpointCount;
            this.newEndpointCount++;

            this.activeEndpointIndex = index;
            this.activeEndpointName = 'New Endpoint';
            this.activeEndpointData = {name: 'New Endpoint', method: 'GET', poll_interval: 300, enabled: true};
            this.isAddingNew = true;

            this.currentEndpointFormHtml = this.generateEndpointFormHtml(index, {});

            // Re-run the JSON validation script for the newly rendered form
            this.$nextTick(() => {
                this.initializeEndpointScripts(index);
            });
        },

        // Helper function to generate the form HTML
        generateEndpointFormHtml(index, data) {
            let html = document.getElementById('full-endpoint-template').innerHTML;

            const cIndex = 0; // Assuming primary connection
            const safeIndex = index.toString().replace(/'/g, '\\\'');

            // 1. Replace the dynamic index placeholder in all field names/IDs
            html = html.replace(/__ACTIVE_INDEX__/g, safeIndex);

            // 2. Add a "Remove" button
            let removeButton = `
                <div class="text-right mb-3">
                    <button type="button" class="btn btn-sm btn-danger" @click="removeEndpoint('${safeIndex}')">
                        <i class="fas fa-trash"></i> ${index.toString().startsWith('new_') ? 'Remove Unsaved' : 'Delete Existing'} Endpoint
                    </button>
                </div>
            `;
            html = removeButton + html;

            // 3. Populate initial values for existing endpoints.
            // This is a minimal implementation; a complete solution would require more complexity.
            // We focus on the metric map since it's a textarea.
            const metricMapValue = JSON.stringify(data.metric_map || {}, null, 4);
            const textareaName = `template_data[connections][${cIndex}][endpoints][${safeIndex}][metric_map]`;

            // Use a temporary placeholder for the value and replace it after the main ID/name substitution
            const textareaPlaceholder = `__METRIC_MAP_CONTENT__`;
            html = html.replace(`name="${textareaName}">`, `name="${textareaName}">${textareaPlaceholder}</textarea>`);

            // Inject the metric map value
            html = html.replace(textareaPlaceholder, metricMapValue);

            return html;
        },

        // Manual initialization of the JS needed for the endpoint-form partial
        initializeEndpointScripts(index) {
            const cIndex = 0; // Assuming primary connection
            const uniqueId = `${cIndex}_${index}`;

            const textarea = document.getElementById(`metric_map_json_${uniqueId}`);
            const beautifyButton = document.getElementById(`beautifyJson_${uniqueId}`);
            const errorDiv = document.getElementById(`jsonError_${uniqueId}`);

            if (!textarea) return;

            /**
             * Validate and pretty-print JSON
             */
            function validateAndFormatJSON() {
                const value = textarea.value.trim();
                if (!value) {
                    errorDiv.style.display = 'none';
                    return;
                }
                try {
                    const parsed = JSON.parse(value);
                    textarea.value = JSON.stringify(parsed, null, 4);
                    errorDiv.style.display = 'none';
                } catch (e) {
                    errorDiv.textContent = ' Invalid JSON: ' + e.message;
                    errorDiv.style.display = 'block';
                }
            }

            /**
             * Validate while typing (without reformatting)
             */
            textarea.oninput = () => {
                try {
                    JSON.parse(textarea.value);
                    errorDiv.style.display = 'none';
                } catch (e) {
                    errorDiv.textContent = ' Invalid JSON: ' + e.message;
                    errorDiv.style.display = 'block';
                }
            };

            /**
             * Beautify JSON automatically when focus is lost
             */
            textarea.onblur = validateAndFormatJSON;

            /**
             * Manual Beautify button
             */
            if (beautifyButton) {
                beautifyButton.onclick = function(e) {
                    e.preventDefault();
                    validateAndFormatJSON();
                };
            }
        },

        removeEndpoint(indexToRemove) {
            if (!confirm(`Are you sure you want to delete the endpoint with index ${indexToRemove}? This action cannot be undone on save.`)) {
                return;
            }

            // To delete an existing endpoint, we must submit a hidden field that signals deletion.
            // Since we're using a single form for all endpoints, the simplest way is to
            // inject a hidden 'DELETE' flag for the specific index.

            // For both new and existing, we clear the right pane
            this.activeEndpointIndex = null;
            this.isAddingNew = false;
            this.currentEndpointFormHtml = '<div class="alert alert-success text-center mt-5"><i class="fas fa-check"></i> Endpoint hidden/removed. Click "Save All Endpoints" to apply changes.</div>';

            // --- Crucial Deletion Logic ---
            const form = document.getElementById('endpoint-management-form');

            // Create a hidden input to mark for deletion
            const deleteFlagName = `template_data[connections][0][endpoints][${indexToRemove}][__DELETE_FLAG]`;
            let hiddenInput = form.querySelector(`input[name="${deleteFlagName}"]`);

            if (!hiddenInput) {
                hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = deleteFlagName;
                hiddenInput.value = '1';
                form.appendChild(hiddenInput);
            }
            // --- End Deletion Logic ---

            alert('Endpoint marked for deletion or removed from unsaved list. Click "Save All Endpoints" to finalize.');
        },
    }
}
</script>
@endsection