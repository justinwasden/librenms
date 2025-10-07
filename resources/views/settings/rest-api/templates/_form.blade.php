<div class="form-group">
    <label for="name">Name</label>
    <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $template->name) }}" required>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label for="vendor">Vendor</label>
            <input type="text" name="vendor" id="vendor" class="form-control" value="{{ old('vendor', $template->vendor) }}">
        </div>
    </div>
    {{-- NEW FIELD: Resource Type on Template --}}
    <div class="col-md-6">
        <div class="form-group">
            <label for="resource_type">Primary Resource Type</label>
            <select name="resource_type" id="resource_type" class="form-control">
                <option value="">-- None (Generic) --</option>
                <option value="device" {{ old('resource_type', $template->resource_type) === 'device' ? 'selected' : '' }}>Device</option>
                <option value="port" {{ old('resource_type', $template->resource_type) === 'port' ? 'selected' : '' }}>Port/Interface</option>
                <option value="storage" {{ old('resource_type', $template->resource_type) === 'storage' ? 'selected' : '' }}>Storage/Volume</option>
                <option value="sensor" {{ old('resource_type', $template->resource_type) === 'sensor' ? 'selected' : '' }}>Sensor/Health</option>
                <option value="processor" {{ old('resource_type', $template->resource_type) === 'processor' ? 'selected' : '' }}>Processor</option>
                <option value="mempool" {{ old('resource_type', $template->resource_type) === 'mempool' ? 'selected' : '' }}>Memory Pool</option>
                <option value="alert" {{ old('resource_type', $template->resource_type) === 'alert' ? 'selected' : '' }}>Alert/Event</option>
                <option value="custom" {{ old('resource_type', $template->resource_type) === 'custom' ? 'selected' : '' }}>Custom/Other</option>
            </select>
            <small class="form-text text-muted">Primary focus of this template (e.g., set to 'storage' for PureStorage)</small>
        </div>
    </div>
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