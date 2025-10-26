{{-- resources/views/device/partials/device_api.blade.php --}}
@if(!empty($device->attribs['rest_last_error_message']))
    <div class="alert alert-warning">
        <strong>Last Error:</strong> {{ $device->attribs['rest_last_error_message'] }}
        @if(!empty($device->attribs['rest_last_error']))
            <br><small>{{ \Carbon\Carbon::createFromTimestamp($device->attribs['rest_last_error'])->diffForHumans() }}</small>
        @endif
    </div>
@endif

<div class="form-group">
    <div class="col-sm-offset-2 col-sm-6">
        <div class="checkbox">
            <label>
                <input type="checkbox" id="rest_enabled" name="rest_enabled" value="1"
                       {{ old('rest_enabled', $device->attribs['rest_enabled'] ?? 0) ? 'checked' : '' }}>
                <strong>Enable REST API discovery/polling</strong>
            </label>
        </div>
    </div>
</div>

<div class="form-group">
    <label for="rest_vendor" class="col-sm-2 control-label">Vendor <span class="text-danger">*</span></label>
    <div class="col-sm-6">
        @php $vendor = old('rest_vendor', $device->attribs['rest_vendor'] ?? ''); @endphp
        <select class="form-control" id="rest_vendor" name="rest_vendor">
            <option value="" {{ $vendor === '' ? 'selected' : '' }}>Select a vendor...</option>
            <option value="purestorage" {{ $vendor === 'purestorage' ? 'selected' : '' }}>Pure Storage (FlashArray)</option>
            <option value="proxmox" {{ $vendor === 'proxmox' ? 'selected' : '' }}>Proxmox VE</option>
            <option value="generic" {{ $vendor === 'generic' ? 'selected' : '' }}>Generic REST API</option>
        </select>
        <small class="text-muted">Select the vendor to auto-populate recommended settings.</small>
    </div>
</div>

<div class="form-group">
    <label for="rest_base_url" class="col-sm-2 control-label">Base URL</label>
    <div class="col-sm-6">
        <input type="url" id="rest_base_url" class="form-control" name="rest_base_url"
               value="{{ old('rest_base_url', $device->attribs['rest_base_url'] ?? $device->attribs['proxmox_base_url'] ?? '') }}"
               placeholder="https://array.example/api/2.26 or https://pve.example:8006">
        <small class="text-muted">Pure Storage: https://array/api/2.26 • Proxmox: https://host:8006</small>
    </div>
</div>

<div class="form-group">
    <label for="rest_auth_type" class="col-sm-2 control-label">Auth Type</label>
    <div class="col-sm-6">
        @php $auth = old('rest_auth_type', $device->attribs['rest_auth_type'] ?? ($device->attribs['proxmox_auth_type'] ?? '')); @endphp
        <select class="form-control" id="rest_auth_type" name="rest_auth_type">
            <option value="" {{ $auth === '' ? 'selected' : '' }}>Select auth</option>
            <option value="apikey" {{ $auth === 'apikey' ? 'selected' : '' }}>API Key / Token (Pure Storage)</option>
            <option value="bearer" {{ $auth === 'bearer' ? 'selected' : '' }}>Bearer Token</option>
            <option value="basic" {{ $auth === 'basic' ? 'selected' : '' }}>Basic (username/password)</option>
            <option value="token" {{ $auth === 'token' ? 'selected' : '' }}>Proxmox API Token</option>
            <option value="ticket" {{ $auth === 'ticket' ? 'selected' : '' }}>Proxmox Ticket (username/password)</option>
        </select>
    </div>
</div>

<div class="form-group">
    <label for="rest_token" class="col-sm-2 control-label">Token / API Key</label>
    <div class="col-sm-6">
        <input type="password" id="rest_token" class="form-control" name="rest_token"
               placeholder="Enter to set or replace" value="">
        @if(!empty($device->attribs['rest_token_enc']))
            <small class="text-muted">A token is stored. Enter a new value to replace.</small>
        @endif
    </div>
</div>

<div class="form-group">
    <label for="rest_username" class="col-sm-2 control-label">Basic Auth Username</label>
    <div class="col-sm-6">
        <input type="text" id="rest_username" class="form-control" name="rest_username"
               value="{{ old('rest_username') }}">
    </div>
</div>

<div class="form-group">
    <label for="rest_password" class="col-sm-2 control-label">Basic Auth Password</label>
    <div class="col-sm-6">
        <input type="password" id="rest_password" class="form-control" name="rest_password" value="">
        @if(!empty($device->attribs['rest_password_enc']))
            <small class="text-muted">A password is stored. Enter a new value to replace.</small>
        @endif
    </div>
</div>

<div class="form-group">
    <label for="proxmox_token_user" class="col-sm-2 control-label">Proxmox Token User@Realm</label>
    <div class="col-sm-6">
        <input type="text" id="proxmox_token_user" class="form-control" name="proxmox_token_user"
               value="{{ old('proxmox_token_user', $device->attribs['proxmox_token_user'] ?? '') }}"
               placeholder="user@pve">
    </div>
</div>

<div class="form-group">
    <label for="proxmox_token_id" class="col-sm-2 control-label">Proxmox Token ID</label>
    <div class="col-sm-6">
        <input type="text" id="proxmox_token_id" class="form-control" name="proxmox_token_id"
               value="{{ old('proxmox_token_id', $device->attribs['proxmox_token_id'] ?? '') }}"
               placeholder="tokenid">
    </div>
</div>

<div class="form-group">
    <label for="proxmox_token" class="col-sm-2 control-label">Proxmox Token Secret</label>
    <div class="col-sm-6">
        <input type="password" id="proxmox_token" class="form-control" name="proxmox_token"
               placeholder="Enter to set or replace" value="">
        @if(!empty($device->attribs['proxmox_token_enc']))
            <small class="text-muted">A token secret is stored. Enter a new value to replace.</small>
        @endif
    </div>
</div>

<div class="form-group">
    <label for="proxmox_username" class="col-sm-2 control-label">Proxmox Username@Realm</label>
    <div class="col-sm-6">
        <input type="text" id="proxmox_username" class="form-control" name="proxmox_username"
               value="{{ old('proxmox_username', $device->attribs['proxmox_username'] ?? '') }}"
               placeholder="root@pam">
    </div>
</div>

<div class="form-group">
    <label for="proxmox_password" class="col-sm-2 control-label">Proxmox Password</label>
    <div class="col-sm-6">
        <input type="password" id="proxmox_password" class="form-control" name="proxmox_password" value="">
        @if(!empty($device->attribs['proxmox_password_enc']))
            <small class="text-muted">A password is stored. Enter a new value to replace.</small>
        @endif
    </div>
</div>

<div class="form-group">
    <label for="rest_headers" class="col-sm-2 control-label">Extra Headers (JSON)</label>
    <div class="col-sm-6">
        <textarea id="rest_headers" class="form-control" name="rest_headers" rows="2"
                  placeholder='{"X-Org":"netops"}'>{{ old('rest_headers', $device->attribs['rest_headers'] ?? '') }}</textarea>
    </div>
</div>

<div class="form-group">
    <div class="col-sm-offset-2 col-sm-6">
        <div class="checkbox">
            <label>
                @php $verify = old('rest_verify_tls', $device->attribs['rest_verify_tls'] ?? $device->attribs['proxmox_verify_tls'] ?? 1); @endphp
                <input type="checkbox" id="rest_verify_tls" name="rest_verify_tls" value="1"
                       {{ $verify ? 'checked' : '' }}>
                Verify TLS certificates
            </label>
        </div>
    </div>
</div>

<div class="form-group">
    <label for="rest_timeout_ms" class="col-sm-2 control-label">Timeout (ms)</label>
    <div class="col-sm-6">
        <input type="number" id="rest_timeout_ms" class="form-control" name="rest_timeout_ms"
               value="{{ old('rest_timeout_ms', $device->attribs['rest_timeout_ms'] ?? $device->attribs['proxmox_timeout_ms'] ?? 5000) }}">
    </div>
</div>

<div class="form-group">
    <label for="rest_proxy" class="col-sm-2 control-label">Proxy (optional)</label>
    <div class="col-sm-6">
        <input type="text" id="rest_proxy" class="form-control" name="rest_proxy"
               value="{{ old('rest_proxy', $device->attribs['rest_proxy'] ?? $device->attribs['proxmox_proxy'] ?? '') }}"
               placeholder="http://user:pass@proxy:3128">
    </div>
</div>

<div class="form-group">
    <label for="rest_rate_limit_qps" class="col-sm-2 control-label">Rate Limit (queries/second)</label>
    <div class="col-sm-6">
        <input type="number" id="rest_rate_limit_qps" class="form-control" name="rest_rate_limit_qps" min="1" max="100"
               value="{{ old('rest_rate_limit_qps', $device->attribs['rest_rate_limit_qps'] ?? 10) }}">
        <small class="text-muted">Maximum API requests per second (default: 10).</small>
    </div>
</div>


<div class="form-group">
    <div class="col-sm-offset-2 col-sm-6">
        <button type="button" id="test-api-connection" class="btn btn-info">
            <i class="fa fa-plug"></i> Test Connection
        </button>

        @if(!empty($device->attribs['rest_error_count']) && $device->attribs['rest_error_count'] > 0)
            <button type="button" id="reset-circuit-breaker" class="btn btn-warning">
                <i class="fa fa-refresh"></i> Reset Error Counter
            </button>
        @endif
    </div>
</div>

@if(!empty($device->attribs['rest_last_success']))
<div class="form-group">
    <div class="col-sm-offset-2 col-sm-6">
        <small class="text-muted">
            <i class="fa fa-check-circle text-success"></i>
            Last success: {{ \Carbon\Carbon::createFromTimestamp($device->attribs['rest_last_success'])->diffForHumans() }}
            @if(!empty($device->attribs['rest_avg_latency_ms']))
                (avg {{ $device->attribs['rest_avg_latency_ms'] }}ms)
            @endif
        </small>
    </div>
</div>
@endif

@push('scripts')
<script>
// Vendor auto-fill defaults
const vendorDefaults = {
    'purestorage': {
        auth_type: 'apikey',
        base_url_hint: 'https://array-name/api/2.26',
        timeout_ms: 5000,
        rate_limit_qps: 10
    },
    'proxmox': {
        auth_type: 'token',
        base_url_hint: 'https://pve-host:8006',
        timeout_ms: 3000,
        rate_limit_qps: 5
    },
    'generic': {
        auth_type: 'bearer',
        base_url_hint: 'https://device/api/v1',
        timeout_ms: 5000,
        rate_limit_qps: 10
    }
};

$('#rest_vendor').on('change', function() {
    const vendor = $(this).val();
    if (vendor && vendorDefaults[vendor]) {
        const defaults = vendorDefaults[vendor];

        // Only update if fields are empty
        const baseUrlField = $('input[name="rest_base_url"]');
        if (!baseUrlField.val()) {
            baseUrlField.attr('placeholder', defaults.base_url_hint);
        }

        $('select[name="rest_auth_type"]').val(defaults.auth_type);
        $('input[name="rest_timeout_ms"]').val(defaults.timeout_ms);
        $('input[name="rest_rate_limit_qps"]').val(defaults.rate_limit_qps);
    }
});

// Test API connection
$('#test-api-connection').on('click', function() {
    const btn = $(this);
    const originalHtml = btn.html();
    btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Testing...');

    fetch('{{ route("device.test-api-connection", $device->device_id) }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            rest_enabled: $('#rest_enabled').is(':checked'),
            rest_vendor: $('#rest_vendor').val(),
            rest_base_url: $('input[name="rest_base_url"]').val(),
            rest_auth_type: $('select[name="rest_auth_type"]').val(),
            rest_token: $('input[name="rest_token"]').val(),
            rest_username: $('input[name="rest_username"]').val(),
            rest_password: $('input[name="rest_password"]').val(),
            rest_verify_tls: $('#rest_verify_tls').is(':checked'),
            rest_timeout_ms: $('input[name="rest_timeout_ms"]').val()
        })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            toastr.success('Connection successful! Detected: ' + (d.vendor || 'Unknown') + ' ' + (d.version || ''));
        } else {
            toastr.error('Connection failed: ' + (d.error || 'Unknown error'));
        }
    })
    .catch(e => {
        toastr.error('Connection test failed: ' + e.message);
    })
    .finally(() => {
        btn.prop('disabled', false).html(originalHtml);
    });
});

// Reset circuit breaker
$('#reset-circuit-breaker').on('click', function() {
    const btn = $(this);
    if (!confirm('Reset the error counter and circuit breaker for this device?')) {
        return;
    }

    btn.prop('disabled', true);

    fetch('{{ route("device.reset-circuit-breaker", $device->device_id) }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            toastr.success('Circuit breaker reset successfully');
            setTimeout(() => location.reload(), 1000);
        } else {
            toastr.error('Failed to reset circuit breaker');
        }
    })
    .catch(() => {
        toastr.error('Failed to reset circuit breaker');
    })
    .finally(() => {
        btn.prop('disabled', false);
    });
});
</script>
@endpush