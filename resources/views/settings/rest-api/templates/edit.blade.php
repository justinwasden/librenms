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
        height: calc(100vh - 150px);
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
                                        <label>Metric Mapping <small class="text-muted">- Optional, leave empty for auto-learning</small></label>
                                        
                                        {{-- API Preview Button --}}
                                        <div class="mb-3">
                                            <button type="button" class="btn btn-info btn-sm fetch-api-preview-btn"
                                                    @click="fetchApiPreview()">
                                                <i class="fas fa-download"></i> Fetch API Preview
                                            </button>
                                            <span class="preview-status ml-2" x-show="previewLoading" style="color: #0066cc;">
                                                ⟳ Fetching...
                                            </span>
                                            <span class="preview-status ml-2" x-show="previewSuccess && !previewLoading" style="color: #28a745;">
                                                ✓ Preview ready
                                            </span>
                                            <span class="preview-status ml-2" x-show="previewError && !previewLoading" style="color: #dc3545;">
                                                ✗ Error
                                            </span>
                                        </div>

                                        {{-- JSON Textarea --}}
                                        <textarea class="form-control font-monospace" rows="10" x-model="selectedEndpoint.metric_map_json"
                                                  @input="checkForChanges()" placeholder='{\n  "field_name": "json.path.to.field"\n}'></textarea>
                                        <small class="form-text text-muted">Leave empty to let the system auto-learn field mappings</small>
                                        <button type="button" class="btn btn-sm btn-secondary mt-2"
                                                @click="beautifyJson()">
                                            <i class="fas fa-indent"></i> Beautify JSON
                                        </button>
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

                                    {{-- API PREVIEW SECTION --}}
                                    <hr class="mt-4 mb-3">
                                    <h6 class="text-info mb-3"><i class="fas fa-database"></i> API Response Preview</h6>
                                    
                                    <div x-show="!apiPreviewData && !previewError && previewFetched" class="alert alert-info mb-3">
                                        <i class="fas fa-info-circle"></i> Click "Fetch API Preview" above to load API response data.
                                    </div>

                                    <div x-show="previewError && previewFetched" class="alert alert-danger mb-3">
                                        <i class="fas fa-exclamation-triangle"></i> <strong>Error fetching preview:</strong>
                                        <p class="mb-0" x-text="previewErrorMessage"></p>
                                    </div>

                                    <div x-show="apiPreviewData && previewFetched" class="bg-light p-3 rounded" style="max-height: 600px; overflow-y: auto;">
                                        {{-- Recommendations Tab --}}
                                        <ul class="nav nav-tabs mb-3" role="tablist">
                                            <li class="nav-item">
                                                <a class="nav-link active" href="#" @click.prevent="activePreviewTab = 'recommendations'" 
                                                   :class="{ 'active': activePreviewTab === 'recommendations' }">
                                                    <i class="fas fa-lightbulb"></i> Recommendations
                                                    <span class="badge badge-info ml-2" x-show="apiPreviewRecommendations.length === 0">(Vendor-specific only)</span>
                                                    <span class="badge badge-success ml-2" x-show="apiPreviewRecommendations.length > 0" 
                                                          x-text="apiPreviewRecommendations.length"></span>
                                                </a>
                                            </li>
                                            <li class="nav-item">
                                                <a class="nav-link" href="#" @click.prevent="activePreviewTab = 'structure'"
                                                   :class="{ 'active': activePreviewTab === 'structure' }">
                                                    <i class="fas fa-sitemap"></i> Structure
                                                </a>
                                            </li>
                                            <li class="nav-item">
                                                <a class="nav-link" href="#" @click.prevent="activePreviewTab = 'sample'"
                                                   :class="{ 'active': activePreviewTab === 'sample' }">
                                                    <i class="fas fa-code"></i> Sample Data
                                                </a>
                                            </li>
                                        </ul>

                                        {{-- Recommendations Tab Content --}}
                                        <div x-show="activePreviewTab === 'recommendations'">
                                            <div x-show="apiPreviewRecommendations.length === 0" class="text-muted text-center py-4">
                                                <p><i class="fas fa-info-circle"></i> <strong>No vendor-specific recommendations available</strong></p>
                                                <p class="mb-0">Use the <strong>Structure</strong> tab below to manually map fields to LibreNMS tables.</p>
                                                <p class="mb-0 small mt-2">Most API fields will need custom mapping based on your specific use case.</p>
                                            </div>

                                            <div x-show="apiPreviewRecommendations.length > 0" class="table-responsive">
                                                <p class="text-muted"><i class="fas fa-star"></i> <strong>Vendor-Specific Recommendations</strong></p>
                                                <table class="table table-sm table-hover mb-0">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>API Field</th>
                                                            <th>Data Type</th>
                                                            <th>Recommended</th>
                                                            <th>Confidence</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <template x-for="(rec, recIndex) in apiPreviewRecommendations" :key="recIndex">
                                                            <tr>
                                                                <td><code x-text="rec.api_field || rec.field" style="font-size: 11px;"></code></td>
                                                                <td>
                                                                    <span class="badge" 
                                                                          :class="getDataTypeBadgeClass(rec.dataType || rec.type)"
                                                                          x-text="rec.dataType || rec.type"></span>
                                                                </td>
                                                                <td><small x-text="(rec.librenms_table || rec.table) + '.' + (rec.librenms_field || rec.field)"></small></td>
                                                                <td>
                                                                    <div class="progress" style="height: 18px; width: 60px;">
                                                                        <div class="progress-bar" 
                                                                             :style="'width: ' + (rec.confidence * 100) + '%; background-color: ' + getConfidenceColor(rec.confidence)"
                                                                             :title="Math.round(rec.confidence * 100) + '%'">
                                                                            <small x-text="Math.round(rec.confidence * 100) + '%'" style="font-size: 9px;"></small>
                                                                        </div>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                        </template>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>

                                        {{-- Structure Tab Content --}}
                                        <div x-show="activePreviewTab === 'structure'" class="table-responsive">
                                            <table class="table table-sm mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Field</th>
                                                        <th>Type</th>
                                                        <th>Sample Value</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <template x-for="(field, name) in apiPreviewFields" :key="name">
                                                        <tr>
                                                            <td><code x-text="name" style="font-size: 11px;"></code></td>
                                                            <td>
                                                                <span class="badge badge-light" 
                                                                      x-text="getFieldType(field)"></span>
                                                            </td>
                                                            <td>
                                                                <small x-text="getFieldSample(field)"></small>
                                                            </td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>

                                        {{-- Sample Data Tab Content --}}
                                        <div x-show="activePreviewTab === 'sample'">
                                            <pre style="font-size: 11px; white-space: pre-wrap; word-wrap: break-word;"><code x-text="JSON.stringify(apiPreviewSample, null, 2)"></code></pre>
                                        </div>
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

{{-- DEVICE SELECTOR MODAL --}}
@include('settings.rest-api.templates.partials.device-selector-modal')

<script>
window.templateEditor = function() {
    return { init() { console.log('Template Editor Loaded'); } }
}

window.endpointManager = function() {
    return {
        endpoints: [],
        selectedEndpointIndex: null,
        selectedEndpoint: {},
        originalEndpoint: {},
        isDirty: false,
        previewLoading: false,
        previewSuccess: false,
        previewError: false,
        showDeviceSelectorModal: false,
        allDevices: [],
        filteredDevices: [],
        searchText: '',
        selectedDevice: null,
        selectedCredentialId: '',
        availableCredentials: [],
        selectedCredentialInfo: {},
        deviceSelectorError: '',
        deviceSelectorSuccess: '',
        apiPreviewData: null,
        apiPreviewRecommendations: [],
        apiPreviewFields: {},
        apiPreviewSample: {},
        previewFetched: false,
        activePreviewTab: 'recommendations',
        previewErrorMessage: '',

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
            
            // Listen for deviceSelected event from device selector modal
            const self = this;
            document.addEventListener('deviceSelected', function(event) {
                const { deviceId, credentialId } = event.detail;
                console.log('deviceSelected event received:', deviceId, credentialId);
                if (self && self.performPreview) {
                    self.performPreview(deviceId, credentialId);
                }
            });
        },

        selectEndpoint(index) {
            this.selectedEndpointIndex = index;
            this.selectedEndpoint = JSON.parse(JSON.stringify(this.endpoints[index]));
            this.originalEndpoint = JSON.parse(JSON.stringify(this.endpoints[index]));
            this.isDirty = false;
            this.previewLoading = false;
            this.previewSuccess = false;
            this.previewError = false;
            this.previewFetched = false;
            this.apiPreviewData = null;
            this.apiPreviewRecommendations = [];
            this.apiPreviewFields = {};
            this.apiPreviewSample = {};
            this.activePreviewTab = 'recommendations';
            this.previewErrorMessage = '';
        },

        // NEW METHOD: Fetch API Preview
        async fetchApiPreview() {
            if (!this.selectedEndpoint.path) {
                alert('Please enter an API path first');
                return;
            }

            // Show device selector modal
            let modal = document.getElementById('deviceSelectorModal');
            if (modal) {
                $(modal).modal('show');
            }
        },

        // NEW METHOD: Beautify JSON
        beautifyJson() {
            try {
                const json = JSON.parse(this.selectedEndpoint.metric_map_json);
                this.selectedEndpoint.metric_map_json = JSON.stringify(json, null, 2);
                this.checkForChanges();
            } catch (e) {
                alert('Invalid JSON: ' + e.message);
            }
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

                // Read response as text first, then parse as JSON
                const responseText = await res.text();
                let data;
                
                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('Failed to parse JSON response:', parseError);
                    console.error('Response text:', responseText.substring(0, 500));
                    throw new Error(`Invalid JSON response from server (HTTP ${res.status}): ${responseText.substring(0, 200)}`);
                }

                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}: ${data.message || data.error || 'Unknown error'}`);
                }

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
                    throw new Error(data.message || 'Unknown error saving endpoint');
                }
            } catch (err) {
                console.error('saveEndpointChanges error:', err);
                // Show validation errors if available
                if (this.validationErrors && Object.keys(this.validationErrors).length > 0) {
                    const errorList = Object.entries(this.validationErrors)
                        .map(([field, msgs]) => `${field}: ${msgs.join(', ')}`)
                        .join('\n');
                    alert('Validation errors:\n' + errorList);
                } else {
                    alert('Failed to save endpoint: ' + err.message);
                }
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

                // Read response as text first, then parse as JSON
                const responseText = await res.text();
                let data;
                
                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('Failed to parse JSON response:', parseError);
                    console.error('Response text:', responseText.substring(0, 500));
                    throw new Error(`Invalid JSON response from server (HTTP ${res.status}): ${responseText.substring(0, 200)}`);
                }

                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}: ${data.message || data.error || 'Unknown error'}`);
                }

                if (data.success) {
                    this.endpoints.splice(this.selectedEndpointIndex, 1);
                    this.selectedEndpointIndex = null;
                    this.selectedEndpoint = {};
                    alert('Endpoint deleted from template successfully!');
                } else {
                    throw new Error(data.message || 'Unknown error deleting endpoint');
                }
            } catch (err) {
                console.error('deleteEndpoint error:', err);
                alert('Failed to delete endpoint: ' + err.message);
            }
        },

        // DEVICE SELECTOR METHODS
        async loadDevices() {
            this.deviceSelectorError = '';
            try {
                const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                const res = await fetch('/api/rest-api/devices', {
                    headers: { 'X-CSRF-TOKEN': token }
                });
                const data = await res.json();
                this.allDevices = data.devices || [];
                this.filteredDevices = this.allDevices;
                console.log(`Loaded ${this.allDevices.length} devices`);
            } catch (err) {
                console.error('Failed to load devices:', err);
                this.deviceSelectorError = 'Failed to load devices: ' + err.message;
            }
        },

        async loadCredentials() {
            this.deviceSelectorError = '';
            try {
                const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                const res = await fetch('/api/rest-api/credentials', {
                    headers: { 'X-CSRF-TOKEN': token }
                });
                const data = await res.json();
                this.availableCredentials = data.credentials || [];
                console.log(`Loaded ${this.availableCredentials.length} credentials`);
            } catch (err) {
                console.error('Failed to load credentials:', err);
                this.deviceSelectorError = 'Failed to load credentials: ' + err.message;
            }
        },

        filterDevices() {
            const search = this.searchText.toLowerCase();
            this.filteredDevices = this.allDevices.filter(d => 
                d.hostname.toLowerCase().includes(search) || 
                d.ip.toLowerCase().includes(search)
            );
        },

        selectDevice(device) {
            this.selectedDevice = device;
            this.searchText = device.hostname;
        },

        onCredentialChange() {
            const cred = this.availableCredentials.find(c => c.id == this.selectedCredentialId);
            if (cred) {
                this.selectedCredentialInfo = cred;
            } else {
                this.selectedCredentialInfo = {};
            }
        },

        // Placeholder for device selector to interact with
        async performPreview(deviceId, credentialId) {
            this.previewLoading = true;
            this.previewError = false;
            this.deviceSelectorError = '';
            this.previewFetched = false;

            try {
                const templateId = {{ $template->id }};
                const connIdx = this.selectedEndpoint._connection_index || 0;
                const epIdx = this.selectedEndpoint._endpoint_index !== undefined ? this.selectedEndpoint._endpoint_index : 0;
                
                const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                // Use window.location.origin to ensure correct host
                // Try using the web route path instead of /api/
                const url = window.location.origin + '/api/rest-api/template-preview';
                console.log('Calling preview API at:', url);
                console.log('window.location.href:', window.location.href);

                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        template_id: templateId,
                        connection_index: connIdx,
                        endpoint_index: epIdx,
                        device_id: deviceId,
                        credential_id: credentialId
                    })
                });

                console.log('Response status:', res.status);
                console.log('Response headers:', res.headers);
                
                let data;
                const contentType = res.headers.get('content-type');
                console.log('Content-Type:', contentType);
                
                if (contentType && contentType.includes('application/json')) {
                    data = await res.json();
                    console.log('Response data:', data);
                } else {
                    const text = await res.text();
                    console.error('Non-JSON response received:', text.substring(0, 500));
                    throw new Error('Server returned non-JSON response (status ' + res.status + '). Check browser console for details.');
                }
                
                if (data.success) {
                    this.previewLoading = false;
                    this.previewSuccess = true;
                    this.previewFetched = true;
                    this.apiPreviewData = data.preview;
                    
                    // Validate and clean recommendations
                    this.apiPreviewRecommendations = this.sanitizeRecommendations(data.recommendations || []);
                    this.activePreviewTab = 'recommendations';
                    
                    // Extract fields from first item in response
                    const items = data.preview.items || data.preview.data || [data.preview];
                    const firstItem = items[0] || {};
                    this.apiPreviewFields = firstItem;
                    this.apiPreviewSample = firstItem;
                    
                    console.log('✓ Preview loaded:', {
                        itemCount: items.length,
                        recommendationCount: this.apiPreviewRecommendations.length,
                        fieldCount: Object.keys(firstItem).length
                    });
                    
                    $('#deviceSelectorModal').modal('hide');
                } else {
                    this.previewLoading = false;
                    this.previewError = true;
                    this.previewFetched = true;
                    this.previewErrorMessage = data.error || 'Unknown error';
                    this.deviceSelectorError = '✗ Error: ' + (data.error || 'Unknown error');
                }
            } catch (err) {
                console.error('Preview error:', err);
                this.previewLoading = false;
                this.previewError = true;
                this.previewFetched = true;
                this.previewErrorMessage = err.message;
                this.deviceSelectorError = '✗ Failed to fetch preview: ' + err.message;
            }
        },

        // Sanitize recommendations - remove duplicates and validate structure
        sanitizeRecommendations(recs) {
            if (!Array.isArray(recs)) {
                console.warn('Recommendations is not an array:', typeof recs);
                return [];
            }
            
            // Remove duplicates by creating a Map with api_field as key
            const seen = new Map();
            const cleaned = [];
            
            recs.forEach((rec, idx) => {
                if (!rec || typeof rec !== 'object') {
                    console.warn('Invalid recommendation at index', idx, rec);
                    return;
                }
                
                const key = rec.api_field || rec.field || `rec_${idx}`;
                
                if (seen.has(key)) {
                    console.warn('Duplicate recommendation key:', key);
                    return;
                }
                
                // Ensure all required properties exist
                const cleaned_rec = {
                    api_field: rec.api_field || rec.field || 'unknown',
                    librenms_table: rec.librenms_table || rec.table || 'unknown',
                    librenms_field: rec.librenms_field || rec.field || 'unknown',
                    confidence: typeof rec.confidence === 'number' ? rec.confidence : 0.5,
                    dataType: rec.dataType || rec.type || 'unknown',
                    reason: rec.reason || ''
                };
                
                seen.set(key, true);
                cleaned.push(cleaned_rec);
            });
            
            console.log('Cleaned recommendations:', cleaned.length, cleaned);
            return cleaned;
        },
        getDataTypeBadgeClass(type) {
            const typeMap = {
                'string': 'badge-success',
                'integer': 'badge-info',
                'float': 'badge-info',
                'double': 'badge-info',
                'boolean': 'badge-warning',
                'array': 'badge-danger',
                'object': 'badge-danger',
                'null': 'badge-secondary',
            };
            return typeMap[type] || 'badge-secondary';
        },

        // Helper method to get color for confidence score
        getConfidenceColor(confidence) {
            if (confidence >= 0.95) return '#28a745'; // green
            if (confidence >= 0.85) return '#17a2b8'; // info
            if (confidence >= 0.70) return '#ffc107'; // warning
            return '#dc3545'; // danger
        },

        // Helper to get field type
        getFieldType(field) {
            if (field === null) return 'null';
            if (Array.isArray(field)) return 'array';
            return typeof field;
        },

        // Helper to get sample value
        getFieldSample(field) {
            if (field === null) return 'null';
            if (Array.isArray(field)) return `[${field.length} items]`;
            if (typeof field === 'string') return field.length > 50 ? field.substring(0, 50) + '...' : field;
            if (typeof field === 'object') return '[object]';
            return String(field);
        },

        closeDeviceSelector() {
            this.showDeviceSelectorModal = false;
            this.resetDeviceSelector();
        },

        resetDeviceSelector() {
            this.searchText = '';
            this.selectedDevice = null;
            this.selectedCredentialId = '';
            this.selectedCredentialInfo = {};
            this.deviceSelectorError = '';
            this.filteredDevices = this.allDevices;
        },
    }
}
</script>
@endsection
