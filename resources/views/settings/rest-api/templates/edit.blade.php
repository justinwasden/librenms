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
    /* FIX: Custom class for maximum modal width (equivalent to roughly 98% viewport) */
    .modal-full-width {
        max-width: 98% !important;
        margin: 0.5rem auto;
    }
    /* Scrollable form content on the right pane */
    .endpoint-form-scroll {
        max-height: 70vh;
        overflow-y: auto;
        padding-right: 15px;
    }
</style>
@endpush

@push('scripts')
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
@endpush

@section('content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-md-14 col-lg-9 col-xl-8">
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

{{-- 2. Endpoints Modal (Wider Layout Applied Here) --}}
<div class="modal fade" id="endpointsModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-full-width" role="document">
        <div class="modal-content">
            <form id="endpoint-management-form" action="#" method="POST" x-data="endpointManager()">
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
                        <div class="col-md-10"> {{-- Form maximized to 10/12 --}}
                            <div x-show="activeEndpointIndex || isAddingNew" x-cloak>
                                <h6 class="mb-3" x-html="isAddingNew ? '<i class=\"fas fa-plus-square text-success\"></i> New Endpoint Details' : '<i class=\"fas fa-edit text-primary\"></i> Edit Endpoint: ' + activeEndpointName"></h6>

                                <div class="endpoint-form-scroll">
                                    <div id="endpoint-detail-container" x-html="currentEndpointFormHtml" @input="isFormDirty = true">
                                        {{-- Initial Load Placeholder --}}
                                        <div class="alert alert-warning text-center">Select an endpoint or click 'Add New Endpoint' to begin editing.</div>
                                    </div>
                                </div>

                                {{-- Endpoint-Specific Action Buttons (Delete button removed) --}}
                                <div class="d-flex justify-content-end pt-3 border-top mt-3" x-show="activeEndpointIndex">
                                    <button type="button"
                                            class="btn btn-outline-secondary mr-2"
                                            @click="cancelEdit()">
                                        <i class="fas fa-times"></i> Cancel Changes
                                    </button>

                                    <button type="submit"
                                            class="btn btn-primary"
                                            @click.prevent="saveEndpoint(activeEndpointIndex)"
                                            :disabled="!isFormDirty">
                                        <i class="fas fa-save"></i> Save Endpoint
                                    </button>
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
                </div>

                {{-- Modal Footer only contains the close button --}}
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
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
// The Alpine logic for endpointManager remains largely the same for save/cancel/dirty-state
// but is not fully duplicated here for brevity.
// The critical change is the removal of the delete button logic from the saveEndpoint and removeEndpoint functions.

function templateEditor() {
    return {
        openEndpoint: null,
        templateData: @json($template->template_data),

        init() {
            $('#endpointsModal').on('show.bs.modal', () => {
                // Real application would refresh the list data here
            });
        },
    }
}

function endpointManager() {
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

        html = html.replace(/__ACTIVE_INDEX__/g, safeIndex);

        const formKeys = ['name', 'path', 'method', 'resource_type', 'poll_interval', 'description', 'response_path'];
        formKeys.forEach(key => {
            const value = data[key] !== undefined ? String(data[key]) : (key === 'poll_interval' ? '300' : '');
            const escapedValue = escapeHtml(value);

            // Hydrate input value attributes (general text/number inputs)
            html = html.replace(
                new RegExp(`name="template_data\\[connections\\]\\[${cIndex}\\]\\[endpoints\\]\\[${safeIndex}\\]\\[${key}\\]"\\s*value=".*?"`),
                `name="template_data[connections][${cIndex}][endpoints][${safeIndex}][${key}]" value="${escapedValue}"`
            );

            // Hydrate select 'selected' attributes
            html = html.replace(
                new RegExp(`value="${escapedValue}"(?![^>]*selected)`),
                `value="${escapedValue}" selected`
            );

            // Hydrate textarea values (description)
             if (key === 'description') {
                 html = html.replace(
                    new RegExp(`name="template_data\\[connections\\]\\[${cIndex}\\]\\[endpoints\\]\\[${safeIndex}\\]\\[${key}\\]">.*?<\\/textarea>`, 's'),
                    `name="template_data[connections][${cIndex}][endpoints][${safeIndex}][${key}]">${escapedValue}</textarea>`
                );
             }
        });

        // 3. Special handling for Metric Map (Textarea content)
        const metricMapValue = data.metric_map ? JSON.stringify(data.metric_map, null, 4) : '{}';
        const textareaName = `template_data[connections][${cIndex}][endpoints][${safeIndex}][metric_map]`;
        const textareaPlaceholder = `__METRIC_MAP_CONTENT__`;

        // Inject the placeholder marker before the closing tag, and replace all metric_map names
        html = html.replace(
            new RegExp(`name="${textareaName}"(.*?)>.*?<\\/textarea>`, 's'),
            `name="${textareaName}"$1>${textareaPlaceholder}</textarea>`
        );
        html = html.replace(textareaPlaceholder, escapeHtml(metricMapValue));


        // 4. Special handling for Checkbox (enabled)
        const isEnabled = data.enabled === false ? '' : 'checked';
        html = html.replace(
            new RegExp(`id="endpoint_enabled_${cIndex}_${safeIndex}"(.*?)checked`),
            `id="endpoint_enabled_${cIndex}_${safeIndex}"$1`
        );
        if (isEnabled) {
            html = html.replace(
                new RegExp(`id="endpoint_enabled_${cIndex}_${safeIndex}"\\s*name="template_data\\[connections\\]\\[${cIndex}\\]\\[endpoints\\]\\[${safeIndex}\\]\\[enabled\\]"\\s*value="1"`),
                `id="endpoint_enabled_${cIndex}_${safeIndex}" name="template_data[connections][${cIndex}][endpoints][${safeIndex}][enabled]" value="1" ${isEnabled}`
            );
        }

        return html;
    };

    const extractFormData = (index) => {
        const form = document.getElementById('endpoint-management-form');
        const formData = new FormData(form);
        const data = {};
        const prefix = `template_data[connections][0][endpoints][${index}]`;

        for (const [key, value] of formData.entries()) {
            if (key.startsWith(prefix)) {
                const fieldName = key.substring(prefix.length + 2, key.length - 1);

                if (fieldName === 'metric_map') {
                    try {
                        data.metric_map = JSON.parse(value);
                    } catch (e) {
                        alert('Metric Mapping JSON is invalid and cannot be saved.');
                        return null;
                    }
                } else {
                    data[fieldName] = value;
                }
            }
        }

        if (!data.hasOwnProperty('enabled')) {
            data.enabled = 0;
        }
        return data;
    };

    return {
        activeEndpointIndex: null,
        activeEndpointData: {},
        activeEndpointName: '',
        isAddingNew: false,
        isFormDirty: false,
        newEndpointCount: 0,
        currentEndpointFormHtml: '',
        initialDataSnapshot: null,

        init() {
            $('#endpointsModal').on('hide.bs.modal', (e) => {
                const submitClicked = $(document.activeElement).is('button[type="submit"]');
                if (!submitClicked && this.isFormDirty) {
                    if (!confirm('You have unsaved changes. Are you sure you want to close?')) {
                        e.preventDefault();
                        return;
                    }
                }
                this.isFormDirty = false;
            });
        },

        async saveEndpoint(index) {
            const endpointData = extractFormData(index);
            if (!endpointData) return;

            const payload = {
                action_type: 'update_endpoint_granular',
                endpoint_index: index,
                endpoint_data: endpointData,
                _token: '{{ csrf_token() }}',
                _method: 'PUT'
            };

            try {
                const response = await fetch('{{ route('settings.rest-api.templates.update', ['template' => $template->id]) }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', },
                    body: JSON.stringify(payload)
                });

                const data = await response.json();

                if (response.ok && data.success) {
                    alert('Endpoint saved successfully!');
                    this.isFormDirty = false;

                    // Crucial: Update the internal data snapshot to mark the state as clean
                    this.activeEndpointData = data.updated_endpoint_data || endpointData;
                    this.initialDataSnapshot = JSON.stringify(this.activeEndpointData);

                    if (this.isAddingNew) {
                        this.activeEndpointIndex = data.new_index || index;
                        this.isAddingNew = false;
                        // Reload page to refresh the list on the left with the new endpoint
                        window.location.reload();
                    }

                } else {
                    alert('Error saving endpoint: ' + (data.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Save error:', error);
                alert('A network error occurred while saving.');
            }
        },

        cancelEdit() {
            if (!this.isFormDirty || confirm('Discard all unsaved changes for this endpoint?')) {
                if (this.isAddingNew) {
                    this.activeEndpointIndex = null;
                    this.isAddingNew = false;
                    this.currentEndpointFormHtml = '<div class="alert alert-warning text-center">Select an endpoint or click \'Add New Endpoint\' to begin editing.</div>';
                } else {
                    // Re-open the form with the initial, saved data (from the snapshot)
                    this.activeEndpointData = JSON.parse(this.initialDataSnapshot);
                    this.currentEndpointFormHtml = hydrateForm(document.getElementById('full-endpoint-template').innerHTML, this.activeEndpointData, this.activeEndpointIndex);
                    this.$nextTick(() => {
                        this.initializeEndpointScripts(this.activeEndpointIndex);
                    });
                }
                this.isFormDirty = false;
            }
        },

        openEndpoint(index, name, data) {
            if (this.isFormDirty && this.activeEndpointIndex) {
                 if (!confirm(`You have unsaved changes to ${this.activeEndpointName}. Continue without saving?`)) {
                    return;
                }
            }

            this.activeEndpointIndex = index;
            this.activeEndpointName = name;
            this.activeEndpointData = data;
            this.isAddingNew = false;
            this.isFormDirty = false;
            this.initialDataSnapshot = JSON.stringify(data);

            this.currentEndpointFormHtml = hydrateForm(document.getElementById('full-endpoint-template').innerHTML, data, index);

            this.$nextTick(() => {
                this.initializeEndpointScripts(index);
                document.querySelector('.endpoint-form-scroll').scrollTop = 0;
            });
        },

        openNewEndpoint() {
            if (this.isFormDirty && this.activeEndpointIndex) {
                 if (!confirm(`You have unsaved changes to ${this.activeEndpointName}. Continue without saving?`)) {
                    return;
                }
            }

            const index = 'new_' + Date.now();

            this.activeEndpointIndex = index;
            this.activeEndpointName = 'New Endpoint';
            this.activeEndpointData = {name: '', method: 'GET', poll_interval: 300, enabled: true, metric_map: {}};
            this.isAddingNew = true;
            this.isFormDirty = false;
            this.initialDataSnapshot = JSON.stringify(this.activeEndpointData);

            this.currentEndpointFormHtml = hydrateForm(document.getElementById('full-endpoint-template').innerHTML, this.activeEndpointData, index);

            this.$nextTick(() => {
                this.initializeEndpointScripts(index);
                document.querySelector('.endpoint-form-scroll').scrollTop = 0;
            });
        },

        initializeEndpointScripts(index) {
            const cIndex = 0;
            const uniqueId = `${cIndex}_${index}`;

            const textarea = document.getElementById(`metric_map_json_${uniqueId}`);
            const beautifyButton = document.getElementById(`beautifyJson_${uniqueId}`);
            const errorDiv = document.getElementById(`jsonError_${uniqueId}`);

            if (!textarea) return;

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

            textarea.onblur = validateAndFormatJSON;

            document.querySelector('.endpoint-form-scroll').addEventListener('input', (e) => {
                if (e.target.name && e.target.closest('#endpoint-detail-container')) {
                    this.isFormDirty = true;
                }
            });

            if (beautifyButton) {
                beautifyButton.onclick = function(e) {
                    e.preventDefault();
                    validateAndFormatJSON();
                };
            }
        },

        // Delete endpoint is now a dedicated action, similar to save, that hits the backend
        removeEndpoint(indexToRemove) {
            // Note: The delete button is removed from the form, but this function is kept
            // in case you re-introduce a dedicated delete button outside the main form.
            alert('The Delete Endpoint button was removed from the form as requested. If you want to delete, you must temporarily add the button back.');
        }
    }
}
</script>
@endsection