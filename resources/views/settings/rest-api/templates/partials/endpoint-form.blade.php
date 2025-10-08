<div class="border rounded p-3 bg-light">
    {{-- ... (omitted unchanged fields) ... --}}

    {{-- Metric Mapping Section --}}
    <div class="card mb-3">
        <div class="card-header bg-primary text-white">
            <h6 class="mb-0">
                <i class="fas fa-chart-line"></i> Metric Mapping
            </h6>
        </div>
        <div class="card-body">
            <div class="alert alert-info mb-3">
                <i class="fas fa-info-circle"></i>
                <strong>Map API response fields to LibreNMS metrics.</strong><br>
                Use JSONPath notation to extract values from the response. Example: <code>$.data.cpu_usage</code>
            </div>

            <div class="form-group">
						    <label for="metric_map_json">Metric Mapping (JSON)</label>
						    <textarea id="metric_map_json"
						              {{-- CHANGE: The name now points directly to the correct array path --}}
						              name="template_data[connections][{{ $connectionIndex }}][endpoints][{{ $endpointIndex }}][metric_map]"
						              class="form-control font-monospace"
						              rows="10"
						              placeholder="Enter or paste JSON mapping here..."
						              required
						              style="white-space: pre; resize: vertical;">{{ old('metric_map_json', json_encode($endpoint['metric_map'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) }}</textarea>

						    <small class="form-text text-muted">
						        Paste valid JSON mapping. Example: <code>{"storage_size": "items.0.space.total_physical"}</code>
						    </small>
						    <button type="button" class="btn btn-sm btn-secondary mt-2" id="beautifyJson">Beautify JSON</button>

						    <div id="jsonError" class="text-danger mt-2" style="display: none;"></div>
						</div>


            {{-- ... (omitted unchanged fields) ... --}}
        </div>
    </div>

    {{-- ... (omitted unchanged fields and script block) ... --}}
</div>