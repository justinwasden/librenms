@php
    $credential = $credential ?? new \App\Models\RestApiCredential();
    $header = old('params.header', $credential->params->where('key', 'header')->first()->value ?? 'Authorization');
    $scheme = old('params.scheme', $credential->params->where('key', 'scheme')->first()->value ?? 'Bearer');
    $isEdit = $credential->exists;
@endphp

<div class="form-group">
    <label for="params_token">Token</label>
    <input type="password"
           name="params[token]"
           id="params_token"
           class="form-control"
           placeholder="{{ $isEdit ? '(Leave blank to keep current)' : '' }}"
           {{ $isEdit ? '' : 'required' }}>
    @if($isEdit)
        <small class="form-text text-muted">Leave blank to keep the current token.</small>
    @endif
</div>

<div class="form-group">
    <label for="params_header">Header Name</label>
    <input type="text"
           name="params[header]"
           id="params_header"
           class="form-control"
           value="{{ $header }}"
           required>
    <small class="form-text text-muted">The name of the HTTP header to use for the token (e.g., Authorization, X-API-Key).</small>
</div>

<div class="form-group">
    <label for="params_scheme">Scheme</label>
    <input type="text"
           name="params[scheme]"
           id="params_scheme"
           class="form-control"
           value="{{ $scheme }}">
    <small class="form-text text-muted">The authentication scheme to prepend to the token (e.g., Bearer, Token). Leave blank if not needed.</small>
</div>