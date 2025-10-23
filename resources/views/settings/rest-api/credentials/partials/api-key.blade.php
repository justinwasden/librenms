<div class="alert alert-info">
    <strong>API Key Authentication</strong><br>
    The key is sent either in a custom HTTP header or as a query parameter.
</div>

<div class="form-group">
    <label for="params_key_value">API Key Value <span class="text-danger">*</span></label>
    <div class="input-group">
        {{-- Input type is password for security, includes a reveal feature handled by JS --}}
        <input type="password"
               name="params[key_value]"
               id="params_key_value"
               class="form-control"
               value="{{ old('params.key_value', optional($credential->params)->firstWhere('key', 'key_value')->value ?? '') }}"
               required>
        <div class="input-group-append">
            <span class="input-group-text" id="token-timer-regular" style="display: none;">
                <i class="fas fa-clock"></i> <span class="timer-seconds">5</span>s
            </span>
        </div>
    </div>
    <small class="form-text text-muted">
        <i class="fas fa-info-circle"></i> Click the field to reveal the key for 5 seconds.
    </small>
</div>

<div class="form-group">
    <label for="params_key_name">Key Name <span class="text-danger">*</span></label>
    <input type="text" name="params[key_name]" id="params_key_name" class="form-control"
           value="{{ old('params.key_name', optional($credential->params)->firstWhere('key', 'key_name')->value ?? 'X-API-Key') }}"
           placeholder="e.g., X-API-Key or apikey" required>
    <small class="form-text text-muted">The name of the header or query parameter (e.g., X-API-Key).</small>
</div>

<div class="form-group">
    <label for="params_key_location">Key Location</label>
    @php
        $currentLocation = old('params.key_location', optional($credential->params)->firstWhere('key', 'key_location')->value ?? 'header');
    @endphp
    <select name="params[key_location]" id="params_key_location" class="form-control">
        <option value="header" {{ $currentLocation == 'header' ? 'selected' : '' }}>Header</option>
        <option value="query" {{ $currentLocation == 'query' ? 'selected' : '' }}>Query Parameter</option>
    </select>
    <small class="form-text text-muted">Where the API key should be sent in the request.</small>
</div>