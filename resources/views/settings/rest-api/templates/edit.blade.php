@extends('layouts.librenmsv1')

@section('title', 'Edit REST API Template')

@push('styles')
<style>
    [x-cloak] { display: none !important; }
    .tab-pane { display: none; }
    .tab-pane.active { display: block; }

    /* Enhanced tab styling */
    .nav-tabs .nav-link {
        color: #495057;
        border: 1px solid transparent;
        transition: all 0.2s ease;
    }

    .nav-tabs .nav-link:hover {
        background-color: #f8f9fa;
        border-color: #dee2e6 #dee2e6 transparent;
    }

    .nav-tabs .nav-link.active {
        color: #007bff;
        background-color: #fff;
        border-color: #dee2e6 #dee2e6 #fff;
        border-bottom-color: transparent;
        font-weight: 600;
    }

    .nav-tabs .nav-link i {
        margin-right: 6px;
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

                            <div class="form-group mb-4">
                                <label for="description">Description</label>
                                <textarea name="description" id="description" class="form-control" rows="2">{{ old('description', $template->description) }}</textarea>
                            </div>

                            {{-- Separator --}}
                            <hr class="mb-4" style="border-top: 2px solid #dee2e6;">

                            {{-- Tabs Navigation --}}
                            <ul class="nav nav-tabs mb-3" role="tablist" style="border-bottom: 2px solid #dee2e6;">
                                <li class="nav-item">
                                    <a class="nav-link"
                                       :class="{ 'active': activeTab === 'connection' }"
                                       @click.prevent="activeTab = 'connection'"
                                       href="#"
                                       style="font-weight: 500; font-size: 15px; padding: 12px 20px;">
                                        <i class="fas fa-plug"></i> Connection
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link"
                                       :class="{ 'active': activeTab === 'endpoints' }"
                                       @click.prevent="activeTab = 'endpoints'"
                                       href="#"
                                       style="font-weight: 500; font-size: 15px; padding: 12px 20px;">
                                        <i class="fas fa-list"></i> Endpoints
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link"
                                       :class="{ 'active': activeTab === 'preview' }"
                                       @click.prevent="activeTab = 'preview'"
                                       href="#"
                                       style="font-weight: 500; font-size: 15px; padding: 12px 20px;">
                                        <i class="fas fa-eye"></i> Preview
                                    </a>
                                </li>
                            </ul>

                            {{-- Tab Content --}}
                            <div class="tab-content">
                                {{-- Connection Tab --}}
                                <div class="tab-pane" :class="{ 'active': activeTab === 'connection' }">
                                    @include('settings.rest-api.templates.partials.connection', ['template' => $template])
                                </div>

                                {{-- Endpoints Tab --}}
                                <div class="tab-pane" :class="{ 'active': activeTab === 'endpoints' }">
                                    @php
                                        $template_data_array = is_array($template->template_data)
                                            ? $template->template_data
                                            : (json_decode($template->template_data, true) ?? []);
                                        $connections = $template_data_array['connections'] ?? [];
                                    @endphp

                                    @if (count($connections) > 0)
                                        @foreach ($connections as $cIndex => $connection)
                                            <div class="card mb-3">
                                                <div class="card-header bg-info text-white">
                                                    <h5 class="mb-0">
                                                        <i class="fas fa-server"></i>
                                                        {{ $connection['name'] ?? 'Unnamed Connection' }}
                                                        <small class="ml-2">({{ $connection['base_url'] ?? 'No URL' }})</small>
                                                    </h5>
                                                </div>

                                                <div class="card-body">
                                                    @if (!empty($connection['endpoints']))
                                                        <div class="list-group">
                                                            @foreach ($connection['endpoints'] as $eIndex => $endpoint)
                                                                <div class="list-group-item">
                                                                    <div class="d-flex justify-content-between align-items-center mb-2">
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
                                                                            <span x-text="openEndpoint === '{{ $cIndex }}-{{ $eIndex }}' ? 'Close' : 'Edit'"></span>
                                                                        </button>
                                                                    </div>

                                                                    <div x-show="openEndpoint === '{{ $cIndex }}-{{ $eIndex }}'"
                                                                         x-cloak
                                                                         x-transition:enter="transition ease-out duration-200"
                                                                         x-transition:enter-start="opacity-0 transform scale-95"
                                                                         x-transition:enter-end="opacity-100 transform scale-100">
                                                                        <hr>
                                                                        @include('settings.rest-api.templates.partials.endpoint-form', [
                                                                            'connectionIndex' => $cIndex,
                                                                            'endpointIndex' => $eIndex,
                                                                            'endpoint' => $endpoint,
                                                                        ])
                                                                    </div>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @else
                                                        <p class="text-muted text-center py-3">
                                                            <i class="fas fa-info-circle"></i> No endpoints defined for this connection.
                                                        </p>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    @else
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle"></i> No connections defined in template. Please add connection data in the Connection tab.
                                        </div>
                                    @endif

                                    {{-- Start NEW ENDPOINT ADDITION Block --}}
																				<div class="card mb-3 mt-4" x-show="templateData.connections && templateData.connections.length > 0">
																				    <div class="card-header bg-success text-white p-2">
																				        <h6 class="mb-0">
																				            <i class="fas fa-plus"></i> Add New Endpoint
																				        </h6>
																				    </div>
																				    <div class="card-body">
																				        <div class="alert alert-info py-2">
																				            Endpoints will be added to the <strong>{{ $connections[0]['name'] ?? 'Primary Connection' }}</strong>.
																				        </div>

																				        <button type="button" class="btn btn-success btn-sm mb-3" @click="addNewEndpoint()">
																				            <i class="fas fa-plus-circle"></i> Add Endpoint Form
																				        </button>

																				        {{-- Container for dynamically added endpoint forms --}}
																				        <div id="new-endpoints-container">
																				            {{-- Dynamic forms will be appended here --}}
																				        </div>
																				    </div>
																				</div>
																				{{-- End NEW ENDPOINT ADDITION Block --}}

																				{{-- HIDDEN TEMPLATE: The actual form structure to be cloned by JavaScript --}}
																				<template id="new-endpoint-template">
																				    @php
																				    // We use a non-numeric string placeholder for JavaScript to replace
																				    $js_placeholder_index = '__NEW_ENDPOINT_INDEX__';
																				    $js_connection_index = 0; // Assuming only one connection is edited at a time for simplicity
																				    @endphp
																				    <div class="card mb-3 new-endpoint-item">
																				        <div class="card-header bg-light-gray">
																				            <h6 class="mb-0">New Endpoint <small class="text-danger">(Unsaved)</small>
																				                <button type="button" class="close text-danger remove-endpoint float-right" aria-label="Remove">
																				                    <span>&times;</span>
																				                </button>
																				            </h6>
																				        </div>
																				        <div class="card-body">
																				            {{-- Re-use the existing partial with placeholders --}}
																				            @include('settings.rest-api.templates.partials.endpoint-form', [
																				                'connectionIndex' => $js_connection_index,
																				                'endpointIndex' => $js_placeholder_index,
																				                'endpoint' => [], // Empty array for initial state
																				            ])
																				        </div>
																				    </div>
																				</template>
                                </div>

                                {{-- Preview Tab --}}
                                <div class="tab-pane" :class="{ 'active': activeTab === 'preview' }">
                                    @include('settings.rest-api.templates.partials.preview', ['template' => $template])
                                </div>
                            </div>
                        </div>

                        <div class="card-footer">
                            <div class="d-flex justify-content-between">
                                <a href="{{ route('settings.rest-api.templates.index') }}" class="btn btn-default">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function templateEditor() {
    return {
        activeTab: 'connection',
        openEndpoint: null,
        // New reactive property for connections data from the template
        templateData: @json($template->template_data),
        // Counter for generating unique indexes for new endpoints
        newEndpointCount: 0,

        init() {
            // Initialize
            console.log('Template editor initialized');
        },

        toggleEndpoint(endpointId) {
            if (this.openEndpoint === endpointId) {
                this.openEndpoint = null;
            } else {
                this.openEndpoint = endpointId;
            }
        },

        // NEW METHOD: Clone the template form and insert it
        addNewEndpoint() {
            const template = document.getElementById('new-endpoint-template');
            const container = document.getElementById('new-endpoints-container');

            if (!template || !container) return;

            const clone = template.content.cloneNode(true);
            const newIndex = 'new_' + this.newEndpointCount;

            // Find all inputs and update their names with the unique index
            clone.querySelectorAll('*').forEach(el => {
                if (el.name) {
                    el.name = el.name.replace('__NEW_ENDPOINT_INDEX__', newIndex);
                }
                if (el.id) {
                    el.id = el.id.replace('__NEW_ENDPOINT_INDEX__', newIndex);
                }
                // Update the hidden metric map name for the controller logic to find it
                if (el.tagName === 'TEXTAREA' && el.name === 'metric_map_json') {
                    el.name = `template_data[connections][0][endpoints][${newIndex}][metric_map]`;
                    // The metric_map needs to be initialized as an empty object for the form.
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
