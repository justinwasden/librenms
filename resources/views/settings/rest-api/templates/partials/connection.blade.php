@php
// We no longer deal with credential models or logic here.
$connections = $template->template_data['connections'] ?? [];
$connection = $connections[0] ?? [];
// Authentication method is now determined by the device's configuration, not the template.
@endphp

<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label>Connection Name <span class="text-danger">*</span></label>
            <input type="text"
                   class="form-control"
                   name="template_data[connections][0][name]"
                   value="{{ $connection['name'] ?? '' }}"
                   placeholder="e.g., FortiGate API"
                   required>
            <small class="form-text text-muted">Descriptive name for this API connection.</small>
        </div>
    </div>

    <div class="col-md-6">
        <div class="form-group">
            {{-- REMOVED: Credential Selector Field --}}
            <label>Connection Target Type</label>
            <input type="text"
                   class="form-control"
                   name="template_data[connections][0][target_type]"
                   value="{{ $connection['target_type'] ?? 'device' }}"
                   placeholder="device">
            <small class="form-text text-muted">Defines the resource level this connection applies to (e.g., 'device', 'vdom').</small>
        </div>
    </div>
</div>

<div class="form-group">
    <label>Base URL <span class="text-danger">*</span></label>
    <input type="text"
           class="form-control"
           name="template_data[connections][0][base_url]"
           value="{{ $connection['base_url'] ?? '' }}"
           placeholder="https://{device_hostname}"
           required>
    <small class="form-text text-muted">
        Base URL for API calls. Use <code>{device_hostname}</code>, <code>{device_ip}</code>, or <code>{device_sysname}</code> as placeholders.
    </small>
</div>

{{-- REMOVED: Dynamic Connection Parameters Section (Login/Session fields) --}}
{{-- These parameters are now configured in the Credential Settings and applied by AuthManager. --}}


{{-- Connection Settings (Unchanged) --}}
<div class="row">
    <div class="col-md-4">
        <div class="form-group">
            <label>Rate Limit (requests/minute)</label>
            <input type="number"
                   class="form-control"
                   name="template_data[connections][0][rate_limit]"
                   value="{{ $connection['rate_limit'] ?? 60 }}"
                   min="1"
                   max="1000">
            <small class="form-text text-muted">Maximum API requests per minute</small>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label>Timeout (seconds)</label>
            <input type="number"
                   class="form-control"
                   name="template_data[connections][0][timeout]"
                   value="{{ $connection['timeout'] ?? 30 }}"
                   min="5"
                   max="300">
            <small class="form-text text-muted">Request timeout in seconds</small>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label>Retry Attempts</label>
            <input type="number"
                   class="form-control"
                   name="template_data[connections][0][retry_attempts]"
                   value="{{ $connection['retry_attempts'] ?? 3 }}"
                   min="0"
                   max="10">
            <small class="form-text text-muted">Number of retry attempts on failure</small>
        </div>
    </div>
</div>

{{-- SSL/TLS Settings (Unchanged) --}}
<div class="card bg-light">
    <div class="card-header">
        <h6 class="mb-0">
            <i class="fas fa-lock"></i> SSL/TLS Settings
        </h6>
    </div>
    <div class="card-body">
        <div class="custom-control custom-checkbox">
            <input type="hidden" name="template_data[connections][0][disable_ssl_verify]" value="0">
            <input type="checkbox"
                   class="custom-control-input"
                   id="disable_ssl_verify"
                   name="template_data[connections][0][disable_ssl_verify]"
                   value="1"
                   {{ ($connection['disable_ssl_verify'] ?? false) ? 'checked' : '' }}>
            <label class="custom-control-label" for="disable_ssl_verify">
                Disable SSL Certificate Verification
            </label>
        </div>
        <small class="form-text text-muted">
            <i class="fas fa-exclamation-triangle text-warning"></i>
            Enable this for self-signed certificates or testing. Not recommended for production.
        </small>
    </div>
</div>

<script>
// Keep Alpine logic minimal for Base URL preview.
document.addEventListener('alpine:init', () => {
    Alpine.data('connectionPreview', () => ({
        // Bind to input fields for reactive URL preview
        baseUrl: document.querySelector('input[name="template_data[connections][0][base_url]"]').value || '',

        // Removed loginPath logic as it's no longer configured here.

        get fullLoginUrl() {
            // Only return the base URL, or a placeholder
            return this.baseUrl || 'https://{device_hostname}';
        }
    }));
});
</script>