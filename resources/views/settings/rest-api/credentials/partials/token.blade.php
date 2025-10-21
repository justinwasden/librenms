<div class="form-group">
    <label for="params_token">Token</label>
    <div class="input-group">
        <input type="password" 
               name="params[token]" 
               id="params_token" 
               class="form-control" 
               value="{{ old('params.token', optional($credential->params)->firstWhere('key', 'token')->value ?? '') }}" 
               required>
        <div class="input-group-append">
            <span class="input-group-text" id="token-timer-regular" style="display: none;">
                <i class="fas fa-clock"></i> <span class="timer-seconds">5</span>s
            </span>
        </div>
    </div>
    <small class="form-text text-muted">
        <i class="fas fa-info-circle"></i> Click the field to reveal the token for 5 seconds.
    </small>
</div>

<div class="form-group">
    <label for="params_header">Header Name</label>
    <input type="text" name="params[header]" id="params_header" class="form-control" value="{{ old('params.header', optional($credential->params)->firstWhere('key', 'header')->value ?? 'Authorization') }}" required>
    <small class="form-text text-muted">The name of the HTTP header to use for the token (e.g., Authorization, X-API-Key).</small>
</div>

<div class="form-group">
    <label for="params_scheme">Scheme</label>
    <input type="text" name="params[scheme]" id="params_scheme" class="form-control" value="{{ old('params.scheme', optional($credential->params)->firstWhere('key', 'scheme')->value ?? 'Bearer') }}">
    <small class="form-text text-muted">The authentication scheme to prepend to the token (e.g., Bearer, Token). Leave blank if not needed.</small>
</div>