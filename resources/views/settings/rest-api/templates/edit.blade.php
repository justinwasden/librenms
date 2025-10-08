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
    /* Maximize modal content for XL size */
    #endpointsModal .modal-dialog {
        max-width: 95%; /* Make it slightly wider than standard XL */
    }
    /* Scrollable form content on the right pane */
    .endpoint-form-scroll {
        max-height: 70vh;
        overflow-y: auto;
        padding-right: 15px; /* space for scrollbar */
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

                    {{-- Main Form for Basic Info (omitted for brevity) --}}
                    <form action="{{ route('settings.rest-api.templates.update', ['template' => $template->id]) }}" method="POST">
                        @csrf
                        @method('PUT')
                        {{-- ... (Basic Info content) ... --}}
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

                            {{-- Action Card Group (omitted for brevity) --}}
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

{{-- 1. Connection Modal (omitted for brevity) --}}
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
    {{-- Set to widest possible standard modal --}}
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
                        {{-- LEFT PANE: Endpoint List (Smaller width) --}}
                        <div class="col-md-4 border-right">
                            <h6 class="mb-3 text-primary"><i class="fas fa-list-ul"></i> Existing Endpoints</h6>

                            @php
                                $template_data_array = is_array($template->template_data)
                                    ? $template->template_data
                                    : (json_decode($template->template_data, true) ?? []);
                                $connections = $template_data_array['connections'] ?? [];
                                $cIndex = 0;
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
                        <div class="col-md-8">
                            <div x-show="activeEndpointIndex || isAddingNew" x-cloak>
                                <h6 class="mb-3" x-html="isAddingNew ? '<i class=\"fas fa-plus-square text-success\"></i> New Endpoint Details' : '<i class=\"fas fa-edit text-primary\"></i> Edit Endpoint: ' + activeEndpointName"></h6>

                                <div class="endpoint-form-scroll">
                                    <div id="endpoint-detail-container" x-html="currentEndpointFormHtml" @input="isFormDirty = true">
                                        {{-- Initial Load Placeholder --}}
                                        <div class="alert alert-warning text-center">Select an endpoint or click 'Add New Endpoint' to begin editing.</div>
                                    </div>
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

                    <input type="hidden" name="action_type" value="update_endpoints_only">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit"
                            class="btn btn-primary"
                            :disabled="!isFormDirty">
                        <i class="fas fa-save"></i> Save All Endpoints
                    </button>
                </div>

                <template id="full-endpoint-template">
                    @php
                        $js_placeholder_index = '__ACTIVE_INDEX__';
                        $js_connection_index = 0;
                    @endphp
                    @include('settings.rest-api.templates.partials.endpoint-form', [
                        'connectionIndex' => $js_connection_index,
                        'endpointIndex' => $js_placeholder_index,
                        'endpoint' => [],
                    ])
                </template>

            </form>
        </div>
    </div>
</div>

{{-- 3. Preview Modal (omitted for brevity) --}}
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
                @include('settings.rest-api.templates.partials.preview', ['template' => $template])
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>


<script>
// Base template editor function (unchanged)
function templateEditor() {
    return {
        openEndpoint: null,
        templateData: @json($template->template_data),

        init() {
            // Initialization for the main page
        },
    }
}

// Alpine Data for Endpoint Management (Updated)
function endpointManager() {
    // Helper to escape HTML entities for injection
    const escapeHtml = (str) => {
        if (!str) return '';
        return str.replace(/&/g, '&amp;')
                  .replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;')
                  .replace(/"/g, '&quot;')
                  .replace(/'/g, '&#039;');
    };

    const hydrateForm = (html, data, index) => {
        const cIndex = 0;
        const safeIndex = index.toString().replace(/'/g, '\\\'');

        // 1. Replace the dynamic index placeholder in all field names/IDs
        html = html.replace(/__ACTIVE_INDEX__/g, safeIndex);

        // 2. Hydrate values (focusing on text inputs and textareas)
        for (const [key, value] of Object.entries(data)) {
            if (typeof value === 'object' && value !== null) continue;
            const escapedValue = escapeHtml(String(value));

            // Text inputs
            html = html.replace(
                new RegExp(`name="template_data\\[connections\\]\\[${cIndex}\\]\\[endpoints\\]\\[${safeIndex}\\]\\[${key}\\]"\\s*value=".*?"`),
                `name="template_data[connections][${cIndex}][endpoints][${safeIndex}][${key}]" value="${escapedValue}"`
            );

            // Select inputs
            html = html.replace(
                new RegExp(`value="${escapedValue}"`),
                `value="${escapedValue}" selected`
            );
        }

        // 3. Special handling for Metric Map (Textarea content)
        const metricMapValue = data.metric_map ? JSON.stringify(data.metric_map, null, 4) : '';
        const textareaName = `template_data[connections][${cIndex}][endpoints][${safeIndex}][metric_map]`;
        const textareaPlaceholder = `__METRIC_MAP_CONTENT__`;

        // Inject the placeholder marker before the closing tag, and replace all metric_map names
        html = html.replace(
            new RegExp(`name="${textareaName}">\\s*<\\/textarea>`, 'g'),
            `name="${textareaName}">${textareaPlaceholder}</textarea>`
        );
        html = html.replace(textareaPlaceholder, escapeHtml(metricMapValue));

        // 4. Special handling for Checkbox (enabled)
        const isEnabled = data.enabled === false ? '' : 'checked';
        html = html.replace(
            new RegExp(`id="endpoint_enabled_${cIndex}_${safeIndex}"\\s*name="template_data\\[connections\\]\\[${cIndex}\\]\\[endpoints\\]\\[${safeIndex}\\]\\[enabled\\]"\\s*value="1"`),
            `id="endpoint_enabled_${cIndex}_${safeIndex}" name="template_data[connections][${cIndex}][endpoints][${safeIndex}][enabled]" value="1" ${isEnabled}`
        );

        return html;
    };

    return {
        activeEndpointIndex: null,
        activeEndpointData: {},
        activeEndpointName: '',
        isAddingNew: false,
        isFormDirty: false,        // NEW: Tracks if the form has been edited
        newEndpointCount: 0,
        currentEndpointFormHtml: '',

        init() {
            // Event listener to reset dirty state when modal is closed successfully
            $('#endpointsModal').on('hide.bs.modal', (e) => {
                if (!e.target.contains(e.relatedTarget) && this.isFormDirty) {
                    if (!confirm('You have unsaved changes. Are you sure you want to close?')) {
                        e.preventDefault();
                        return;
                    }
                }
                this.isFormDirty = false;
            });

            // Set up listener for form submission to potentially reset dirty state on success
            document.getElementById('endpoint-management-form').addEventListener('submit', () => {
                // If form submits successfully, the state will be clean.
                // A full page reload/redirect will usually handle this.
            });
        },

        openEndpoint(index, name, data) {
            this.activeEndpointIndex = index;
            this.activeEndpointName = name;
            this.activeEndpointData = data;
            this.isAddingNew = false;
            this.isFormDirty = false; // Reset dirty state on selection

            this.currentEndpointFormHtml = hydrateForm(document.getElementById('full-endpoint-template').innerHTML, data, index);

            this.$nextTick(() => {
                this.initializeEndpointScripts(index);
            });
        },

        openNewEndpoint() {
            const index = 'new_' + Date.now();

            this.activeEndpointIndex = index;
            this.activeEndpointName = 'New Endpoint';
            this.activeEndpointData = {name: '', method: 'GET', poll_interval: 300, enabled: true, metric_map: {}};
            this.isAddingNew = true;
            this.isFormDirty = false; // Reset dirty state on creation

            this.currentEndpointFormHtml = hydrateForm(document.getElementById('full-endpoint-template').innerHTML, this.activeEndpointData, index);

            this.$nextTick(() => {
                this.initializeEndpointScripts(index);
            });
        },

        initializeEndpointScripts(index) {
            const cIndex = 0;
            const uniqueId = `${cIndex}_${index}`;

            // Re-select elements by unique ID since the HTML was re-rendered
            const textarea = document.getElementById(`metric_map_json_${uniqueId}`);
            const beautifyButton = document.getElementById(`beautifyJson_${uniqueId}`);
            const errorDiv = document.getElementById(`jsonError_${uniqueId}`);

            if (!textarea) return;

            /**
             * Validate and pretty-print JSON
             */
            const validateAndFormatJSON = () => {
                const value = textarea.value.trim();
                if (!value) {
                    errorDiv.style.display = 'none';
                    return;
                }
                try {
                    const parsed = JSON.parse(value);
                    textarea.value = JSON.stringify(parsed, null, 4);
                    errorDiv.style.display = 'none';
                    this.isFormDirty = true;
                } catch (e) {
                    errorDiv.textContent = ' Invalid JSON: ' + e.message;
                    errorDiv.style.display = 'block';
                }
            };

            /**
             * Set dirty state and validate on input
             */
            textarea.oninput = () => {
                this.isFormDirty = true;
                try {
                    JSON.parse(textarea.value);
                    errorDiv.style.display = 'none';
                } catch (e) {
                    errorDiv.textContent = ' Invalid JSON: ' + e.message;
                    errorDiv.style.display = 'block';
                }
            };

            // Set dirty state on blur if metric map changed
            textarea.onblur = validateAndFormatJSON;

            // Set dirty state for other fields in the scroll container
            document.querySelector('.endpoint-form-scroll').addEventListener('input', (e) => {
                if (e.target.name && e.target.closest('#endpoint-detail-container')) {
                    this.isFormDirty = true;
                }
            });

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
            if (!confirm(`Are you sure you want to delete the endpoint with index ${indexToRemove}? This action will be finalized when you click "Save All Endpoints."`)) {
                return;
            }

            // Clear the right pane and set dirty state
            this.activeEndpointIndex = null;
            this.isAddingNew = false;
            this.isFormDirty = true;
            this.currentEndpointFormHtml = '<div class="alert alert-danger text-center mt-5"><i class="fas fa-trash"></i> Endpoint marked for deletion or removed from list. Click "Save All Endpoints" to finalize.</div>';

            // Create a hidden input to mark for deletion
            const form = document.getElementById('endpoint-management-form');
            const deleteFlagName = `template_data[connections][0][endpoints][${indexToRemove}][__DELETE_FLAG]`;
            let hiddenInput = form.querySelector(`input[name="${deleteFlagName}"]`);

            if (!hiddenInput) {
                hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = deleteFlagName;
                hiddenInput.value = '1';
                form.appendChild(hiddenInput);
            }

            // To update the list on the left, you'd need to re-render the modal's list content,
            // but for now, we rely on the Save button and page reload for visual confirmation.
        },
    }
}
</script>
@endsection