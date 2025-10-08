@extends('layouts.librenmsv1')

@section('title', 'Edit REST API Template')

@push('styles')
<style>
    /* Retained for Alpine/Modal functionality */
    [x-cloak] { display: none !important; }

    /* NEW: Enhanced Card Layout for Actions */
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
                    {{-- The form will now only handle the basic fields (name, vendor, description, resource_type) --}}
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
                                    {{-- Reusing the Vendor/Resource_Type block from _form.blade.php for clarity --}}
                                    {{-- NOTE: We are removing the vendor/resource_type fields from _form.blade.php and putting them here. --}}
                                    <div class="form-group">
                                        <label for="vendor">Vendor</label>
                                        <input type="text" name="vendor" id="vendor" class="form-control"
                                               value="{{ old('vendor', $template->vendor) }}">
                                    </div>
                                </div>
                            </div>

                            {{-- NEW: Resource Type (Moved from _form.blade.php for the PUT request) --}}
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
            {{-- We will use a separate form inside the modal for endpoints to simplify saving --}}
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
                    {{-- Dynamic content from the old Endpoints tab (Refactored) --}}
                    @php
                        $template_data_array = is_array($template->template_data)
                            ? $template->template_data
                            : (json_decode($template->template_data, true) ?? []);
                        $connections = $template_data_array['connections'] ?? [];
                    @endphp

                    @if (count($connections) > 0)
                        @foreach ($connections as $cIndex => $connection)
                            <div class="card mb-3">
                                <div class="card-header bg-primary-light text-dark">
                                    <h5 class="mb-0">
                                        <i class="fas fa-server"></i>
                                        Connection: {{ $connection['name'] ?? 'Unnamed Connection' }}
                                    </h5>
                                </div>

                                <div class="card-body">
                                    <p class="text-muted">Currently editing **{{ $connection['name'] ?? 'Primary Connection' }}** endpoints.</p>

                                    {{-- List of Existing Endpoints --}}
                                    <div class="list-group mb-4">
                                        @if (!empty($connection['endpoints']))
                                            @foreach ($connection['endpoints'] as $eIndex => $endpoint)
                                                <div class="list-group-item list-group-item-action">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div>
                                                            <h6 class="mb-1">
                                                                <span class="badge badge-primary">{{ strtoupper($endpoint['method'] ?? 'GET') }}</span>
                                                                {{ $endpoint['name'] ?? 'Unnamed Endpoint' }}
                                                            </h6>
                                                            <small class="text-muted">{{ $endpoint['path'] ?? '' }}</small>
                                                        </div>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                                                @click="toggleEndpoint('{{ $cIndex }}-{{ $eIndex }}')">
                                                            <i class="fas" :class="openEndpoint === '{{ $cIndex }}-{{ $eIndex }}' ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                                                            <span x-text="openEndpoint === '{{ $cIndex }}-{{ $eIndex }}' ? 'Close Edit' : 'Edit Endpoint'"></span>
                                                        </button>
                                                    </div>

                                                    <div x-show="openEndpoint === '{{ $cIndex }}-{{ $eIndex }}'"
                                                        x-cloak
                                                        x-transition:enter="transition ease-out duration-200"
                                                        x-transition:enter-start="opacity-0 transform scale-95"
                                                        x-transition:enter-end="opacity-100 transform scale-100">
                                                        <hr class="mt-2 mb-3">
                                                        {{-- The form partial is now inside the modal --}}
                                                        @include('settings.rest-api.templates.partials.endpoint-form', [
                                                            'connectionIndex' => $cIndex,
                                                            'endpointIndex' => $eIndex,
                                                            'endpoint' => $endpoint,
                                                            'template' => $template // Pass template for context
                                                        ])
                                                    </div>
                                                </div>
                                            @endforeach
                                        @else
                                            <div class="alert alert-warning text-center">
                                                <i class="fas fa-info-circle"></i> No endpoints defined. Use the button below to add your first one.
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Add New Endpoint Section --}}
                                    <div class="card border-success">
                                        <div class="card-header bg-success text-white p-2">
                                            <h6 class="mb-0">
                                                <i class="fas fa-plus"></i> Add New Endpoint
                                                <button type="button" class="btn btn-sm btn-outline-light float-right" @click="addNewEndpoint()">
                                                    <i class="fas fa-plus-circle"></i> Add Endpoint Form
                                                </button>
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            {{-- Container for dynamically added endpoint forms --}}
                                            <div id="new-endpoints-container">
                                                {{-- Dynamic forms will be appended here --}}
                                            </div>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        @endforeach
                    @else
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i> No connections defined in template. Please configure your connection in the **Connection Settings** modal first.
                        </div>
                    @endif

                    {{-- Hidden field to ensure only endpoint data is processed --}}
                    <input type="hidden" name="action_type" value="update_endpoints_only">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    {{-- A full save is needed to persist all endpoint changes --}}
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save All Endpoints</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- HIDDEN TEMPLATE: The actual form structure to be cloned by JavaScript --}}
<template id="new-endpoint-template">
    @php
    $js_placeholder_index = '__NEW_ENDPOINT_INDEX__';
    $js_connection_index = 0; // Assuming only one connection is edited at a time for simplicity
    @endphp
    <div class="card mb-3 new-endpoint-item border-warning">
        <div class="card-header bg-warning p-2">
            <h6 class="mb-0">New Endpoint <small class="text-danger">(Unsaved)</small>
                <button type="button" class="close text-danger remove-endpoint float-right" aria-label="Remove">
                    <span aria-hidden="true">&times;</span>
                </button>
            </h6>
        </div>
        <div class="card-body">
            {{-- Re-use the existing partial with placeholders --}}
            @include('settings.rest-api.templates.partials.endpoint-form', [
                'connectionIndex' => $js_connection_index,
                'endpointIndex' => $js_placeholder_index,
                'endpoint' => [], // Empty array for initial state
                'template' => $template // Pass template for context
            ])
        </div>
    </div>
</template>

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
        // We no longer need activeTab here, as the tabs are replaced by modals
        openEndpoint: null,
        templateData: @json($template->template_data),

        init() {
            // Optional: Re-run endpoint parsing logic if needed for external JS/Alpine components
        },

        // This is now used by the Endpoints Modal to manage existing endpoint forms
        toggleEndpoint(endpointId) {
            if (this.openEndpoint === endpointId) {
                this.openEndpoint = null;
            } else {
                this.openEndpoint = endpointId;
            }
        },
    }
}

// NEW Alpine Data for Endpoint Management within the Modal
function endpointManager() {
    return {
        openEndpoint: null,
        newEndpointCount: 0,

        toggleEndpoint(endpointId) {
            if (this.openEndpoint === endpointId) {
                this.openEndpoint = null;
            } else {
                this.openEndpoint = endpointId;
            }
        },

        addNewEndpoint() {
            const template = document.getElementById('new-endpoint-template');
            const container = document.getElementById('new-endpoints-container');

            if (!template || !container) return;

            const clone = template.content.cloneNode(true);
            const newIndex = 'new_' + this.newEndpointCount;

            // Find all inputs and update their names with the unique index
            clone.querySelectorAll('*').forEach(el => {
                if (el.name) {
                    // Update index placeholder for both existing and new endpoints
                    el.name = el.name.replace('__NEW_ENDPOINT_INDEX__', newIndex);
                }
                if (el.id) {
                    el.id = el.id.replace('__NEW_ENDPOINT_INDEX__', newIndex);
                }
                // Update the hidden metric map name for the controller logic to find it
                if (el.tagName === 'TEXTAREA' && el.id === 'metric_map_json') {
                    // The metric_map needs to be initialized as an empty object for the form.
                    el.name = `template_data[connections][0][endpoints][${newIndex}][metric_map]`;
                    el.value = JSON.stringify({}, null, 4);
                }
            });

            // Add listener to remove the endpoint form
            const removeButton = clone.querySelector('.remove-endpoint');
            removeButton.addEventListener('click', (e) => {
                e.preventDefault();
                e.target.closest('.new-endpoint-item').remove();
            });

            container.appendChild(clone);
            this.newEndpointCount++;
        }
    }
}
</script>
@endsection