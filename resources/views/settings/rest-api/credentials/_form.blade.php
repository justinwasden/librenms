<div class="form-group">
    <label for="name">Name</label>
    <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $credential->name) }}" required>
</div>

<div class="form-group">
    <label for="authentication_type_id">Authentication Type</label>
    <select name="authentication_type_id" id="authentication_type_id" class="form-control" required>
        <option value="">Select an Authentication Type</option>
        @foreach($authTypes as $type)
            <option value="{{ $type->id }}" @if(old('authentication_type_id', $credential->authentication_type_id) == $type->id) selected @endif>{{ $type->name }}</option>
        @endforeach
    </select>
</div>

<div id="auth-params-container">
    {{-- Parameters will be loaded here via AJAX --}}
</div>