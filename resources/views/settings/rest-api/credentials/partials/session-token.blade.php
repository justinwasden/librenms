<div class="alert alert-info">
    <strong>Session Token Authentication</strong><br>
    This authentication method is for APIs that require a two-step process: first login to get a session token, then use that token for subsequent requests (e.g., PureStorage FlashArray REST API 2.x).
</div>

<div class="form-group">
    <label for="params_api_token">API Token <span class="text-danger">*</span></label>
    <div class="input-group">
        <input type="password" 
               name="params[api_token]" 
               id="params_api_token" 
               class="form-control" 
               value="{{ old('params.api_token', optional($credential->params)->firstWhere('key', 'api_token')->value ?? '') }}" 
               required>
        <div class="input-group-append">
            <span class="input-group-text" id="token-timer" style="display: none;">
                <i class="fas fa-clock"></i> <span id="timer-seconds">5</span>s
            </span>
        </div>
    </div>
    <small class="form-text text-muted">
        <i class="fas fa-info-circle"></i> Click the field to reveal the API token for 5 seconds.
    </small>
</div>

<div class="form-group">
    <label for="params_login_path">Login Path <span class="text-danger">*</span></label>
    <input type="text" name="params[login_path]" id="params_login_path" class="form-control" 
           value="{{ old('params.login_path', optional($credential->params)->firstWhere('key', 'login_path')->value ?? '') }}" 
           placeholder="api/2.26/login" required>
    <small class="form-text text-muted">The relative path to the login endpoint (e.g., api/2.26/login for PureStorage).</small>
</div>

<div class="form-group">
    <label for="params_token_header">Session Token Header</label>
    <input type="text" 
           name="params[token_header]" 
           id="params_token_header" 
           class="form-control" 
           value="{{ old('params.token_header', optional($credential->params)->firstWhere('key', 'token_header')->value ?? 'x-auth-token') }}">
    <small class="form-text text-muted">The response header name containing the session token (default: x-auth-token).</small>
</div>

<div class="form-group">
    <label for="params_api_token_header">API Token Header</label>
    <input type="text" 
           name="params[api_token_header]" 
           id="params_api_token_header" 
           class="form-control" 
           value="{{ old('params.api_token_header', optional($credential->params)->firstWhere('key', 'api_token_header')->value ?? 'api-token') }}">
    <small class="form-text text-muted">The request header name for sending the API token to the login endpoint (default: api-token).</small>
</div>

<div class="form-group">
    <label for="params_login_method">Login Method</label>
    <select name="params[login_method]" id="params_login_method" class="form-control">
        @php
            $currentMethod = old('params.login_method', optional($credential->params)->firstWhere('key', 'login_method')->value ?? 'POST');
        @endphp
        <option value="POST" {{ $currentMethod == 'POST' ? 'selected' : '' }}>POST</option>
        <option value="GET" {{ $currentMethod == 'GET' ? 'selected' : '' }}>GET</option>
        <option value="PUT" {{ $currentMethod == 'PUT' ? 'selected' : '' }}>PUT</option>
    </select>
    <small class="form-text text-muted">The HTTP method to use for the login request (default: POST).</small>
</div>

<div class="form-group">
    <label for="params_session_ttl">Session Cache TTL (seconds)</label>
    <input type="number" name="params[session_ttl]" id="params_session_ttl" class="form-control" 
           value="{{ old('params.session_ttl', optional($credential->params)->firstWhere('key', 'session_ttl')->value ?? '3600') }}" 
           min="60" step="60">
    <small class="form-text text-muted">How long to cache the session token before re-authenticating (default: 3600 seconds / 1 hour).</small>
</div>

<hr>

<div class="card bg-light">
    <div class="card-body">
        <h5 class="card-title">Example: PureStorage FlashArray</h5>
        <p class="card-text"><strong>Typical values for PureStorage FlashArray REST API 2.x:</strong></p>
        <ul class="mb-0">
            <li><strong>API Token:</strong> Your PureStorage API token from Settings > Access > Users</li>
            <li><strong>Login Path:</strong> <code>api/2.26/login</code> (adjust version as needed)</li>
            <li><strong>Session Token Header:</strong> <code>x-auth-token</code></li>
            <li><strong>API Token Header:</strong> <code>api-token</code></li>
            <li><strong>Login Method:</strong> POST</li>
        </ul>
    </div>
</div>
