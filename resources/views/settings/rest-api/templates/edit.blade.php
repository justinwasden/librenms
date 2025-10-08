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
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content" x-data="endpointManager()" x-init="init()">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-tasks"></i> Manage Endpoints</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span>&times;</span>
                </button>
            </div>

            <div class="modal-body">
                <div class="row">
                    {{-- Left pane: endpoint list --}}
                    <div class="col-md-3 border-right">
                        <h6 class="mb-3 text-primary"><i class="fas fa-list-ul"></i> Existing Endpoints</h6>

                        @php
                            $template_data_array = is_array($template->template_data)
                                ? $template->template_data
                                : (json_decode($template->template_data, true) ?? []);
                            $connections = $template_data_array['connections'] ?? [];
                            $cIndex = 0;
                        @endphp

                        @if (isset($connections[$cIndex]))
                            <div class="list-group" id="endpointList">
                                @foreach ($connections[$cIndex]['endpoints'] ?? [] as $eIndex => $endpoint)
                                    <button type="button"
                                            class="list-group-item list-group-item-action"
                                            x-on:click="selectEndpoint({{ $cIndex }}, {{ $eIndex }})"
                                            :class="{'active': selectedEndpointIndex === {{ $eIndex }}}">
                                        {{ $endpoint['name'] ?? 'Endpoint '.$eIndex }}
                                    </button>
                                @endforeach
                            </div>
                        @else
                            <p class="text-muted">No endpoints configured yet.</p>
                        @endif

                        <div class="mt-3">
                            <button type="button" class="btn btn-success btn-block" x-on:click="addNewEndpoint()">
                                <i class="fas fa-plus"></i> Add New Endpoint
                            </button>
                        </div>
                    </div>

                    {{-- Right pane: endpoint details --}}
                    <div class="col-md-9">
                        <template x-if="selectedEndpointIndex !== null">
                            <div>
                                <div class="form-group">
                                    <label for="endpoint_name">Endpoint Name</label>
                                    <input type="text" class="form-control" x-model="selectedEndpoint.name">
                                </div>

                                <div class="form-group">
                                    <label for="endpoint_path">Path</label>
                                    <input type="text" class="form-control" x-model="selectedEndpoint.path">
                                </div>

                                <div class="form-group">
                                    <label for="endpoint_method">HTTP Method</label>
                                    <select class="form-control" x-model="selectedEndpoint.method">
                                        <option value="GET">GET</option>
                                        <option value="POST">POST</option>
                                        <option value="PUT">PUT</option>
                                        <option value="DELETE">DELETE</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="metric_map">Metric Mapping (JSON)</label>
                                    <textarea class="form-control" rows="10" x-model="selectedEndpoint.metric_map_json"></textarea>
                                </div>

                                <div class="form-group text-right">
                                    <button type="button" class="btn btn-primary" x-on:click="saveEndpointChanges()">
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
                {{-- Save All button removed --}}
            </div>
        </div>
    </div>
</div>

<script>
function endpointManager() {
    return {
        endpoints: @json($connections[$cIndex]['endpoints'] ?? []),
        selectedEndpointIndex: null,
        selectedEndpoint: {},

        init() {
            // prepare endpoints
            this.endpoints.forEach((ep, idx) => {
                this.endpoints[idx].metric_map_json =
                    typeof ep.metric_map === 'string'
                        ? ep.metric_map
                        : JSON.stringify(ep.metric_map ?? {}, null, 4);
            });
        },

        selectEndpoint(cIndex, eIndex) {
            this.selectedEndpointIndex = eIndex;
            this.selectedEndpoint = {...this.endpoints[eIndex]}; // clone to allow editing
        },

        addNewEndpoint() {
            const newEp = {
                name: 'New Endpoint',
                path: '',
                method: 'GET',
                metric_map: {},
                metric_map_json: '{}'
            };
            this.endpoints.push(newEp);
            this.selectedEndpointIndex = this.endpoints.length - 1;
            this.selectedEndpoint = {...newEp};
        },

        saveEndpointChanges() {
            if (this.selectedEndpointIndex === null) return;

            // parse metric_map_json into JSON
            try {
                this.selectedEndpoint.metric_map = JSON.parse(this.selectedEndpoint.metric_map_json);
            } catch(e) {
                alert('Metric Mapping JSON is invalid. Please fix before saving.');
                return;
            }

            // update original array
            this.endpoints[this.selectedEndpointIndex] = {...this.selectedEndpoint};

            // OPTIONAL: send AJAX to server here
            console.log('Endpoint saved:', this.selectedEndpoint);
        }
    };
}
</script>
@endsection
