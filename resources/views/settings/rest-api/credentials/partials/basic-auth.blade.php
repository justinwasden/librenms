<div class="form-group">
    <label for="params_username">Username</label>
    <input type="text" name="params[username]" id="params_username" class="form-control"
           value="{{ old('params.username', $credential->params->where('key', 'username')->first()->value ?? '') }}"
           required>
</div>

<div class="form-group">
    <label for="params_password">Password</label>
    <input type="password" name="params[password]" id="params_password" class="form-control"
           placeholder="{{ isset($credential) && $credential->exists ? '(Leave blank to keep current)' : '' }}"
           {{ isset($credential) && $credential->exists ? '' : 'required' }}>
    @if(isset($credential) && $credential->exists)
        <small class="form-text text-muted">Leave blank to keep the current password.</small>
    @endif
</div>