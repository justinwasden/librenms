{{-- resources/views/settings/rest-api/credentials/partials/proxmox-api-token.blade.php --}}
<div class="alert alert-info">
    <strong>Proxmox VE API Token Authentication</strong><br>
    Sends Authorization header <code>PVEAPIToken=&lt;user@realm&gt;!&lt;tokenid&gt;=&lt;tokensecret&gt;</code> for requests.
</div>

<div class="form-group">
    <label for="params_user_realm">User@Realm <span class="text-danger">*</span></label>
    <input type="text" name="params[user_realm]" id="params_user_realm" class="form-control"
           value="{{ old('params.user_realm', optional($credential->params)->firstWhere('key', 'user_realm')->value ?? '') }}"
           placeholder="e.g., root@pam" required>
</div>

<div class="form-group">
    <label for="params_token_id">Token ID <span class="text-danger">*</span></label>
    <input type="text" name="params[token_id]" id="params_token_id" class="form-control"
           value="{{ old('params.token_id', optional($credential->params)->firstWhere('key', 'token_id')->value ?? '') }}" required>
</div>

<div class="form-group">
    <label for="params_token_secret">Token Secret <span class="text-danger">*</span></label>
    <input type="password" name="params[token_secret]" id="params_token_secret" class="form-control"
           value="{{ old('params.token_secret', optional($credential->params)->firstWhere('key', 'token_secret')->value ?? '') }}" required>
</div>

<div class="form-group">
    <label for="params_verify_ssl">Verify SSL</label>
    @php
        $verify = old('params.verify_ssl', optional($credential->params)->firstWhere('key', 'verify_ssl')->value ?? '1');
    @endphp
    <select name="params[verify_ssl]" id="params_verify_ssl" class="form-control">
        <option value="1" {{ $verify == '1' ? 'selected' : '' }}>Enabled</option>
        <option value="0" {{ $verify == '0' ? 'selected' : '' }}>Disabled (Self-signed)</option>
    </select>
</div>

<small class="text-muted d-block mt-2">
    Base URL example: <code>https://your-proxmox-host:8006</code> (set this on the connection).
</small>