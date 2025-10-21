@extends('layouts.librenms')

@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-8">
            <h2>REST API Configuration - {{ $device->hostname }}</h2>

            @if ($errors->any())
                <div class="alert alert-danger">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('devices.rest-api.update', $device) }}" method="POST" class="card">
                @csrf
                @method('PUT')

                <div class="card-body">
                    <!-- Template Selection -->
                    <div class="form-group mb-3">
                        <label for="template_id" class="form-label">REST API Template</label>
                        <select name="template_id" id="template_id" class="form-control" required>
                            <option value="">-- Select Template --</option>
                            @foreach($templates as $template)
                                <option value="{{ $template->id }}" 
                                    {{ $deviceTemplate?->template_id == $template->id ? 'selected' : '' }}>
                                    {{ $template->name }} ({{ $template->vendor }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Mapper Selection -->
                    <div class="form-group mb-3">
                        <label for="mapper_name" class="form-label">REST API Mapper</label>
                        <select name="mapper_name" id="mapper_name" class="form-control">
                            <option value="">
                                -- Auto-Detect (Current: {{ $deviceTemplate?->mapper_source ?? 'none' }}) --
                            </option>

                            <!-- Vendor Mappers -->
                            @if($availableMappers)
                                <optgroup label="Vendor Mappers">
                                    @foreach($availableMappers as $mapper)
                                        @if($mapper['type'] === 'vendor')
                                            <option value="{{ $mapper['name'] }}"
                                                data-type="vendor"
                                                data-version="{{ $mapper['version'] }}"
                                                {{ $deviceTemplate?->mapper_name === $mapper['name'] ? 'selected' : '' }}>
                                                {{ $mapper['name'] }} (v{{ $mapper['version'] }})
                                            </option>
                                        @endif
                                    @endforeach
                                </optgroup>

                                <!-- Custom Mappers -->
                                <optgroup label="Custom Mappers">
                                    @foreach($availableMappers as $mapper)
                                        @if($mapper['type'] === 'custom')
                                            <option value="{{ $mapper['name'] }}"
                                                data-type="custom"
                                                {{ $deviceTemplate?->mapper_name === $mapper['name'] ? 'selected' : '' }}>
                                                {{ $mapper['name'] }}
                                            </option>
                                        @endif
                                    @endforeach
                                </optgroup>
                            @endif
                        </select>
                        <small class="form-text text-muted d-block mt-2">
                            @if($deviceTemplate?->mapper_source === 'auto_detected')
                                <strong>Auto-detected from device OS:</strong> {{ $device->os }}
                            @elseif($deviceTemplate?->mapper_source === 'user_selected')
                                <strong>User selected:</strong> {{ $deviceTemplate->mapper_name }}
                            @elseif($deviceTemplate?->mapper_source === 'custom_device')
                                <strong>Custom device mapping:</strong> {{ $deviceTemplate->custom_mapping_name }}
                            @else
                                Leave blank to auto-detect from device OS
                            @endif
                        </small>
                    </div>

                    <!-- Available Endpoints -->
                    <div class="form-group mb-3">
                        <label class="form-label">Available Endpoints</label>
                        <div id="endpoints-container" class="list-group">
                            <p class="text-muted">Select a mapper above to see available endpoints</p>
                        </div>
                        <small class="form-text text-muted d-block mt-2">
                            Endpoints will be discovered from the selected mapper or template
                        </small>
                    </div>

                    <!-- Credential Configuration -->
                    <div class="form-group mb-3">
                        <label for="credential_id" class="form-label">REST API Credential</label>
                        <select name="credential_id" id="credential_id" class="form-control">
                            <option value="">-- Select Credential --</option>
                            @foreach($credentials as $credential)
                                <option value="{{ $credential->id }}"
                                    {{ $deviceTemplate?->credential_id == $credential->id ? 'selected' : '' }}>
                                    {{ $credential->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Test Connection -->
                    <div class="form-group mb-3">
                        <button type="button" class="btn btn-warning" id="test-connection-btn">
                            <i class="fas fa-plug"></i> Test Connection
                        </button>
                        <div id="test-results" style="display: none; margin-top: 10px;"></div>
                    </div>
                </div>

                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Configuration
                    </button>
                    <a href="{{ route('devices.show', $device) }}" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Load endpoints when mapper changes
    $('#mapper_name').on('change', function() {
        var mapperName = $(this).val();
        
        if (!mapperName) {
            $('#endpoints-container').html(
                '<p class="text-muted">Select a mapper to see available endpoints</p>'
            );
            return;
        }

        // Fetch endpoints for selected mapper
        $.ajax({
            url: "{{ route('api.rest-api.mapper-endpoints', '') }}/" + encodeURIComponent(mapperName),
            type: 'GET',
            success: function(endpoints) {
                var html = '';
                
                if (Array.isArray(endpoints) && endpoints.length > 0) {
                    endpoints.forEach(function(endpoint) {
                        html += '<div class="list-group-item">' +
                                '  <strong>' + endpoint + '</strong>' +
                                '</div>';
                    });
                } else {
                    html = '<p class="text-muted">No endpoints available for this mapper</p>';
                }

                $('#endpoints-container').html(html);
            },
            error: function() {
                $('#endpoints-container').html(
                    '<div class="alert alert-danger">Error loading endpoints</div>'
                );
            }
        });
    });

    // Test connection
    $('#test-connection-btn').on('click', function() {
        var btn = $(this);
        btn.prop('disabled', true);
        
        $.ajax({
            url: "{{ route('devices.rest-api.test', $device) }}",
            type: 'POST',
            data: {
                _token: '{{ csrf_token() }}',
                mapper_name: $('#mapper_name').val(),
                credential_id: $('#credential_id').val(),
                template_id: $('#template_id').val()
            },
            success: function(response) {
                var resultsDiv = $('#test-results');
                resultsDiv.html(
                    '<div class="alert alert-success">' +
                    '  <strong>✓ Connection Successful</strong>' +
                    '  <p>Endpoint: ' + response.endpoint + '</p>' +
                    '  <p>Mapper: ' + response.mapper + '</p>' +
                    '</div>'
                ).show();
            },
            error: function(xhr) {
                var error = xhr.responseJSON?.message || 'Connection failed';
                $('#test-results').html(
                    '<div class="alert alert-danger">' +
                    '  <strong>✗ Connection Failed</strong>' +
                    '  <p>' + error + '</p>' +
                    '</div>'
                ).show();
            },
            complete: function() {
                btn.prop('disabled', false);
            }
        });
    });

    // Initialize with current mapper
    $('#mapper_name').trigger('change');
});
</script>
@endsection
