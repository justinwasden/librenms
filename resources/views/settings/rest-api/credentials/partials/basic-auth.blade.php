<div class="form-group">
    <label for="params_username">Username</label>
    <input type="text" name="params[username]" id="params_username" class="form-control" value="{{ old('params.username', $credential->params->firstWhere('key', 'username')->value ?? '') }}" required>
</div>

<div class="form-group">
    <label for="params_password">Password</label>
    <input type="password" name="params[password]" id="params_password" class="form-control" value="{{ old('params.password', $credential->params->firstWhere('key', 'password')->value ?? '') }}" required>
</div>