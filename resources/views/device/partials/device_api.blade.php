{{-- resources/views/device/partials/device_api.blade.php --}}
@php
    // Ensure arrays/booleans to avoid count()/foreach/JSON errors
    $templates = is_array($templates ?? null) ? $templates : [];
    $authTypes = is_array($authTypes ?? null) ? $authTypes : [];
    $autoSelectTemplate = isset($autoSelectTemplate) ? (bool) $autoSelectTemplate : false;

    // API enabled state from existing config (unchecked by default if no config)
    $apiEnabled = (bool) ($apiConfig ? true : false);
    $currentAuthType = $apiConfig?->schema?->key ?? '';
@endphp

@if(!empty($device->getAttrib('rest_last_error_message')))
    <div class="alert alert-warning">
        <strong>Last Error:</strong> {{ $device->getAttrib('rest_last_error_message') }}
        @if(!empty($device->getAttrib('rest_last_error')))
            <br><small>{{ \Carbon\Carbon::createFromTimestamp((int) $device->getAttrib('rest_last_error'))->diffForHumans() }}</small>
        @endif
    </div>
@endif

<input type="hidden" name="api_settings_form" value="1">
    {{-- Save Settings Button - Always visible so users can save when enabling OR disabling API --}}
<div class="form-group">
    <div class="col-sm-offset-2 col-sm-6">
        <button type="submit" name="Submit" class="btn btn-success">
            <i class="fa fa-check"></i> Save Connection Settings
        </button>
        <small class="text-muted" id="save-button-hint">
            <span id="disable-hint" style="display: none;">
                <i class="fa fa-info-circle"></i> Click to disable API polling and remove credentials.
            </span>
            <span id="endpoint-hint">
                <i class="fa fa-info-circle"></i> Endpoint changes are saved automatically via the modal. This button saves authentication and connection settings only.
            </span>
        </small>
    </div>
</div>

<div class="form-group">
    <div class="col-sm-offset-2 col-sm-6">
        <div class="checkbox">
            <label>
                <input type="checkbox" id="rest_enabled" name="rest_enabled" value="1"
                       {{ old('rest_enabled', $apiEnabled) ? 'checked' : '' }}>
                <strong>Enable REST API discovery/polling</strong>
            </label>
        </div>
        <small class="text-muted">Enable to configure vendor API polling and discovery.</small>
    </div>
</div>

{{-- All other API settings are hidden until enabled --}}
<div id="api-settings-content" style="{{ old('rest_enabled', $apiEnabled) ? '' : 'display:none;' }}">
	    {{-- Test Connection Button (only visible when API enabled) --}}
    <div class="form-group">
        <div class="col-sm-offset-2 col-sm-6">
            <button type="button" id="test-api-connection" class="btn btn-info">
                <i class="fa fa-plug"></i> Test Connection
            </button>

            @php $errorCount = (int) $device->getAttrib('rest_error_count'); @endphp
            @if($errorCount > 0)
                <button type="button" id="reset-circuit-breaker" class="btn btn-warning">
                    <i class="fa fa-refresh"></i> Reset Error Counter
                </button>
            @endif
        </div>
    </div>
    {{-- Template Selector --}}
    <div class="form-group">
        <label for="rest_template" class="col-sm-2 control-label">Template</label>
        <div class="col-sm-6">
            @php $selectedTemplate = old('rest_template', $selectedTemplate ?? ''); @endphp
            <select class="form-control" id="rest_template" name="rest_template">
                <option value="">Custom (no template)</option>
                @foreach($templates as $vendor => $template)
                    <option value="{{ $vendor }}" {{ $selectedTemplate === $vendor ? 'selected' : '' }}>
                        {{ $template['name'] ?? ucfirst((string) $vendor) }}
                    </option>
                @endforeach
            </select>
            <small class="text-muted">
                @if(empty($templates))
                    No templates available for {{ $device->os }}. Use custom configuration.
                @elseif(count($templates) === 1)
                    Template auto-selected for {{ $device->os }} devices.
                @else
                    Select a pre-configured template or configure manually.
                @endif
            </small>
        </div>
    </div>

    {{-- Authentication Type Selector --}}
    <div class="form-group">
        <label for="rest_auth_type" class="col-sm-2 control-label">Authentication Type <span class="text-danger">*</span></label>
        <div class="col-sm-6">
            @php $authType = old('rest_auth_type', $currentAuthType); @endphp
            <select class="form-control" id="rest_auth_type" name="rest_auth_type">
                <option value="">Select authentication type...</option>
                @foreach($authTypes as $type => $config)
                    <option value="{{ $type }}" {{ $authType === $type ? 'selected' : '' }}>
                        {{ $config['name'] ?? ucfirst((string) $type) }}
                    </option>
                @endforeach
            </select>
            <small class="text-muted auth-description"></small>
        </div>
    </div>

    {{-- Base URL --}}
    <div class="form-group">
        <label for="rest_base_url" class="col-sm-2 control-label">Base URL <span class="text-danger">*</span></label>
        <div class="col-sm-6">
            <input type="url" id="rest_base_url" class="form-control" name="rest_base_url"
                   value="{{ old('rest_base_url', $apiConfig?->base_url ?? '') }}"
                   placeholder="https://device.example/api">
            <small class="text-muted base-url-hint"></small>
        </div>
    </div>

    {{-- Dynamic Auth Fields (rendered based on auth schemas from database) --}}
    @foreach($authTypes as $authKey => $authSchema)
        @if(isset($authSchema['fields']) && is_array($authSchema['fields']))
            @foreach($authSchema['fields'] as $field)
                <div class="form-group auth-field auth-{{ $authKey }}" style="display: none;">
                    <label for="{{ $field['name'] }}" class="col-sm-2 control-label">
                        {{ $field['label'] }}
                        @if($field['required'] ?? false)
                            <span class="text-danger">*</span>
                        @endif
                    </label>
                    <div class="col-sm-6">
                        @if($field['type'] === 'password')
                            <input type="password"
                                   id="{{ $field['name'] }}"
                                   class="form-control"
                                   name="{{ $field['name'] }}"
                                   placeholder="{{ $field['placeholder'] ?? 'Enter to set or replace' }}"
                                   value="">
                            @if($apiConfig && $apiConfig->schema_id === $authSchema['id'] && $apiConfig->getValue($field['name']))
                                <small class="text-muted">A value is stored. Enter a new value to replace.</small>
                            @endif
                        @elseif($field['type'] === 'select' && isset($field['options']))
                            <select id="{{ $field['name'] }}"
                                    class="form-control"
                                    name="{{ $field['name'] }}">
                                @foreach($field['options'] as $optValue => $optLabel)
                                    @php
                                        $currentValue = old($field['name'],
                                            $apiConfig && $apiConfig->schema_id === $authSchema['id']
                                                ? $apiConfig->getValue($field['name'], $field['default'] ?? '')
                                                : ($field['default'] ?? '')
                                        );
                                    @endphp
                                    <option value="{{ $optValue }}" {{ $currentValue == $optValue ? 'selected' : '' }}>
                                        {{ $optLabel }}
                                    </option>
                                @endforeach
                            </select>
                        @else
                            @php
                                $currentValue = old($field['name'],
                                    $apiConfig && $apiConfig->schema_id === $authSchema['id']
                                        ? $apiConfig->getValue($field['name'], $field['default'] ?? '')
                                        : ($field['default'] ?? '')
                                );
                            @endphp
                            <input type="{{ $field['type'] ?? 'text' }}"
                                   id="{{ $field['name'] }}"
                                   class="form-control"
                                   name="{{ $field['name'] }}"
                                   value="{{ $currentValue }}"
                                   placeholder="{{ $field['placeholder'] ?? '' }}">
                        @endif
                    </div>
                </div>
            @endforeach
        @endif
    @endforeach

    {{-- Other Settings --}}
    <div class="form-group">
        <label for="rest_headers" class="col-sm-2 control-label">Extra Headers</label>
        <div class="col-sm-6">
            @php
                $headers = old('rest_headers', '');
                if (!$headers && $apiConfig && $apiConfig->extra_headers) {
                    $headers = implode("\n", array_map(fn($k, $v) => "$k: $v", array_keys($apiConfig->extra_headers), $apiConfig->extra_headers));
                }
            @endphp
            <textarea id="rest_headers" class="form-control" name="rest_headers" rows="2"
                      placeholder="Header-Name: value (one per line)">{{ $headers }}</textarea>
            <small class="text-muted">One header per line in format: Header-Name: value</small>
        </div>
    </div>

    <div class="form-group">
        <div class="col-sm-offset-2 col-sm-6">
            <div class="checkbox">
                @php $verify = (bool) old('rest_verify_tls', $apiConfig?->verify_ssl ?? true); @endphp
                {{-- Hidden field ensures unchecked state is sent --}}
                <input type="hidden" name="rest_verify_tls" value="0">
                <label>
                    <input type="checkbox" id="rest_verify_tls" name="rest_verify_tls" value="1"
                           {{ $verify ? 'checked' : '' }}>
                    Verify TLS/SSL certificates
                </label>
            </div>
            <small class="text-muted d-block">Uncheck to disable SSL certificate verification (not recommended for production).</small>
        </div>
    </div>

    {{-- Connection Options --}}
    <div class="form-group">
        <label for="rest_timeout_ms" class="col-sm-2 control-label">Timeout (ms)</label>
        <div class="col-sm-6">
            <input type="number" min="0" step="100" id="rest_timeout_ms" class="form-control" name="rest_timeout_ms"
                   value="{{ old('rest_timeout_ms', $device->getAttrib('rest_timeout_ms') ?? 5000) }}">
        </div>
    </div>

    <div class="form-group">
        <label for="rest_proxy" class="col-sm-2 control-label">Proxy</label>
        <div class="col-sm-6">
            <input type="text" id="rest_proxy" class="form-control" name="rest_proxy"
                   value="{{ old('rest_proxy', $device->getAttrib('rest_proxy') ?? '') }}"
                   placeholder="http://proxy.example:3128">
        </div>
    </div>

    <hr>



    @php $lastSuccess = (int) $device->getAttrib('rest_last_success'); @endphp
    @if($lastSuccess > 0)
        <div class="form-group">
            <div class="col-sm-offset-2 col-sm-6">
                <small class="text-muted">
                    <i class="fa fa-check-circle text-success"></i>
                    Last success: {{ \Carbon\Carbon::createFromTimestamp($lastSuccess)->diffForHumans() }}
                    @php $avgLatency = (int) $device->getAttrib('rest_avg_latency_ms'); @endphp
                    @if($avgLatency > 0)
                        (avg {{ $avgLatency }}ms)
                    @endif
                </small>
            </div>
        </div>
    @endif

    <div id="connection-test-result" class="form-group" style="display: none;">
        <div class="col-sm-offset-2 col-sm-6">
            <div class="alert" id="test-result-message"></div>
        </div>
    </div>


    <hr>

    {{-- Endpoints Management Section --}}
    <div class="form-group">
        <div class="col-sm-offset-2 col-sm-10">
            <h4>API Endpoints <small class="text-muted">Configure which endpoints to poll</small></h4>
        </div>
    </div>

    <div class="form-group">
        <div class="col-sm-offset-2 col-sm-10">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <button type="button" id="add-endpoint-btn" class="btn btn-xs btn-success pull-right">
                        <i class="fa fa-plus"></i> Add Endpoint
                    </button>
                    Configured Endpoints
                </div>
                <div class="panel-body" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-condensed table-hover" id="endpoints-table">
                        <thead>
                        <tr>
                            <th style="width: 25%;">Name</th>
                            <th style="width: 30%;">Path</th>
                            <th style="width: 10%;">Method</th>
                            <th style="width: 15%;">Category</th>
                            <th style="width: 10%;">Status</th>
                            <th style="width: 10%;">Actions</th>
                        </tr>
                        </thead>
                        <tbody id="endpoints-tbody">
                        {{-- Endpoints will be populated via JavaScript --}}
                        </tbody>
                    </table>
                    <p class="text-muted text-center" id="no-endpoints-msg" style="display: none;">
                        No endpoints configured. Select a template or add endpoints manually.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <input type="hidden" name="rest_endpoints" id="rest_endpoints" value="">

    {{-- Endpoint Edit/Add Modal --}}
    <div class="modal fade" id="endpoint-modal" tabindex="-1" role="dialog" aria-labelledby="endpoint-modal-label">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <h4 class="modal-title" id="endpoint-modal-label">Add Endpoint</h4>
                </div>
                <div class="modal-body" style="padding: 20px 30px;">
                    <form id="endpoint-form">
                        <div class="form-group">
                            <label for="endpoint-enabled">Status</label>
                            <div class="checkbox">
                                <label>
                                    <input type="checkbox" id="endpoint-enabled" checked>
                                    <strong>Enable this endpoint</strong>
                                </label>
                            </div>
                            <small class="text-muted">Disabled endpoints will not be polled</small>
                        </div>

                        <div class="form-group">
                            <label for="endpoint-name">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="endpoint-name" placeholder="e.g., System Status" required>
                            <small class="text-muted">Descriptive name for this endpoint</small>
                        </div>

                        <div class="form-group">
                            <label for="endpoint-path">Path <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="endpoint-path" placeholder="/api/status" required>
                            <small class="text-muted">Relative path from base URL</small>
                        </div>

                        <div class="form-group">
                            <label for="endpoint-method">HTTP Method</label>
                            <select class="form-control" id="endpoint-method">
                                <option value="GET">GET</option>
                                <option value="POST">POST</option>
                                <option value="PUT">PUT</option>
                                <option value="PATCH">PATCH</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="endpoint-category">Category</label>
                            <input type="text" class="form-control" id="endpoint-category" placeholder="general" value="general">
                            <small class="text-muted">Capability/category for grouping</small>
                        </div>

                        <div class="form-group">
                            <label for="endpoint-poll-interval">Poll Interval (seconds)</label>
                            <input type="number" class="form-control" id="endpoint-poll-interval" min="1" value="60">
                            <small class="text-muted">How often to poll this endpoint</small>
                        </div>

                        <div class="form-group">
                            <label for="endpoint-description">Description</label>
                            <textarea class="form-control" id="endpoint-description" rows="2" placeholder="Optional description"></textarea>
                        </div>

                        <div class="form-group" style="display: none;">
                            <label for="endpoint-transform">Transform</label>
                            <textarea class="form-control" id="endpoint-transform" rows="3" placeholder="Optional JSON transform configuration"></textarea>
                            <small class="text-muted">Advanced: JSON transformation rules</small>
                        </div>

                        <div class="form-group">
                            <label for="endpoint-headers">Extra Headers</label>
                            <textarea class="form-control" id="endpoint-headers" rows="2" placeholder="Header-Name: value (one per line)"></textarea>
                            <small class="text-muted">Additional headers for this endpoint only</small>
                        </div>

                        <div class="form-group">
                            <label for="endpoint-request-body">Request Body</label>
                            <textarea class="form-control" id="endpoint-request-body" rows="2" placeholder="JSON request body for POST/PUT requests"></textarea>
                            <small class="text-muted">For POST/PUT/PATCH methods</small>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="save-endpoint-btn">Save Endpoint</button>
                </div>
            </div>
        </div>
    </div>

</div> {{-- #api-settings-content --}}



@push('scripts')
<script>
// Helper function (MUST be defined outside of $(document).ready() to be visible everywhere)
function generateEndpointName(path, capability) {
    let name = path.replace(/^\//, '');
    name = name.replace(/\{[^}]+\}/g, ''); // Remove {variables}
    name = name.replace(/[/_-]/g, ' ').trim();
    name = name.split(' ')
        .filter(word => word.length > 0)
        .map(word => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
    if (capability) {
        name = capability.charAt(0).toUpperCase() + capability.slice(1) + ': ' + name;
    }
    return name || 'API Endpoint';
}

(function() {
    const enabledCheckbox = document.getElementById('rest_enabled');
    const content = document.getElementById('api-settings-content');

    function toggleInputsDisability(disabled) {
        content.querySelectorAll('input, select, textarea, button').forEach(el => {
            if (el.id === 'rest_enabled') return;
            el.disabled = disabled;
        });
    }

    function toggleContent() {
        const enabled = enabledCheckbox && enabledCheckbox.checked;
        content.style.display = enabled ? '' : 'none';
        toggleInputsDisability(!enabled);

        // Show hint when API is disabled (user can save to remove credentials)
        const disableHint = document.getElementById('disable-hint');
        if (disableHint) {
            disableHint.style.display = enabled ? 'none' : 'inline';
        }
    }

    if (enabledCheckbox) {
        enabledCheckbox.addEventListener('change', toggleContent);
        toggleContent();
    }
})();

// Device information
const deviceHostname = '{{ $device->hostname }}';
const deviceSysName = '{{ $device->sysName ?? $device->hostname }}';
const autoSelectTemplate = {{ $autoSelectTemplate ? 'true' : 'false' }};

// Template metadata and auth config
const templates = @json($templates);
const authTypes = @json($authTypes);
const selectedTemplateKey = '{{ $selectedTemplate ?? '' }}';

// Pre-load all template data for instant switching
const allTemplateData = {
@foreach($templates as $vendor => $template)
    @php
        $fullTemplate = \LibreNMS\Util\ApiTemplateManager::loadTemplate($vendor);
    @endphp
    '{{ $vendor }}': @json($fullTemplate ?? []),
@endforeach
};

// Endpoints storage - load from selected template if available
let endpoints = [];

// Load saved endpoints from PHP if they exist
@if(!empty($savedEndpoints) && is_array($savedEndpoints))
    endpoints = @json($savedEndpoints);
    console.log('Loaded ' + endpoints.length + ' saved endpoints from database');
@endif

// Initialize on page load
$(document).ready(function() {

    // --- 1. Initial Endpoint Loading (Loads saved data or template defaults if old() is present) ---
    // This loads endpoints either from the old() input or if a template key was previously saved/selected by PHP.
    // Only load from template if no saved custom endpoints exist
    if (endpoints.length === 0 && selectedTemplateKey && allTemplateData[selectedTemplateKey]) {
        console.log('Loading endpoints from template:', selectedTemplateKey);
        const template = allTemplateData[selectedTemplateKey];
        if (Array.isArray(template.endpoints) && template.endpoints.length > 0) {
            endpoints = template.endpoints.map(function(ep) {
                // Use the shared function logic to map API model keys to JS object keys
                return {
                    name: generateEndpointName(ep.path, ep.capability),
                    path: ep.path,
                    method: ep.method || 'GET',
                    category: ep.capability || 'general',
                    poll_interval: ep.poll_interval || 60,
                    enabled: ep.enabled !== false,
                    transform: ep.transform || '',
                    headers: ep.headers || {},
                    request_body: ep.request_body || null
                };
            });
        }
    }

    // --- 2. Auto-Selection Logic (CRITICAL FIX for Auth Type and Timing) ---
    const initialTemplateName = $('#rest_template').val();

    // Check if auto-select criteria are met (must be done AFTER initial endpoint loading attempt)
    // The condition 'endpoints.length === 0' prevents overwriting explicitly saved/customized endpoints.
    if (autoSelectTemplate && initialTemplateName && endpoints.length === 0) {

        // This ensures the URL and Auth Type/Fields are set, and re-maps the endpoints for display.
        applyTemplate(allTemplateData[initialTemplateName]);
        toastr.info('Template automatically selected and applied for ' + initialTemplateName);

        // Note: applyTemplate handles rendering.
    }

    // 3. Force Authentication Field Visibility if already configured by PHP
    const initialAuthType = $('#rest_auth_type').val();
    if (initialAuthType) {
        // Force the visibility update immediately based on the PHP-set value
        updateAuthFieldVisibility();
        // Update description as well
        if (authTypes[initialAuthType]) {
            $('.auth-description').text(authTypes[initialAuthType].description || '');
        }
    }

    // --- 4. Final Render (Always needed) ---
    renderEndpointsTable();
    updateEndpointsHiddenField();
});

// Template selection handler
$('#rest_template').on('change', function() {
    const templateName = $(this).val();
    if (templateName) {
        // User selected a template - load and apply it
        loadTemplateData(templateName);
    } else {
        // User selected "Custom" - clear endpoint list but preserve base URL
        endpoints = [];
        // Clear base URL hint as it's no longer template driven
        $('.base-url-hint').text('Custom configuration - enter base URL manually');
        renderEndpointsTable();
        updateEndpointsHiddenField();
        toastr.info('Switched to custom configuration. Endpoints cleared, Base URL preserved.');
    }
});

// Load template data (from pre-loaded data)
function loadTemplateData(templateName) {
    if (allTemplateData[templateName]) {
        applyTemplate(allTemplateData[templateName]);
    } else {
        toastr.error('Template not found: ' + templateName);
        console.error('Template not found:', templateName);
    }
}

// Apply template to form (FIXED to handle Auth Type and map all endpoint fields)
function applyTemplate(template) {
    if (!template || typeof template !== 'object') return;

    // 1. Base URL - only auto-populate if empty (preserve user customizations)
    const currentBaseUrl = $('#rest_base_url').val();
    if (!currentBaseUrl || currentBaseUrl.trim() === '') {
        if (template.base_url_pattern) {
            const baseUrl = template.base_url_pattern.replace('{hostname}', deviceHostname);
            $('#rest_base_url').val(baseUrl);
            $('.base-url-hint').text('Auto-populated from device hostname');
        } else if (template.base_url_example) {
            $('#rest_base_url').attr('placeholder', template.base_url_example);
            $('.base-url-hint').text('Example: ' + template.base_url_example);
        }
    } else {
        // Base URL already exists (user customized or loaded from database)
        // Just update the hint text
        if (template.base_url_pattern) {
            $('.base-url-hint').text('Custom Base URL (differs from template default)');
        }
    }

    // 2. Authentication Type (FIXED)
    // template.auth_type is correctly exposed by PHP loadTemplate function as the schema KEY string.
    if (template.auth_type) {
        // This sets the select value and triggers the change event to show fields.
        $('#rest_auth_type').val(template.auth_type).trigger('change');
    }

    // 3. Endpoints (FIXED - Ensure mapping uses correct source keys)
    const rawEndpoints = template.endpoints;

    if (Array.isArray(rawEndpoints) && rawEndpoints.length > 0) {
        endpoints = rawEndpoints.map(function(ep) {
            // Map fields using the correct names exposed by the PHP loadTemplate function.
            let name = generateEndpointName(ep.path, ep.capability);

            return {
                name: name,
                path: ep.path,
                method: ep.method || 'GET',
                category: ep.capability || 'general',
                // Use default if DB field is not set/exposed, otherwise use what's exposed.
                poll_interval: ep.poll_interval || 60,
                enabled: ep.enabled !== false,
                transform: ep.transform || '',
                headers: ep.headers || {},
                request_body: ep.request_body || null
            };
        });
        renderEndpointsTable();
        updateEndpointsHiddenField();

    } else {
        endpoints = [];
        renderEndpointsTable();
        updateEndpointsHiddenField();
    }
}

// Auth type change handler
$('#rest_auth_type').on('change', function() {
    updateAuthFieldVisibility();

    // Update description
    const authType = $(this).val();
    if (authType && authTypes[authType]) {
        $('.auth-description').text(authTypes[authType].description || '');
    } else {
        $('.auth-description').text('');
    }
});

// Show/hide auth fields based on selected type
function updateAuthFieldVisibility() {
    const authType = $('#rest_auth_type').val();

    // Hide all auth fields
    $('.auth-field').hide();

    // Show fields for selected auth type
    if (authType) {
        $('.auth-' + authType).show();
    }
}

// Render endpoints table
function renderEndpointsTable() {
    const tbody = $('#endpoints-tbody');
    tbody.empty();

    if (!Array.isArray(endpoints) || endpoints.length === 0) {
        $('#no-endpoints-msg').show();
        return;
    }

    $('#no-endpoints-msg').hide();

    endpoints.forEach((endpoint, index) => {
        const safeName = escapeHtml(endpoint?.name ?? '');
        const safePath = escapeHtml(endpoint?.path ?? '');
        const method = (endpoint?.method || 'GET').toUpperCase();
        const category = endpoint?.category || 'general';
        const enabled = endpoint?.enabled ?? true;

        const statusBadge = enabled
            ? '<span class="label label-success">Enabled</span>'
            : '<span class="label label-default">Disabled</span>';

        const row = `
            <tr data-index="${index}">
                <td>${safeName}</td>
                <td><code>${safePath}</code></td>
                <td><span class="label label-info">${method}</span></td>
                <td>${category}</td>
                <td>${statusBadge}</td>
                <td>
                    <button type="button" class="btn btn-xs btn-primary edit-endpoint" data-index="${index}">
                        <i class="fa fa-edit"></i>
                    </button>
                    <button type="button" class="btn btn-xs btn-danger delete-endpoint" data-index="${index}">
                        <i class="fa fa-trash"></i>
                    </button>
                </td>
            </tr>
        `;
        tbody.append(row);
    });
}

// Update hidden field with endpoints JSON
function updateEndpointsHiddenField() {
    try {
        // Only store necessary data to keep payload small
        const serializableEndpoints = endpoints.map(ep => ({
            name: ep.name,
            path: ep.path,
            method: ep.method,
            category: ep.category,
            poll_interval: ep.poll_interval,
            enabled: ep.enabled,
            transform: ep.transform,
            headers: ep.headers,
            request_body: ep.request_body,
        }));
        $('#rest_endpoints').val(JSON.stringify(serializableEndpoints || []));
    } catch (e) {
        $('#rest_endpoints').val('[]');
    }
}

// Add endpoint
$('#add-endpoint-btn').on('click', function() {
    showEndpointModal();
});

// Edit endpoint
$(document).on('click', '.edit-endpoint', function() {
    const index = $(this).data('index');
    showEndpointModal(endpoints[index] || null, index);
});

// Delete endpoint
$(document).on('click', '.delete-endpoint', function() {
    if (!confirm('Are you sure you want to delete this endpoint?')) {
        return;
    }

    const index = $(this).data('index');
    endpoints.splice(index, 1);
    renderEndpointsTable();
    updateEndpointsHiddenField();
});

// Show endpoint modal
let currentEndpointIndex = null;
let originalEndpointState = null;

function showEndpointModal(endpoint = null, index = null) {
    currentEndpointIndex = index;
    const isEdit = endpoint !== null;

    // Store original state for change detection
    originalEndpointState = isEdit ? JSON.parse(JSON.stringify(endpoint)) : null;

    // Update modal title
    $('#endpoint-modal-label').text(isEdit ? 'Edit Endpoint' : 'Add Endpoint');

    // Populate form fields
    $('#endpoint-enabled').prop('checked', endpoint?.enabled ?? true);
    $('#endpoint-name').val(endpoint?.name || '');
    $('#endpoint-path').val(endpoint?.path || '');
    $('#endpoint-method').val((endpoint?.method || 'GET').toUpperCase());
    $('#endpoint-category').val(endpoint?.category || 'general');
    $('#endpoint-poll-interval').val(endpoint?.poll_interval || 60);
    $('#endpoint-description').val(endpoint?.description || '');
    $('#endpoint-transform').val(endpoint?.transform || '');

    // Handle headers - convert object to text
    if (endpoint?.headers && typeof endpoint.headers === 'object') {
        const headersText = Object.entries(endpoint.headers)
            .map(([key, value]) => `${key}: ${value}`)
            .join('\n');
        $('#endpoint-headers').val(headersText);
    } else {
        $('#endpoint-headers').val('');
    }

    // Handle request body
    if (endpoint?.request_body) {
        const bodyText = typeof endpoint.request_body === 'string'
            ? endpoint.request_body
            : JSON.stringify(endpoint.request_body, null, 2);
        $('#endpoint-request-body').val(bodyText);
    } else {
        $('#endpoint-request-body').val('');
    }

    // Show modal
    $('#endpoint-modal').modal('show');
}

// Save endpoint from modal
$('#save-endpoint-btn').on('click', function() {
    const btn = $(this);
    const name = $('#endpoint-name').val().trim();
    const path = $('#endpoint-path').val().trim();

    // Validation
    if (!name) {
        toastr.error('Endpoint name is required');
        $('#endpoint-name').focus();
        return;
    }

    if (!path) {
        toastr.error('Endpoint path is required');
        $('#endpoint-path').focus();
        return;
    }

    // Parse headers
    const headersText = $('#endpoint-headers').val().trim();
    const headers = {};
    if (headersText) {
        headersText.split('\n').forEach(line => {
            line = line.trim();
            if (line && line.includes(':')) {
                const [key, ...valueParts] = line.split(':');
                headers[key.trim()] = valueParts.join(':').trim();
            }
        });
    }

    // Parse request body
    let requestBody = $('#endpoint-request-body').val().trim();
    if (requestBody) {
        try {
            // Try to parse as JSON to validate
            requestBody = JSON.parse(requestBody);
        } catch (e) {
            // If not valid JSON, store as string
        }
    } else {
        requestBody = null;
    }

    // Get transform value without validation (hidden field, just pass through)
    let transform = $('#endpoint-transform').val() || '';

    const newEndpoint = {
        id: currentEndpointIndex !== null ? endpoints[currentEndpointIndex]?.id : undefined,
        name: name,
        path: path,
        method: $('#endpoint-method').val().toUpperCase(),
        category: $('#endpoint-category').val().trim() || 'general',
        poll_interval: parseInt($('#endpoint-poll-interval').val(), 10) || 60,
        description: $('#endpoint-description').val().trim(),
        enabled: $('#endpoint-enabled').is(':checked'),
        transform: transform || '',
        headers: Object.keys(headers).length > 0 ? headers : {},
        request_body: requestBody
    };

    const isEdit = currentEndpointIndex !== null && originalEndpointState;

    // For existing endpoints with ID, send AJAX with only changed fields
    if (isEdit && newEndpoint.id) {
        // Detect changed fields
        const changes = {};
        const fieldMapping = {
            name: 'name',
            path: 'path',
            method: 'method',
            category: 'category',
            poll_interval: 'poll_interval',
            description: 'description',
            enabled: 'enabled',
            transform: 'transform',
            headers: 'headers',
            request_body: 'request_body'
        };

        Object.keys(fieldMapping).forEach(field => {
            const newVal = newEndpoint[field];
            const oldVal = originalEndpointState[field];

            // Deep comparison for objects
            if (typeof newVal === 'object' && typeof oldVal === 'object') {
                if (JSON.stringify(newVal) !== JSON.stringify(oldVal)) {
                    changes[field] = newVal;
                }
            } else if (newVal !== oldVal) {
                changes[field] = newVal;
            }
        });

        // If no changes, just close modal
        if (Object.keys(changes).length === 0) {
            toastr.info('No changes detected');
            $('#endpoint-modal').modal('hide');
            return;
        }

        // Disable button while saving
        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');

        // Send AJAX request to update only changed fields
        fetch('{{ route("device.update-endpoint", $device->device_id) }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                endpoint_id: newEndpoint.id,
                changes: changes
            })
        })
        .then(r => {
            if (!r.ok) {
                return r.json().then(err => {
                    throw new Error(err.error || 'Server error: ' + r.status);
                });
            }
            return r.json();
        })
        .then(d => {
            if (d && d.success) {
                // Update local state
                endpoints[currentEndpointIndex] = newEndpoint;
                renderEndpointsTable();
                updateEndpointsHiddenField();
                toastr.success(d.message || 'Endpoint updated');
                $('#endpoint-modal').modal('hide');
            } else {
                toastr.error('Failed to update endpoint: ' + (d.error || 'Unknown error'));
            }
        })
        .catch(e => {
            console.error('Error updating endpoint:', e);
            toastr.error('Failed to update endpoint: ' + e.message);
        })
        .finally(() => {
            btn.prop('disabled', false).html('Save Endpoint');
        });
    } else {
        // For new endpoints or endpoints without ID, update locally and save with form
        if (currentEndpointIndex !== null) {
            endpoints[currentEndpointIndex] = newEndpoint;
            toastr.success('Endpoint updated (click "Save Settings" to persist)');
        } else {
            endpoints.push(newEndpoint);
            toastr.success('Endpoint added (click "Save Settings" to persist)');
        }

        // Update UI and hide modal
        renderEndpointsTable();
        updateEndpointsHiddenField();
        $('#endpoint-modal').modal('hide');
    }
});

// Helper function
function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
}

// Test API connection
$('#test-api-connection').on('click', function() {
    const btn = $(this);
    const originalHtml = btn.html();
    btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Testing...');

    // Collect all visible auth fields
    const authData = {};
    $('.auth-field:visible input, .auth-field:visible select, .auth-field:visible textarea').each(function() {
        const name = $(this).attr('name');
        const value = $(this).val();
        if (name && value) {
            authData[name] = value;
        }
    });

    fetch('{{ route("device.test-api-connection", $device->device_id) }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            rest_enabled: $('#rest_enabled').is(':checked'),
            rest_template: $('#rest_template').val(),
            rest_base_url: $('#rest_base_url').val(),
            rest_auth_type: $('#rest_auth_type').val(),
            rest_headers: $('#rest_headers').val(),
            rest_verify_tls: $('#rest_verify_tls').is(':checked'),
            rest_timeout_ms: $('#rest_timeout_ms').val(),
            rest_proxy: $('#rest_proxy').val(),
            ...authData
        })
    })
    .then(r => r.json())
    .then(d => {
        // Always log full response to console for debugging
        console.log('API Connection Test Response:', d);

        if (d && (d.ok || d.success)) {
            let message = d.message || 'Connection successful!';
            if (d.test_path) {
                message += ' (tested: ' + d.test_path + ')';
            }
            if (d.latency_ms) {
                message += ' [' + d.latency_ms + 'ms]';
            }

            // If there are debug details, also log them prominently
            if (d.details) {
                console.warn('Connection Test Details:', d.details);
            }

            toastr.success(message);
        } else {
            toastr.error('Connection failed: ' + ((d && d.error) || 'Unknown error'));
            if (d.details) {
                console.error('Connection Failure Details:', d.details);
            }
        }
    })
    .catch(e => {
        toastr.error('Connection test failed: ' + e.message);
    })
    .finally(() => {
        btn.prop('disabled', false).html(originalHtml);
    });
});

// Reset circuit breaker
$('#reset-circuit-breaker').on('click', function() {
    const btn = $(this);
    if (!confirm('Reset the error counter and circuit breaker for this device?')) {
        return;
    }

    btn.prop('disabled', true);

    fetch('{{ route("device.reset-circuit-breaker", $device->device_id) }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    })
    .then(r => r.json())
    .then(d => {
        if (d && d.success) {
            toastr.success('Circuit breaker reset successfully');
            setTimeout(() => location.reload(), 1000);
        } else {
            toastr.error('Failed to reset circuit breaker');
        }
    })
    .catch(() => {
        toastr.error('Failed to reset circuit breaker');
    })
    .finally(() => {
        btn.prop('disabled', false);
    });
});

// Disable hidden auth fields before form submission to avoid duplicate field name conflicts
// Multiple schemas have fields with the same names (username, password, etc.)
// We only want to submit the fields for the currently selected auth type
$('#edit-api').on('submit', function(e) {
    console.log('===== FORM SUBMIT HANDLER RUNNING =====');
    console.log('Event:', e);
    console.log('Form action:', $(this).attr('action'));
    console.log('Form method:', $(this).attr('method'));

    // Get the currently selected auth type
    const selectedAuthType = $('#rest_auth_type').val();
    console.log('Selected auth type:', selectedAuthType);

    // Check if API is enabled
    const apiEnabled = $('#rest_enabled').is(':checked');
    console.log('API enabled:', apiEnabled);

    // If API is disabled, disable all API fields to prevent validation errors
    if (!apiEnabled) {
        console.log('API disabled - disabling all API fields to bypass validation');
        $('#api-settings-content input, #api-settings-content select, #api-settings-content textarea').prop('disabled', true);
        return true; // Allow form to submit
    }

    // First, ensure the correct auth fields are visible (only if API is enabled)
    if (selectedAuthType) {
        $('.auth-field').hide(); // Hide all auth fields
        $('.auth-' + selectedAuthType).show(); // Show only the selected auth type's fields

        // Re-enable visible auth fields in case they were disabled
        $('.auth-' + selectedAuthType + ' input, .auth-' + selectedAuthType + ' select, .auth-' + selectedAuthType + ' textarea').prop('disabled', false);
    }

    // Log visible auth fields before disabling hidden ones
    const visibleFields = $('.auth-field:visible input, .auth-field:visible select, .auth-field:visible textarea');
    console.log('Visible auth fields:', visibleFields.length);
    visibleFields.each(function() {
        const name = $(this).attr('name');
        const value = $(this).val();
        const disabled = $(this).prop('disabled');
        console.log('Field:', name, 'Value length:', value ? value.length : 0, 'Disabled:', disabled);
    });

    // Count and log hidden auth fields before disabling
    const hiddenFields = $('.auth-field:hidden input, .auth-field:hidden select, .auth-field:hidden textarea');
    console.log('Hidden auth fields to disable:', hiddenFields.length);

    // Disable all hidden auth fields to prevent validation errors
    hiddenFields.each(function() {
        $(this).prop('disabled', true);
    });

    // The visible auth fields will be submitted normally
    return true;
});

setTimeout(function () {
    const selectedTemplate = $('#rest_template').val();
    const selectedAuth = $('#rest_auth_type').val();

    // If a template is preselected, re-apply it to re-trigger field visibility
    if (selectedTemplate && allTemplateData[selectedTemplate]) {
        // Ensure template's auth type is re-applied to show fields
        applyTemplate(allTemplateData[selectedTemplate]);
    } else if (selectedAuth) {
        // Otherwise just ensure the proper auth fields are visible
        $('#rest_auth_type').trigger('change');
    }
}, 250);
</script>
@endpush