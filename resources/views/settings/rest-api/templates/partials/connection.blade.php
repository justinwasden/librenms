@php
$connections = $template->template_data['connections'] ?? [];
$connection = $connections[0] ?? [];
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
            <select class="form-control" name="template_data[connections][0][credential_id]">
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

{{-- Login Endpoint Configuration --}}
<div class="card bg-light mb-3">
    <div class="card-header">
        <h6 class="mb-0">
            <i class="fas fa-sign-in-alt"></i> Login/Authentication Endpoint
            <small class="text-muted">(Optional - for session-based auth)</small>
        </h6>
    </div>
    <div class="card-body">
        <div class="alert alert-info mb-3">
            <i class="fas fa-info-circle"></i>
            <strong>When to use this:</strong> If your API requires logging in to get a session token
            (like PureStorage's <code>/api/2.26/login</code>), configure it here.
        </div>

        <div class="row">
            <div class="col-md-8">
                <div class="form-group">
                    <label>Login Path</label>
                    <input type="text"
                           class="form-control"
                           name="template_data[connections][0][login_path]"
                           value="{{ $connection['login_path'] ?? '' }}"
                           placeholder="/api/2.26/login">
                    <small class="form-text text-muted">
                        Path to the login endpoint (appended to Base URL).
                        Example: <code>/api/2.26/login</code>
                    </small>
                </div>
            </div>

            <div class="col-md-4">
                <div class="form-group">
                    <label>Login Method</label>
                    <select class="form-control" name="template_data[connections][0][login_method]">
                        <option value="POST" {{ ($connection['login_method'] ?? 'POST') === 'POST' ? 'selected' : '' }}>POST</option>
                        <option value="GET" {{ ($connection['login_method'] ?? '') === 'GET' ? 'selected' : '' }}>GET</option>
                        <option value="PUT" {{ ($connection['login_method'] ?? '') === 'PUT' ? 'selected' : '' }}>PUT</option>
                    </select>
                    <small class="form-text text-muted">HTTP method for login</small>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="form-group">
                    <label>Session Token Header</label>
                    <input type="text"
                           class="form-control"
                           name="template_data[connections][0][session_token_header]"
                           value="{{ $connection['session_token_header'] ?? '' }}"
                           placeholder="x-auth-token">
                    <small class="form-text text-muted">
                        Header name where session token is returned
                    </small>
                </div>
            </div>

            <div class="col-md-6">
                <div class="form-group">
                    <label>API Token Header</label>
                    <input type="text"
                           class="form-control"
                           name="template_data[connections][0][api_token_header]"
                           value="{{ $connection['api_token_header'] ?? '' }}"
                           placeholder="api-token">
                    <small class="form-text text-muted">
                        Header name for sending API token during login
                    </small>
                </div>
            </div>
        </div>

        <div class="form-group mb-0">
            <label>Login Request Body (JSON) <small class="text-muted">(Optional)</small></label>
            <textarea class="form-control font-monospace"
                      rows="3"
                      name="template_data[connections][0][login_body]"
                      placeholder='{"username": "admin", "password": "secret"}'>{{ $connection['login_body'] ?? '' }}</textarea>
            <small class="form-text text-muted">
                JSON body to send with login request. Leave empty if authentication is header-based only.
            </small>
        </div>

        {{-- Preview of full login URL --}}
        <div class="mt-3 p-2 bg-white border rounded" x-data="{ baseUrl: '{{ $connection['base_url'] ?? '' }}', loginPath: '{{ $connection['login_path'] ?? '' }}' }">
            <small class="text-muted"><strong>Full Login URL Preview:</strong></small>
            <div class="font-monospace text-primary">
                <span x-text="baseUrl || 'https://{device_hostname}'"></span><span x-text="loginPath || '/api/login'"></span>
            </div>
        </div>
    </div>
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