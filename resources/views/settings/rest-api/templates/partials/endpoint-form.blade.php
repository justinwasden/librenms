<div class="border rounded p-3 bg-light">
    <div class="form-group">
        <label>Endpoint Name</label>
        <input type="text" class="form-control" name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][name]" value="{{ $endpoint['name'] ?? '' }}">
    </div>

    <div class="form-group">
        <label>Path</label>
        <input type="text" class="form-control" name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][path]" value="{{ $endpoint['path'] ?? '' }}">
    </div>

    <div class="form-group">
        <label>HTTP Method</label>
        <input type="text" class="form-control" name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][http_method]" value="{{ $endpoint['http_method'] ?? 'GET' }}">
    </div>

    <div class="form-group">
        <label>Poll Interval (seconds)</label>
        <input type="number" class="form-control" name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][poll_interval]" value="{{ $endpoint['poll_interval'] ?? 300 }}">
    </div>

    <div class="form-group">
        <label>Enable Endpoint</label>
        <input type="hidden" name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][enabled]" value="0">
        <input type="checkbox" name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][enabled]" value="1" {{ !empty($endpoint['enabled']) ? 'checked' : '' }}>
    </div>

    <div class="form-group">
        <label>Response Mapping (JSON)</label>
        <textarea class="form-control" rows="4" name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][response_mapping]">{{ json_encode($endpoint['response_mapping'] ?? [], JSON_PRETTY_PRINT) }}</textarea>
    </div>

    <div class="text-right">
        <button type="button" class="btn btn-sm btn-outline-secondary" @click="openEndpoint = null">Cancel</button>
    </div>
</div>
