@php
    $credential = $credential ?? new \App\Models\RestApiCredential();
    $username = old('params.username', $credential->params->where('key', 'username')->first()->value ?? '');
    $isEdit = $credential->exists;
@endphp

<div class="form-group">
    <label for="params_username">Username</label>
    <input type="text"
           name="params[username]"
           id="params_username"
           class="form-control"
           value="{{ $username }}"
           required>
</div>

<div class="form-group">
    <label for="params_password">Password</label>
    <input type="password"
           name="params[password]"
           id="params_password"
           class="form-control"
           placeholder="{{ $isEdit ? '(Leave blank to keep current)' : '' }}"
           {{ $isEdit ? '' : 'required' }}>
    @if($isEdit)
        <small class="form-text text-muted">Leave blank to keep the current password.</small>
    @endif
</div>