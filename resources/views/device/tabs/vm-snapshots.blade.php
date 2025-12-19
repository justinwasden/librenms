@extends('layouts.librenmsv1')

@section('content')
<x-device.page :device="$device">
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title">{{ __('Virtual Machine Snapshots') }}</h3>
        </div>
        <div class="panel-body">
            @php
                $snapshots = \DB::table('vmware_vm_snapshots')
                    ->where('device_id', $device->device_id)
                    ->orderByDesc('snapshot_count')
                    ->orderBy('vm_name')
                    ->get();

                $now = now();
            @endphp

            @if($snapshots->isEmpty())
                <div class="alert alert-info">
                    <i class="fa fa-info-circle"></i> {{ __('No VM snapshots found for this device.') }}
                </div>
            @else
                <div class="alert alert-warning" role="alert">
                    <i class="fa fa-exclamation-triangle"></i>
                    <strong>{{ __('Warning:') }}</strong> {{ __('VM snapshots should be monitored and consolidated regularly. Old or excessive snapshots can impact performance and consume significant disk space.') }}
                </div>

                <table class="table table-hover table-condensed table-striped">
                    <thead>
                        <tr>
                            <th>{{ __('VM Name') }}</th>
                            <th>{{ __('Power State') }}</th>
                            <th class="text-center">{{ __('Snapshot Count') }}</th>
                            <th>{{ __('Oldest Snapshot') }}</th>
                            <th>{{ __('Age (Days)') }}</th>
                            <th class="text-right">{{ __('Total Size (GB)') }}</th>
                            <th>{{ __('Last Updated') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($snapshots as $snapshot)
                            @php
                                $age_days = $snapshot->oldest_snapshot_date
                                    ? $now->diffInDays($snapshot->oldest_snapshot_date)
                                    : null;

                                // Determine severity based on age and count
                                $row_class = '';
                                $age_class = '';
                                if ($age_days !== null) {
                                    if ($age_days >= 90) {
                                        $row_class = 'danger';
                                        $age_class = 'text-danger';
                                    } elseif ($age_days >= 30) {
                                        $row_class = 'warning';
                                        $age_class = 'text-warning';
                                    }
                                }

                                if ($snapshot->snapshot_count > 5 && empty($row_class)) {
                                    $row_class = 'warning';
                                }
                            @endphp
                            <tr class="{{ $row_class }}">
                                <td>
                                    <strong>{{ $snapshot->vm_name }}</strong>
                                    @if($snapshot->snapshot_count > 5)
                                        <span class="label label-warning" title="Excessive snapshots">
                                            <i class="fa fa-exclamation-triangle"></i> {{ __('High Count') }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $power_icons = [
                                            'poweredOn' => ['icon' => 'play-circle', 'class' => 'text-success'],
                                            'poweredOff' => ['icon' => 'stop-circle', 'class' => 'text-muted'],
                                            'suspended' => ['icon' => 'pause-circle', 'class' => 'text-warning'],
                                            'unknown' => ['icon' => 'question-circle', 'class' => 'text-muted'],
                                        ];
                                        $power = $power_icons[$snapshot->power_state] ?? $power_icons['unknown'];
                                    @endphp
                                    <i class="fa fa-{{ $power['icon'] }} {{ $power['class'] }}"></i>
                                    {{ ucfirst(str_replace('powered', '', $snapshot->power_state)) }}
                                </td>
                                <td class="text-center">
                                    <span class="badge {{ $snapshot->snapshot_count > 5 ? 'badge-warning' : 'badge-info' }}">
                                        {{ $snapshot->snapshot_count }}
                                    </span>
                                </td>
                                <td>
                                    @if($snapshot->oldest_snapshot_date)
                                        {{ \Carbon\Carbon::parse($snapshot->oldest_snapshot_date)->format('Y-m-d H:i:s') }}
                                    @else
                                        <span class="text-muted">{{ __('N/A') }}</span>
                                    @endif
                                </td>
                                <td class="{{ $age_class }}">
                                    @if($age_days !== null)
                                        <strong>{{ $age_days }}</strong>
                                        @if($age_days >= 90)
                                            <i class="fa fa-exclamation-triangle" title="Critical: Very old snapshot"></i>
                                        @elseif($age_days >= 30)
                                            <i class="fa fa-exclamation-circle" title="Warning: Old snapshot"></i>
                                        @endif
                                    @else
                                        <span class="text-muted">{{ __('N/A') }}</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if($snapshot->total_snapshot_size_gb)
                                        {{ number_format($snapshot->total_snapshot_size_gb, 2) }}
                                    @else
                                        <span class="text-muted">{{ __('N/A') }}</span>
                                    @endif
                                </td>
                                <td>
                                    <small class="text-muted">{{ \Carbon\Carbon::parse($snapshot->updated_at)->diffForHumans() }}</small>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="panel-footer">
                    <div class="row">
                        <div class="col-sm-6">
                            <strong>{{ __('Total VMs with Snapshots:') }}</strong> {{ $snapshots->count() }}
                        </div>
                        <div class="col-sm-6 text-right">
                            <span class="text-muted"><i class="fa fa-info-circle"></i> {{ __('Last polled:') }} {{ $device->last_polled_timetaken ? \Carbon\Carbon::parse($device->last_polled)->diffForHumans() : __('Never') }}</span>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-device.page>
@endsection
