<div class="form-group">
    <label for="params_token">Token</label>
    <input type="password" name="params[token]" id="params_token" class="form-control" value="{{ old('params.token', $credential->params->firstWhere('key', 'token')->value ?? '') }}" required>
</div>

<div class="form-group">
    <label for="params_header">Header Name</label>
    <input type="text" name="params[header]" id="params_header" class="form-control" value="{{ old('params.header', $credential->params->firstWhere('key', 'header')->value ?? 'Authorization') }}" required>
    <small class="form-text text-muted">The name of the HTTP header to use for the token (e.g., Authorization, X-API-Key).</small>
</div>

<div class="form-group">
    <label for="params_scheme">Scheme</label>
    <input type="text" name="params[scheme]" id="params_scheme" class="form-control" value="{{ old('params.scheme', $credential->params->firstWhere('key', 'scheme')->value ?? 'Bearer') }}">
    <small class="form-text text-muted">The authentication scheme to prepend to the token (e.g., Bearer, Token). Leave blank if not needed.</small>
</div>