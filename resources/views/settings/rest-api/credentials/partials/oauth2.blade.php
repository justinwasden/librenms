<div class="alert alert-info">
    <strong>OAuth2 Client Credentials Grant</strong><br>
    Obtains an access token via a POST request to the Token URL using Client ID and Client Secret.
</div>

<div class="form-group">
    <label for="params_client_id">Client ID <span class="text-danger">*</span></label>
    <input type="text" name="params[client_id]" id="params_client_id" class="form-control"
           value="{{ old('params.client_id', optional($credential->params)->firstWhere('key', 'client_id')->value ?? '') }}"
           required>
</div>

<div class="form-group">
    <label for="params_client_secret">Client Secret <span class="text-danger">*</span></label>
    {{-- Client Secret is treated as a password/token and should be obscured --}}
    <input type="password" name="params[client_secret]" id="params_client_secret" class="form-control"
           value="{{ old('params.client_secret', optional($credential->params)->firstWhere('key', 'client_secret')->value ?? '') }}"
           required>
</div>

<div class="form-group">
    <label for="params_token_url">Token URL <span class="text-danger">*</span></label>
    <input type="url" name="params[token_url]" id="params_token_url" class="form-control"
           value="{{ old('params.token_url', optional($credential->params)->firstWhere('key', 'token_url')->value ?? '') }}"
           placeholder="https://api.example.com/oauth/token" required>
    <small class="form-text text-muted">The full endpoint URL for obtaining an access token.</small>
</div>

<div class="form-group">
    <label for="params_scope">Scope (Optional)</label>
    <input type="text" name="params[scope]" id="params_scope" class="form-control"
           value="{{ old('params.scope', optional($credential->params)->firstWhere('key', 'scope')->value ?? '') }}"
           placeholder="read write">
    <small class="form-text text-muted">A space-separated list of scopes required for the token.</small>
</div>

<div class="form-group">
    <label for="params_session_ttl">Token Cache TTL (seconds)</label>
    <input type="number" name="params[session_ttl]" id="params_session_ttl" class="form-control"
           value="{{ old('params.session_ttl', optional($credential->params)->firstWhere('key', 'session_ttl')->value ?? '3600') }}"
           min="60" step="60">
    <small class="form-text text-muted">How long to cache the OAuth2 access token before refreshing (default: 3600).</small>
</div>