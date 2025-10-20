@props(['apiField', 'apiValue', 'apiType', 'table', 'field', 'validation' => null])

<div class="compatibility-check">
    @if($validation)
        <div class="alert {{ $validation['valid'] ? 'alert-success' : 'alert-danger' }} mb-3">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    @if($validation['valid'])
                        <i class="fa fa-check-circle"></i>
                        <strong>Compatible</strong> - This mapping is valid
                    @else
                        <i class="fa fa-times-circle"></i>
                        <strong>Incompatible</strong> - This mapping may fail
                    @endif
                </div>
                <span class="badge badge-{{ $validation['valid'] ? 'success' : 'danger' }}">
                    {{ $validation['api_type'] ?? 'unknown' }}
                </span>
            </div>

            <p class="mb-0 mt-2">
                <strong>Reason:</strong> {{ $validation['reason'] ?? 'No validation available' }}
            </p>

            @if(!empty($validation['warnings']))
                <div class="mt-2">
                    <strong>Warnings:</strong>
                    <ul class="mb-0 mt-1">
                        @foreach($validation['warnings'] as $warning)
                            <li><small class="text-warning">{{ $warning }}</small></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        @if(isset($validation['expected_types']) && !empty($validation['expected_types']))
            <div class="card card-sm">
                <div class="card-body">
                    <h6 class="card-title">
                        <i class="fa fa-list"></i> Expected Data Types
                    </h6>
                    <p class="mb-0">
                        <code>{{ $table }}.{{ $field }}</code> expects:
                        @php
                            $expectedTypes = is_array($validation['expected_types']) 
                                ? $validation['expected_types'] 
                                : [$validation['expected_types']];
                        @endphp
                        @foreach($expectedTypes as $type)
                            @if($type !== '*')
                                <span class="badge badge-info">{{ $type }}</span>
                            @endif
                        @endforeach
                    </p>
                </div>
            </div>
        @endif

        @if(isset($validation['sample']))
            <div class="card card-sm mt-2">
                <div class="card-body">
                    <h6 class="card-title">
                        <i class="fa fa-eye"></i> Sample Transformation
                    </h6>
                    <div class="row">
                        <div class="col-md-6">
                            <small class="text-muted">API Value:</small><br>
                            <code class="bg-light p-2 rounded d-block">{{ json_encode($apiValue) }}</code>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">Stored Value:</small><br>
                            <code class="bg-light p-2 rounded d-block">{{ json_encode($validation['sample']) }}</code>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @else
        <div class="alert alert-info">
            <i class="fa fa-info-circle"></i> No validation available. 
            Test this mapping carefully.
        </div>
    @endif
</div>
