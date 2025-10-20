@props(['apiResponse', 'endpoint' => null, 'vendor' => null])

<div class="card">
    <div class="card-header bg-info">
        <h5 class="mb-0">
            <i class="fa fa-database"></i> API Response Preview
            @if($endpoint)
                <small class="text-muted">{{ $endpoint->name ?? $endpoint->path }}</small>
            @endif
        </h5>
    </div>
    <div class="card-body">
        @if(empty($apiResponse))
            <div class="alert alert-warning mb-0">
                <i class="fa fa-exclamation-triangle"></i> No data available. Check endpoint configuration.
            </div>
        @else
            <div class="alert alert-info mb-3">
                <strong>Response Structure:</strong>
                @if(isset($apiResponse['items']))
                    <span class="badge badge-success">Multi-item response</span>
                    <small>{{ count($apiResponse['items']) }} items found</small>
                @elseif(isset($apiResponse['data']))
                    <span class="badge badge-success">Data array</span>
                    <small>{{ count($apiResponse['data']) }} items found</small>
                @else
                    <span class="badge badge-warning">Single item</span>
                @endif
            </div>

            <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="response-structure-tab" data-toggle="tab" href="#response-structure" role="tab">
                        Structure
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="response-sample-tab" data-toggle="tab" href="#response-sample" role="tab">
                        Sample Data
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="response-raw-tab" data-toggle="tab" href="#response-raw" role="tab">
                        Raw JSON
                    </a>
                </li>
            </ul>

            <div class="tab-content">
                <!-- Structure Tab -->
                <div id="response-structure" class="tab-pane fade show active" role="tabpanel">
                    <div class="response-structure">
                        @php
                            $items = $apiResponse['items'] ?? $apiResponse['data'] ?? [$apiResponse];
                            $sample = reset($items);
                        @endphp

                        @if(is_array($sample))
                            <h6>Top-level fields in item:</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Field Name</th>
                                            <th>Data Type</th>
                                            <th>Sample Value</th>
                                            <th>Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($sample as $key => $value)
                                            <tr>
                                                <td>
                                                    <code>{{ $key }}</code>
                                                </td>
                                                <td>
                                                    @php
                                                        $type = gettype($value);
                                                        if ($type === 'array') {
                                                            $badge = 'badge-warning';
                                                        } elseif ($type === 'integer' || $type === 'double') {
                                                            $badge = 'badge-info';
                                                        } elseif ($type === 'string') {
                                                            $badge = 'badge-success';
                                                        } else {
                                                            $badge = 'badge-secondary';
                                                        }
                                                    @endphp
                                                    <span class="badge {{ $badge }}">{{ $type }}</span>
                                                </td>
                                                <td>
                                                    @if(is_array($value))
                                                        <small class="text-muted">[nested object]</small>
                                                    @elseif(is_string($value))
                                                        <code>{{ \Illuminate\Support\Str::limit($value, 40) }}</code>
                                                    @else
                                                        <code>{{ json_encode($value) }}</code>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if($key === 'name' || $key === 'id')
                                                        <span class="badge badge-light">Identifier</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="alert alert-warning">
                                Sample item is not an array/object
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Sample Data Tab -->
                <div id="response-sample" class="tab-pane fade" role="tabpanel">
                    <h6>First item in response:</h6>
                    <pre class="bg-light p-3 rounded"><code>{{ json_encode($sample ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
                </div>

                <!-- Raw JSON Tab -->
                <div id="response-raw" class="tab-pane fade" role="tabpanel">
                    <h6>Complete API response (first 5 items):</h6>
                    @php
                        $preview = $apiResponse;
                        if (isset($preview['items'])) {
                            $preview['items'] = array_slice($preview['items'], 0, 5);
                        } elseif (isset($preview['data'])) {
                            $preview['data'] = array_slice($preview['data'], 0, 5);
                        }
                    @endphp
                    <pre class="bg-light p-3 rounded"><code>{{ json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
                </div>
            </div>
        @endif
    </div>
</div>
