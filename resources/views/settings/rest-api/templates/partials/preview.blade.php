{{-- /resources/views/device/rest-api/templates/partials/preview.blade.php --}}
<div class="p-3">
    <div class="form-group">
        <label>Select a Device to Test</label>
        <select class="form-control" name="test_device_id">
            @foreach(\App\Models\Device::all() as $device)
                <option value="{{ $device->id }}">{{ $device->hostname }}</option>
            @endforeach
        </select>
    </div>

    <div class="form-group text-right">
        <button type="button" class="btn btn-info" onclick="alert('Test triggered! (Future: AJAX call to /test endpoint)')">Run Test</button>
    </div>

    <div class="border rounded bg-light p-3">
        <h6>Response Preview</h6>
        <pre class="bg-white p-2"><code>// Example output will appear here after testing</code></pre>
    </div>
</div>
