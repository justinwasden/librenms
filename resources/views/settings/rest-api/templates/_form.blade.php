<div class="form-group">
    <label for="name">Name</label>
    <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $template->name) }}" required>
</div>

{{-- Kept for simplicity on the Create screen --}}
<div class="form-group">
    <label for="template_data">Template Data (JSON) <span class="text-danger">*</span></label>
    {{-- This is the only place the whole JSON blob is edited, mainly for initial creation or advanced edit --}}
    <textarea name="template_data" id="template_data" class="form-control" rows="20" required>{{ old('template_data', json_encode($template->template_data, JSON_PRETTY_PRINT)) }}</textarea>

    <small class="form-text text-muted">
        Define the connections and endpoints in a JSON format.

        @if(isset($device))
            Use <code>{{ $device->hostname }}</code> or <code>{{ $device->getAttrib('some_attrib') }}</code> for dynamic values.
        @else
            Dynamic placeholders like <code>{{ '{' }}{{ '{' }}$device->hostname {{ '}' }}{{ '}' }}</code> can be used.
        @endif
    </small>
</div>