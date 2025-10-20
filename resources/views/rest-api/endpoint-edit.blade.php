{{-- resources/views/rest-api/endpoint-edit.blade.php --}}
{{-- Integrated REST API Endpoint Editor with new mapping UI --}}

@extends('layouts.librenms')

@section('title', 'Configure REST API Endpoint - ' . ($endpoint->name ?? 'New'))

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <h1>
                <i class="fa fa-cogs"></i> 
                REST API Endpoint Configuration
                @if($endpoint->exists)
                    <small class="text-muted">{{ $endpoint->name }}</small>
                @endif
            </h1>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <div class="row mt-3">
        <div class="col-md-12">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="endpoint-config-tab" data-toggle="tab" href="#endpoint-config" role="tab">
                        <i class="fa fa-wrench"></i> Endpoint Configuration
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="api-preview-tab" data-toggle="tab" href="#api-preview" role="tab">
                        <i class="fa fa-database"></i> API Response
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="mapping-tab" data-toggle="tab" href="#mapping" role="tab">
                        <i class="fa fa-arrows-h"></i> Field Mapping
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="summary-tab" data-toggle="tab" href="#summary" role="tab">
                        <i class="fa fa-check-circle"></i> Summary
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <div class="tab-content mt-3">
        <!-- Tab 1: Endpoint Configuration -->
        <div id="endpoint-config" class="tab-pane fade show active" role="tabpanel">
            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">
                                <i class="fa fa-link"></i> Endpoint Details
                            </h5>
                        </div>
                        <div class="card-body">
                            @if($errors->any())
                                <div class="alert alert-danger">
                                    <strong>Validation Errors:</strong>
                                    <ul class="mb-0">
                                        @foreach($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <form method="POST" action="{{ route('rest-api.endpoints.update', $endpoint) }}">
                                @csrf
                                @method('PUT')

                                <div class="form-group">
                                    <label for="name">Endpoint Name</label>
                                    <input type="text" class="form-control @error('name') is-invalid @enderror" 
                                           id="name" name="name" value="{{ old('name', $endpoint->name) }}"
                                           placeholder="e.g., Volumes, Network Interfaces, Controllers">
                                    @error('name') <span class="invalid-feedback">{{ $message }}</span> @enderror
                                </div>

                                <div class="form-group">
                                    <label for="path">API Path</label>
                                    <input type="text" class="form-control @error('path') is-invalid @enderror" 
                                           id="path" name="path" value="{{ old('path', $endpoint->path) }}"
                                           placeholder="e.g., /api/2.26/volumes">
                                    @error('path') <span class="invalid-feedback">{{ $message }}</span> @enderror
                                </div>

                                <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label for="method">HTTP Method</label>
                                        <select class="form-control @error('method') is-invalid @enderror" 
                                                id="method" name="method">
                                            <option value="GET" {{ old('method', $endpoint->method ?? 'GET') === 'GET' ? 'selected' : '' }}>GET</option>
                                            <option value="POST" {{ old('method', $endpoint->method) === 'POST' ? 'selected' : '' }}>POST</option>
                                        </select>
                                        @error('method') <span class="invalid-feedback">{{ $message }}</span> @enderror
                                    </div>

                                    <div class="form-group col-md-6">
                                        <label for="resource_type">Resource Type</label>
                                        <select class="form-control @error('resource_type') is-invalid @enderror" 
                                                id="resource_type" name="resource_type">
                                            <option value="">-- Select Type --</option>
                                            <option value="volume" {{ old('resource_type', $endpoint->resource_type) === 'volume' ? 'selected' : '' }}>Volume/Storage</option>
                                            <option value="network-interface" {{ old('resource_type', $endpoint->resource_type) === 'network-interface' ? 'selected' : '' }}>Network Interface</option>
                                            <option value="sensor" {{ old('resource_type', $endpoint->resource_type) === 'sensor' ? 'selected' : '' }}>Sensor</option>
                                            <option value="device" {{ old('resource_type', $endpoint->resource_type) === 'device' ? 'selected' : '' }}>Device</option>
                                            <option value="custom" {{ old('resource_type', $endpoint->resource_type) === 'custom' ? 'selected' : '' }}>Custom</option>
                                        </select>
                                        @error('resource_type') <span class="invalid-feedback">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                <hr>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fa fa-save"></i> Save Configuration
                                </button>
                                <a href="{{ route('rest-api.connections.show', $connection) }}" class="btn btn-secondary">
                                    <i class="fa fa-times"></i> Cancel
                                </a>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0">
                                <i class="fa fa-info-circle"></i> Information
                            </h5>
                        </div>
                        <div class="card-body">
                            <dl class="small">
                                <dt>Connection:</dt>
                                <dd>{{ $connection->name }}</dd>

                                <dt>Device:</dt>
                                <dd>{{ $device->hostname }} ({{ $device->os }})</dd>

                                <dt>Vendor Mapper:</dt>
                                <dd>
                                    @if($vendorMapper)
                                        <span class="badge badge-success">
                                            {{ class_basename(get_class($vendorMapper)) }}
                                        </span>
                                    @else
                                        <span class="badge badge-secondary">Generic</span>
                                    @endif
                                </dd>

                                <dt>Base URL:</dt>
                                <dd><small><code>{{ $connection->base_url }}</code></small></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 2: API Response Preview -->
        <div id="api-preview" class="tab-pane fade" role="tabpanel">
            @if($apiError)
                <div class="alert alert-danger">
                    <strong><i class="fa fa-exclamation-triangle"></i> Error fetching API response:</strong>
                    <p class="mb-0">{{ $apiError }}</p>
                </div>
            @else
                @include('rest-api.mapping.preview-api-response', [
                    'apiResponse' => $apiResponse,
                    'endpoint' => $endpoint,
                    'vendor' => $device->os,
                ])
            @endif
        </div>

        <!-- Tab 3: Field Mapping -->
        <div id="mapping" class="tab-pane fade" role="tabpanel">
            @if($apiError)
                <div class="alert alert-warning">
                    <i class="fa fa-exclamation-triangle"></i> 
                    Cannot show field mapper - unable to fetch API response. 
                    Fix the endpoint configuration first.
                </div>
            @else
                @if(!empty($recommendations))
                    @include('rest-api.mapping.recommended-mappings', [
                        'recommendations' => $recommendations,
                        'endpoint' => $endpoint,
                        'vendor' => $device->os,
                    ])
                    <hr>
                @endif

                @include('rest-api.mapping.field-mapper', [
                    'endpoint' => $endpoint,
                    'apiResponse' => $apiResponse,
                    'vendor' => $device->os,
                    'existingMappings' => $existingMappings ?? [],
                ])
            @endif
        </div>

        <!-- Tab 4: Summary -->
        <div id="summary" class="tab-pane fade" role="tabpanel">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">
                        <i class="fa fa-check-circle"></i> Configuration Summary
                    </h5>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">Endpoint Name:</dt>
                        <dd class="col-sm-8"><strong>{{ $endpoint->name }}</strong></dd>

                        <dt class="col-sm-4">API Path:</dt>
                        <dd class="col-sm-8"><code>{{ $endpoint->path }}</code></dd>

                        <dt class="col-sm-4">Resource Type:</dt>
                        <dd class="col-sm-8"><span class="badge badge-info">{{ $endpoint->resource_type }}</span></dd>

                        <dt class="col-sm-4">Vendor:</dt>
                        <dd class="col-sm-8">{{ $device->os }}</dd>

                        <dt class="col-sm-4">Vendor Mapper:</dt>
                        <dd class="col-sm-8">
                            @if($vendorMapper)
                                <span class="badge badge-success">
                                    {{ class_basename(get_class($vendorMapper)) }}
                                </span>
                            @else
                                <span class="badge badge-secondary">Generic</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4">API Response Status:</dt>
                        <dd class="col-sm-8">
                            @if($apiResponse)
                                <span class="badge badge-success">
                                    <i class="fa fa-check"></i> Available
                                </span>
                            @else
                                <span class="badge badge-danger">
                                    <i class="fa fa-times"></i> Error
                                </span>
                            @endif
                        </dd>

                        <dt class="col-sm-4">Mappings Configured:</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-info">{{ count($existingMappings ?? []) }}</span>
                        </dd>
                    </dl>

                    <hr>

                    <div class="alert alert-info">
                        <strong><i class="fa fa-lightbulb"></i> Next Steps:</strong>
                        <ol class="mb-0 mt-2">
                            <li>Review the API Response in the "API Response" tab</li>
                            <li>Check the recommended field mappings in the "Field Mapping" tab</li>
                            <li>Configure your mappings or apply recommendations</li>
                            <li>Save your configuration</li>
                            <li>Run discovery or polling to test</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Tab navigation helper
document.querySelectorAll('[data-toggle="tab"]').forEach(tab => {
    tab.addEventListener('click', function() {
        // Smooth tab switching
        document.querySelectorAll('.tab-pane').forEach(pane => {
            pane.classList.remove('show', 'active');
        });
        const target = this.getAttribute('href');
        document.querySelector(target).classList.add('show', 'active');
    });
});
</script>
@endsection
