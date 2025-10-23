{{-- resources/views/settings/rest-api/credentials/partials/proxmox.blade.php --}}
<div class="alert alert-info">
    <strong>Proxmox VE Authentication</strong><br>
    Logs in via <code>/api2/json/access/ticket</code> and uses <code>PVEAuthCookie</code> (cookie) and <code>CSRFPreventionToken</code> (header) for requests.
</div>

<div class="form-group">
    <label for="params_username">Username <span class="text-danger">*</span></label>
    <input type="text" name="params[username]" id="params_username" class="form-control"
           value="{{ old('params.username', optional($credential->params)->firstWhere('key', 'username')->value ?? '') }}"
           placeholder="e.g., root" required>
</div>

<div class="form-group">
    <label for="params_password">Password <span class="text-danger">*</span></label>
    <input type="password" name="params[password]" id="params_password" class="form-control"
           value="{{ old('params.password', optional($credential->params)->firstWhere('key', 'password')->value ?? '') }}" required>
</div>

<div class="form-group">
    <label for="params_realm">Realm</label>
    @php
        $currentRealm = old('params.realm', optional($credential->params)->firstWhere('key', 'realm')->value ?? 'pam');
    @endphp
    <select name="params[realm]" id="params_realm" class="form-control">
        <option value="pam" {{ $currentRealm == 'pam' ? 'selected' : '' }}>pam</option>
        <option value="pve" {{ $currentRealm == 'pve' ? 'selected' : '' }}>pve</option>
        <option value="ldap" {{ $currentRealm == 'ldap' ? 'selected' : '' }}>ldap</option>
        <option value="ad" {{ $currentRealm == 'ad' ? 'selected' : '' }}>ad</option>
    </select>
    <small class="form-text text-muted">Typical realm is pam or pve</small>
</div>

<div class="form-group">
    <label for="params_session_ttl">Session TTL (seconds)</label>
    <input type="number" name="params[session_ttl]" id="params_session_ttl" class="form-control"
           value="{{ old('params.session_ttl', optional($credential->params)->firstWhere('key', 'session_ttl')->value ?? '3600') }}"
           min="60" step="60">
    <small class="form-text text-muted">How long to cache the session token before re-authenticating (default: 3600).</small>
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