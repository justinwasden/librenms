@props(['endpoint', 'apiResponse', 'vendor', 'existingMappings' => []])

<div class="card">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="fa fa-arrows-h"></i> Field Mapping Configuration
        </h5>
        <small>Map REST API fields to LibreNMS database tables and fields</small>
    </div>
    <div class="card-body">
        <form id="mapping-form" method="POST" action="{{ route('rest-api.mappings.store') }}">
            @csrf

            <input type="hidden" name="endpoint_id" value="{{ $endpoint->id ?? '' }}">

            <div class="alert alert-info mb-3">
                <i class="fa fa-info-circle"></i> 
                <strong>How to use:</strong>
                <ol class="mb-0 mt-2">
                    <li>Select a database table for each API field</li>
                    <li>Choose the target field in that table</li>
                    <li>Review the compatibility check for data type compatibility</li>
                    <li>Save your mappings</li>
                </ol>
            </div>

            @php
                $items = $apiResponse['items'] ?? $apiResponse['data'] ?? [$apiResponse];
                $sample = reset($items);
            @endphp

            <div class="table-responsive">
                <table class="table table-hover" id="mapping-table">
                    <thead class="table-light">
                        <tr>
                            <th>API Field</th>
                            <th>Data Type</th>
                            <th>Sample Value</th>
                            <th>Target Table</th>
                            <th>Target Field</th>
                            <th style="width: 120px;">Compatibility</th>
                            <th style="width: 80px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if(is_array($sample))
                            @foreach($sample as $apiField => $apiValue)
                                @if(!in_array($apiField, ['id', 'continuation_token', 'more_items_remaining']))
                                    @php
                                        $dataType = gettype($apiValue);
                                        $existing = $existingMappings[$apiField] ?? null;
                                    @endphp
                                    <tr class="mapping-row" data-api-field="{{ $apiField }}" data-api-type="{{ $dataType }}">
                                        <td>
                                            <code>{{ $apiField }}</code>
                                        </td>
                                        <td>
                                            @php
                                                $typeBadges = [
                                                    'string' => 'badge-success',
                                                    'integer' => 'badge-info',
                                                    'double' => 'badge-info',
                                                    'array' => 'badge-danger',
                                                    'boolean' => 'badge-warning',
                                                    'NULL' => 'badge-secondary',
                                                ];
                                                $badge = $typeBadges[$dataType] ?? 'badge-secondary';
                                            @endphp
                                            <span class="badge {{ $badge }}">{{ $dataType }}</span>
                                        </td>
                                        <td>
                                            <small>
                                                @if(is_array($apiValue))
                                                    <em>[nested object]</em>
                                                @elseif(strlen($apiValue) > 40)
                                                    <code>{{ substr($apiValue, 0, 40) }}...</code>
                                                @else
                                                    <code>{{ json_encode($apiValue) }}</code>
                                                @endif
                                            </small>
                                        </td>
                                        <td>
                                            <select class="form-control form-control-sm target-table" 
                                                    name="mappings[{{ $apiField }}][table]"
                                                    data-api-field="{{ $apiField }}"
                                                    data-api-type="{{ $dataType }}">
                                                <option value="">-- Select Table --</option>
                                                <option value="storage" {{ ($existing['table'] ?? '') === 'storage' ? 'selected' : '' }}>
                                                    storage
                                                </option>
                                                <option value="ports" {{ ($existing['table'] ?? '') === 'ports' ? 'selected' : '' }}>
                                                    ports
                                                </option>
                                                <option value="sensors" {{ ($existing['table'] ?? '') === 'sensors' ? 'selected' : '' }}>
                                                    sensors
                                                </option>
                                                <option value="devices" {{ ($existing['table'] ?? '') === 'devices' ? 'selected' : '' }}>
                                                    devices
                                                </option>
                                                <option value="entPhysical" {{ ($existing['table'] ?? '') === 'entPhysical' ? 'selected' : '' }}>
                                                    entPhysical
                                                </option>
                                            </select>
                                        </td>
                                        <td>
                                            <select class="form-control form-control-sm target-field" 
                                                    name="mappings[{{ $apiField }}][field]"
                                                    data-api-field="{{ $apiField }}">
                                                <option value="">-- Select Field --</option>
                                            </select>
                                        </td>
                                        <td class="compatibility-status">
                                            <div class="spinner-border spinner-border-sm d-none" role="status">
                                                <span class="sr-only">Checking...</span>
                                            </div>
                                            <span class="compatibility-badge"></span>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-outline-danger remove-mapping">
                                                <i class="fa fa-times"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        @endif
                    </tbody>
                </table>
            </div>

            <div class="form-group mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-save"></i> Save Mappings
                </button>
                <button type="reset" class="btn btn-secondary">
                    <i class="fa fa-redo"></i> Reset
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Field Options Container (hidden) -->
<div id="field-options-container" style="display: none;">
    @php
        $fieldsByTable = [
            'storage' => ['storage_descr', 'storage_size', 'storage_used', 'storage_free', 'storage_type', 'storage_perc'],
            'ports' => ['ifName', 'ifDescr', 'ifSpeed', 'ifAdminStatus', 'ifOperStatus', 'ifType', 'ifIndex'],
            'sensors' => ['sensor_descr', 'sensor_current', 'sensor_class'],
            'devices' => ['hostname', 'sysDescr', 'hardware', 'version'],
            'entPhysical' => ['entPhysicalDescr', 'entPhysicalName', 'entPhysicalIndex', 'entPhysicalClass'],
        ];
    @endphp

    @foreach($fieldsByTable as $table => $fields)
        <datalist id="fields-{{ $table }}">
            @foreach($fields as $field)
                <option value="{{ $field }}">{{ $field }}</option>
            @endforeach
        </datalist>
    @endforeach
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('mapping-form');
    const mappingTable = document.getElementById('mapping-table');
    
    // Handle table selection change
    mappingTable.querySelectorAll('.target-table').forEach(select => {
        select.addEventListener('change', function() {
            const row = this.closest('tr');
            const table = this.value;
            const fieldSelect = row.querySelector('.target-field');
            
            // Clear field options
            fieldSelect.innerHTML = '<option value="">-- Select Field --</option>';
            
            if (table) {
                // Load compatible fields for this table and data type
                const apiField = this.dataset.apiField;
                const apiType = this.dataset.apiType;
                
                // Make AJAX request to get compatible fields
                fetch(`/api/rest-api/compatible-fields?table=${table}&type=${apiType}`)
                    .then(response => response.json())
                    .then(data => {
                        data.fields.forEach(field => {
                            const option = document.createElement('option');
                            option.value = field;
                            option.textContent = field;
                            fieldSelect.appendChild(option);
                        });
                    })
                    .catch(error => console.error('Error loading fields:', error));
                
                // Check compatibility
                checkCompatibility(row, apiField, apiType, table);
            }
        });
    });
    
    // Handle field selection change
    mappingTable.querySelectorAll('.target-field').forEach(select => {
        select.addEventListener('change', function() {
            const row = this.closest('tr');
            const table = row.querySelector('.target-table').value;
            const field = this.value;
            const apiField = this.dataset.apiField;
            
            if (table && field) {
                checkCompatibility(row, apiField, null, table, field);
            }
        });
    });
    
    // Remove mapping row
    mappingTable.querySelectorAll('.remove-mapping').forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('tr').remove();
        });
    });
    
    // Check compatibility function
    function checkCompatibility(row, apiField, apiType, table, field = null) {
        const statusDiv = row.querySelector('.compatibility-status');
        const spinner = statusDiv.querySelector('.spinner-border');
        const badge = statusDiv.querySelector('.compatibility-badge');
        
        spinner.classList.remove('d-none');
        badge.innerHTML = '';
        
        const params = new URLSearchParams({
            api_field: apiField,
            table: table,
            endpoint_id: document.querySelector('input[name="endpoint_id"]').value
        });
        
        if (apiType) params.append('api_type', apiType);
        if (field) params.append('field', field);
        
        fetch(`/api/rest-api/check-compatibility?${params}`)
            .then(response => response.json())
            .then(data => {
                spinner.classList.add('d-none');
                
                if (data.valid) {
                    badge.innerHTML = '<span class="badge badge-success"><i class="fa fa-check"></i> Compatible</span>';
                    row.classList.remove('table-danger');
                    row.classList.add('table-success');
                } else {
                    badge.innerHTML = '<span class="badge badge-danger"><i class="fa fa-times"></i> Incompatible</span>';
                    row.classList.remove('table-success');
                    row.classList.add('table-danger');
                }
            })
            .catch(error => {
                spinner.classList.add('d-none');
                badge.innerHTML = '<span class="badge badge-warning">?</span>';
                console.error('Error checking compatibility:', error);
            });
    }
    
    // Validate form on submit
    form.addEventListener('submit', function(e) {
        const rows = mappingTable.querySelectorAll('.mapping-row');
        let hasMapping = false;
        
        rows.forEach(row => {
            const table = row.querySelector('.target-table').value;
            const field = row.querySelector('.target-field').value;
            
            if (table && field) {
                hasMapping = true;
            }
        });
        
        if (!hasMapping) {
            e.preventDefault();
            alert('Please configure at least one mapping');
            return false;
        }
    });
});
</script>
