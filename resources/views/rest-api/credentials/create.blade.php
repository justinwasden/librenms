@extends('layouts.librenms')

@section('title', 'Configure REST API - ' . $device->hostname)

@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-8">
            <h2>Configure REST API for {{ $device->hostname }}</h2>

            <form method="POST" action="{{ route('rest-api.credentials.store', $device) }}">
                @csrf

                <div class="form-group">
                    <label for="template_id">Template</label>
                    <select name="template_id" id="template_id" class="form-control" required>
                        <option value="">-- Select Template --</option>
                        @foreach($templates as $template)
                            <option value="{{ $template->id }}">
                                {{ $template->name }} ({{ $template->vendor }})
                            </option>
                        @endforeach
                    </select>
                    @error('template_id')<span class="text-danger">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="auth_type">Authentication Type</label>
                    <select name="auth_type" id="auth_type" class="form-control" required>
                        <option value="">-- Select Auth Type --</option>
                        @foreach($authTypes as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('auth_type')<span class="text-danger">{{ $message }}</span>@enderror
                </div>

                <div class="form-group" id="username-group" style="display:none;">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username" class="form-control">
                    @error('username')<span class="text-danger">{{ $message }}</span>@enderror
                </div>

                <div class="form-group" id="password-group" style="display:none;">
                    <label for="password">Password</label>
                    <input type="password" name="password" id="password" class="form-control">
                    @error('password')<span class="text-danger">{{ $message }}</span>@enderror
                </div>

                <div class="form-group" id="token-group" style="display:none;">
                    <label for="auth_token">API Token</label>
                    <input type="password" name="auth_token" id="auth_token" class="form-control">
                    @error('auth_token')<span class="text-danger">{{ $message }}</span>@enderror
                </div>

                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">Save Configuration</button>
                    <a href="{{ route('devices.show', $device) }}" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('auth_type').addEventListener('change', function() {
    const type = this.value;
    document.getElementById('username-group').style.display = type === 'basic_auth' ? 'block' : 'none';
    document.getElementById('password-group').style.display = type === 'basic_auth' ? 'block' : 'none';
    document.getElementById('token-group').style.display = ['bearer_token', 'api_token', 'oauth2'].includes(type) ? 'block' : 'none';
});
</script>
@endsection
