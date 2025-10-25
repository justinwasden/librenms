{{-- resources/views/device/partials/device_api.blade.php --}}
<div class="card mt-3">
    <div class="card-header">Device API</div>
    <div class="card-body">
        <div class="alert alert-info">
            Configure per-device Device API polling. Enable this only for devices with supported vendor APIs (Pure Storage, Proxmox).
        </div>

        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="rest_enabled" name="rest_enabled" value="1"
                   {{ old('rest_enabled', $device->attribs['rest_enabled'] ?? 0) ? 'checked' : '' }}>
            <label class="form-check-label" for="rest_enabled">Enable Device API discovery/polling</label>
        </div>

        <div class="mb-3">
            <label class="form-label">Vendor</label>
            @php $vendor = old('rest_vendor', $device->attribs['rest_vendor'] ?? ''); @endphp
            <select class="form-select" name="rest_vendor">
                <option value="" {{ $vendor === '' ? 'selected' : '' }}>Select a vendor</option>
                <option value="purestorage" {{ $vendor === 'purestorage' ? 'selected' : '' }}>Pure Storage (FlashArray)</option>
                <option value="proxmox" {{ $vendor === 'proxmox' ? 'selected' : '' }}>Proxmox VE</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Base URL</label>
            <input type="url" class="form-control" name="rest_base_url"
                   value="{{ old('rest_base_url', $device->attribs['rest_base_url'] ?? $device->attribs['proxmox_base_url'] ?? '') }}"
                   placeholder="https://array.example/api/2.26 or https://pve.example:8006">
            <small class="text-muted">Pure Storage: https://array/api/2.26 • Proxmox: https://host:8006</small>
        </div>

        <div class="mb-3">
            <label class="form-label">Auth Type</label>
            @php $auth = old('rest_auth_type', $device->attribs['rest_auth_type'] ?? ($device->attribs['proxmox_auth_type'] ?? '')); @endphp
            <select class="form-select" name="rest_auth_type">
                <option value="" {{ $auth === '' ? 'selected' : '' }}>Select auth</option>
                <option value="apikey" {{ $auth === 'apikey' ? 'selected' : '' }}>API Key / Token (Pure Storage)</option>
                <option value="bearer" {{ $auth === 'bearer' ? 'selected' : '' }}>Bearer Token</option>
                <option value="basic" {{ $auth === 'basic' ? 'selected' : '' }}>Basic (username/password)</option>
                <option value="token" {{ $auth === 'token' ? 'selected' : '' }}>Proxmox API Token</option>
                <option value="ticket" {{ $auth === 'ticket' ? 'selected' : '' }}>Proxmox Ticket (username/password)</option>
            </select>
        </div>

        {{-- Generic Token / API Key --}}
        <div class="mb-3">
            <label class="form-label">Token / API Key</label>
            <input type="password" class="form-control" name="rest_token"
                   placeholder="Enter to set or replace" value="">
            @if(!empty($device->attribs['rest_token_enc']))
                <small class="text-muted">A token is stored. Enter a new value to replace.</small>
            @endif
        </div>

        {{-- Basic auth --}}
        <div class="mb-3">
            <label class="form-label">Basic Auth Username</label>
            <input type="text" class="form-control" name="rest_username"
                   value="{{ old('rest_username') }}">
        </div>
        <div class="mb-3">
            <label class="form-label">Basic Auth Password</label>
            <input type="password" class="form-control" name="rest_password" value="">
            @if(!empty($device->attribs['rest_password_enc']))
                <small class="text-muted">A password is stored. Enter a new value to replace.</small>
            @endif
        </div>

        {{-- Proxmox API Token --}}
        <div class="mb-3">
            <label class="form-label">Proxmox Token User@Realm</label>
            <input type="text" class="form-control" name="proxmox_token_user"
                   value="{{ old('proxmox_token_user', $device->attribs['proxmox_token_user'] ?? '') }}"
                   placeholder="user@pve">
        </div>
        <div class="mb-3">
            <label class="form-label">Proxmox Token ID</label>
            <input type="text" class="form-control" name="proxmox_token_id"
                   value="{{ old('proxmox_token_id', $device->attribs['proxmox_token_id'] ?? '') }}"
                   placeholder="tokenid">
        </div>
        <div class="mb-3">
            <label class="form-label">Proxmox Token Secret</label>
            <input type="password" class="form-control" name="proxmox_token"
                   placeholder="Enter to set or replace" value="">
            @if(!empty($device->attribs['proxmox_token_enc']))
                <small class="text-muted">A token secret is stored. Enter a new value to replace.</small>
            @endif
        </div>

        {{-- Proxmox ticket (username/password) --}}
        <div class="mb-3">
            <label class="form-label">Proxmox Username@Realm</label>
            <input type="text" class="form-control" name="proxmox_username"
                   value="{{ old('proxmox_username', $device->attribs['proxmox_username'] ?? '') }}"
                   placeholder="root@pam">
        </div>
        <div class="mb-3">
            <label class="form-label">Proxmox Password</label>
            <input type="password" class="form-control" name="proxmox_password" value="">
            @if(!empty($device->attribs['proxmox_password_enc']))
                <small class="text-muted">A password is stored. Enter a new value to replace.</small>
            @endif
        </div>

        <div class="mb-3">
            <label class="form-label">Extra Headers (JSON)</label>
            <textarea class="form-control" name="rest_headers" rows="2"
                      placeholder='{"X-Org":"netops"}'>{{ old('rest_headers', $device->attribs['rest_headers'] ?? '') }}</textarea>
        </div>

        <div class="form-check mb-3">
            @php $verify = old('rest_verify_tls', $device->attribs['rest_verify_tls'] ?? $device->attribs['proxmox_verify_tls'] ?? 1); @endphp
            <input class="form-check-input" type="checkbox" id="rest_verify_tls" name="rest_verify_tls" value="1"
                   {{ $verify ? 'checked' : '' }}>
            <label class="form-check-label" for="rest_verify_tls">Verify TLS certificates</label>
        </div>

        <div class="mb-3">
            <label class="form-label">Timeout (ms)</label>
            <input type="number" class="form-control" name="rest_timeout_ms"
                   value="{{ old('rest_timeout_ms', $device->attribs['rest_timeout_ms'] ?? $device->attribs['proxmox_timeout_ms'] ?? 5000) }}">
        </div>

        <div class="mb-3">
            <label class="form-label">Proxy (optional)</label>
            <input type="text" class="form-control" name="rest_proxy"
                   value="{{ old('rest_proxy', $device->attribs['rest_proxy'] ?? $device->attribs['proxmox_proxy'] ?? '') }}"
                   placeholder="http://user:pass@proxy:3128">
        </div>
    </div>
</div>