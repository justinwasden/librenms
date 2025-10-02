<div class="form-group">
    <label for="name">Name</label>
    <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $template->name) }}" required>
</div>

<div class="form-group">
    <label for="vendor">Vendor</label>
    <input type="text" name="vendor" id="vendor" class="form-control" value="{{ old('vendor', $template->vendor) }}">
</div>

<div class="form-group">
    <label for="template_data">Template Data (JSON)</label>
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