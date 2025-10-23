{{-- resources/views/settings/rest-api/credentials/partials/proxmox.blade.php --}}
<div class="alert alert-info">
    <strong>Proxmox VE API Token Authentication</strong><br>
    Logs in via an API Token created in Proxmox VE, requiring the full user/realm, token ID, and secret.
    (e.g., <code>user@realm!token-id:token-secret</code>)
</div>

<div class="form-group">
    <label for="params_user_realm">User@Realm <span class="text-danger">*</span></label>
    <input type="text" name="params[user_realm]" id="params_user_realm" class="form-control"
           value="{{ old('params.user_realm', optional($credential->params)->firstWhere('key', 'user_realm')->value ?? '') }}"
           placeholder="e.g., root@pam or user@pve" required>
    <small class="form-text text-muted">The full user name and realm (e.g., root@pam, or john@pve).</small>
</div>

<div class="form-group">
    <label for="params_token_id">Token ID <span class="text-danger">*</span></label>
    <input type="text" name="params[token_id]" id="params_token_id" class="form-control"
           value="{{ old('params.token_id', optional($credential->params)->firstWhere('key', 'token_id')->value ?? '') }}"
           placeholder="e.g., libreNMS-api-token" required>
    <small class="form-text text-muted">The unique ID of the API token.</small>
</div>

<div class="form-group">
    <label for="params_token_secret">Token Secret <span class="text-danger">*</span></label>
    <div class="input-group">
        {{-- Use password type for security, same as other token/password fields --}}
        <input type="password" name="params[token_secret]" id="params_token_secret" class="form-control"
               value="{{ old('params.token_secret', optional($credential->params)->firstWhere('key', 'token_secret')->value ?? '') }}"
               required>
        <div class="input-group-append">
            {{-- Token reveal elements are included here for consistency with other token forms --}}
            <span class="input-group-text" id="token-timer-regular" style="display: none;">
                <i class="fas fa-clock"></i> <span class="timer-seconds">5</span>s
            </span>
        </div>
    </div>
    <small class="form-text text-muted">The Secret generated when creating the API token. Click the field to reveal for 5 seconds.</small>
</div>

<div class="form-group">
    <label for="params_verify_ssl">Verify SSL</label>
    @php
        // Keep the verify_ssl option as it's common for infrastructure APIs
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