@props(['recommendations', 'endpoint' => null, 'vendor' => null])

<div class="card">
    <div class="card-header bg-success">
        <h5 class="mb-0">
            <i class="fa fa-lightbulb"></i> Recommended Mappings
            @if($vendor)
                <small class="text-muted">{{ $vendor }}</small>
            @endif
        </h5>
    </div>
    <div class="card-body">
        @if(empty($recommendations))
            <div class="alert alert-info mb-0">
                <i class="fa fa-info-circle"></i> No recommendations available. Configure mappings manually below.
            </div>
        @else
            <div class="alert alert-info mb-3">
                <i class="fa fa-star"></i> <strong>Smart Recommendations</strong> based on endpoint structure and data types.
                Click to apply, or customize below.
            </div>

            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>API Field</th>
                            <th>Data Type</th>
                            <th>Recommended Table</th>
                            <th>Recommended Field</th>
                            <th style="width: 100px;">Confidence</th>
                            <th>Reason</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recommendations as $apiField => $rec)
                            <tr>
                                <td>
                                    <code class="bg-light p-2 rounded">{{ $apiField }}</code>
                                </td>
                                <td>
                                    @php
                                        $dataType = $rec['dataType'] ?? 'unknown';
                                        $typeBadges = [
                                            'string' => 'badge-success',
                                            'integer' => 'badge-info',
                                            'float' => 'badge-info',
                                            'boolean' => 'badge-warning',
                                            'array' => 'badge-danger',
                                            'null' => 'badge-secondary',
                                        ];
                                        $badge = $typeBadges[$dataType] ?? 'badge-secondary';
                                    @endphp
                                    <span class="badge {{ $badge }}">{{ $dataType }}</span>
                                </td>
                                <td>
                                    <strong>{{ $rec['table'] ?? 'unknown' }}</strong>
                                </td>
                                <td>
                                    <code class="bg-light p-2 rounded">{{ $rec['field'] ?? 'N/A' }}</code>
                                </td>
                                <td>
                                    @php
                                        $confidence = $rec['confidence'] ?? 0;
                                        if ($confidence >= 0.95) {
                                            $confidenceClass = 'success';
                                            $confidenceText = 'High';
                                        } elseif ($confidence >= 0.85) {
                                            $confidenceClass = 'info';
                                            $confidenceText = 'Good';
                                        } elseif ($confidence >= 0.70) {
                                            $confidenceClass = 'warning';
                                            $confidenceText = 'Fair';
                                        } else {
                                            $confidenceClass = 'danger';
                                            $confidenceText = 'Low';
                                        }
                                    @endphp
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar bg-{{ $confidenceClass }}" role="progressbar" style="width: {{ $confidence * 100 }}%;">
                                            {{ round($confidence * 100) }}%
                                        </div>
                                    </div>
                                    <small class="text-{{ $confidenceClass }}">{{ $confidenceText }}</small>
                                </td>
                                <td>
                                    <small class="text-muted">{{ $rec['reason'] ?? '' }}</small>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary apply-recommendation" 
                                            data-api-field="{{ $apiField }}"
                                            data-table="{{ $rec['table'] ?? '' }}"
                                            data-field="{{ $rec['field'] ?? '' }}">
                                        <i class="fa fa-check"></i> Apply
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-3">
                <button type="button" class="btn btn-success btn-sm apply-all-recommendations">
                    <i class="fa fa-check-double"></i> Apply All Recommendations
                </button>
            </div>
        @endif
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Apply single recommendation
    document.querySelectorAll('.apply-recommendation').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const apiField = this.dataset.apiField;
            const table = this.dataset.table;
            const field = this.dataset.field;
            
            // Fill in the mapping form
            const mapRow = document.querySelector(`[data-api-field="${apiField}"]`);
            if (mapRow) {
                mapRow.querySelector('[name$="[table]"]').value = table;
                mapRow.querySelector('[name$="[field]"]').value = field;
                mapRow.classList.add('table-success');
            }
            
            console.log(`Applied mapping: ${apiField} → ${table}.${field}`);
        });
    });
    
    // Apply all recommendations
    document.querySelector('.apply-all-recommendations')?.addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelectorAll('.apply-recommendation').forEach(btn => btn.click());
    });
});
</script>
