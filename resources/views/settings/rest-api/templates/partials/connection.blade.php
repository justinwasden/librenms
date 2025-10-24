@php
use App\Models\RestApiCredential;

$connections = $template->template_data['connections'] ?? [];
$connection = $connections[0] ?? [];

// Get the credential object if one is selected, to determine the auth type
$selectedCredential = null;
if (!empty($connection['credential_id'])) {
    $selectedCredential = RestApiCredential::find($connection['credential_id']);
}
$authType = $selectedCredential->authenticationType->name ?? 'None';
$authTypeSlug = \Illuminate\Support\Str::slug($authType, '-');

// This logic can be removed if the template structure doesn't store login paths
// but we'll leave the code structure for dynamic parameters here.
@endphp

<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label>Connection Name <span class="text-danger">*</span></label>
            <input type="text"
                   class="form-control"
                   name="template_data[connections][0][name]"
                   value="{{ $connection['name'] ?? '' }}"
                   placeholder="e.g., PureStorage API"
                   required>
            <small class="form-text text-muted">Descriptive name for this API connection</small>
        </div>
    </div>

    <div class="col-md-6">
        <div class="form-group">
            <label>Credential</label>
            <select class="form-control" name="template_data[connections][0][credential_id]" id="connection_credential_id">
                <option value="">None (No Authentication)</option>
                @foreach(\App\Models\RestApiCredential::orderBy('name')->get() as $credential)
                    <option value="{{ $credential->id }}"
                            {{ ($connection['credential_id'] ?? '') == $credential->id ? 'selected' : '' }}>
                        {{ $credential->name }}
                        ({{ $credential->authenticationType->name ?? 'Unknown' }})
                    </option>
                @endforeach
            </select>
            <small class="form-text text-muted">Authentication credentials for this API</small>
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
        Base URL for API calls. Use <code>{device_hostname}</code>, <code>{device_ip}</code>, or <code>{device_sysname}</code> as placeholders
    </small>
</div>

{{-- Dynamic Credential Parameters Section --}}
{{-- This section will dynamically display custom connection parameters ONLY if the linked credential type requires them --}}
<div id="connection_specific_params">
    @if ($authTypeSlug === 'session-token' || $authTypeSlug === 'proxmox' )
        <div class="card bg-light mb-3">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="fas fa-sign-in-alt"></i> Auth Configuration (<span class="text-primary">{{ $authType }}</span>)
                </h6>
            </div>
            <div class="card-body">
                <div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle"></i>
                    The credential type **{{ $authType }}** requires connection details.
                </div>

                {{-- Include a generalized form for auth-specific connection params --}}
                @include('settings.rest-api.templates.partials.conn-auth-params', [
                    'connection' => $connection,
                    'authType' => $authTypeSlug
                ])
            </div>
        </div>
    @endif
</div>


{{-- Connection Settings --}}
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

{{-- SSL/TLS Settings --}}
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
// JavaScript to update the dynamic credential params section when the selection changes.
document.addEventListener('DOMContentLoaded', function() {
    const credSelect = document.getElementById('connection_credential_id');
    const paramsContainer = document.getElementById('connection_specific_params');

    credSelect.addEventListener('change', function() {
        const selectedOption = credSelect.options[credSelect.selectedIndex];
        const authTypeLabel = selectedOption.textContent.match(/\(([^)]+)\)/)?.[1] || 'None';
        const authTypeSlug = authTypeLabel.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

        // Only show the specific params section for complex authentication types
        if (['session-token', 'proxmox'].includes(authTypeSlug)) {
            // In a real implementation, you would make an AJAX call here to fetch the conn-auth-params partial
            // to populate paramsContainer dynamically, passing the credential ID and connection data.

            // For now, we simulate the structure based on the pre-selected values
            paramsContainer.innerHTML = `
                <div class="card bg-light mb-3">
                    <div class="card-header"><h6 class="mb-0"><i class="fas fa-sign-in-alt"></i> Auth Configuration (<span class="text-primary">${authTypeLabel}</span>)</h6></div>
                    <div class="card-body">
                        <div class="alert alert-warning mb-3">
                            <i class="fas fa-exclamation-triangle"></i>
                            This template requires an AJAX call to fully load
                            the parameters for <strong>${authTypeLabel}</strong>.
                            Please save and edit again, or ensure the server is configured.
                        </div>

                        <p><strong>Required Conn Params (Manual Entry):</strong></p>
                        <ul class="text-muted small">
                            ${authTypeSlug === 'session-token' ? '<li>Login Path</li><li>Session Token Header</li>' : '<li>ProxMox specific params...</li>'}
                        </ul>
                    </div>
                </div>
            `;
        } else {
            paramsContainer.innerHTML = '';
        }
    });
});

// Initial load trigger (if a credential was already selected)
document.addEventListener('DOMContentLoaded', function() {
    const credSelect = document.getElementById('connection_credential_id');
    if (credSelect.value) {
        credSelect.dispatchEvent(new Event('change'));
    }
});

// Update preview in real-time
document.addEventListener('alpine:init', () => {
    Alpine.data('connectionPreview', () => ({
        baseUrl: '{{ $connection['base_url'] ?? '' }}',
        loginPath: '{{ $connection['login_path'] ?? '' }}',

        get fullLoginUrl() {
            return (this.baseUrl || 'https://{device_hostname}') + (this.loginPath || '/api/login');
        }
    }));
});
</script>