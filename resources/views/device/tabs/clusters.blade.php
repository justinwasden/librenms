@extends('layouts.librenmsv1')

@section('content')
<x-device.page :device="$device">
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <h3>VMware Clusters</h3>

                @php
                    $clusters = \DB::table('hypervisor_clusters')
                        ->where('device_id', $device->device_id)
                        ->where('cluster_type', 'vmware')
                        ->get();
                @endphp

                @if($clusters->isEmpty())
                    <div class="alert alert-info">
                        <i class="fa fa-info-circle"></i> No cluster data available for this device.
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover table-condensed table-striped">
                            <thead>
                                <tr>
                                    <th>Cluster Name</th>
                                    <th>Hosts (Total/Effective)</th>
                                    <th>VMs (Total/Powered On)</th>
                                    <th>CPU</th>
                                    <th>CPU Usage</th>
                                    <th>Memory</th>
                                    <th>Memory Usage</th>
                                    <th>Last Updated</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($clusters as $cluster)
                                    <tr>
                                        <td><strong>{{ $cluster->cluster_name }}</strong></td>
                                        <td>{{ $cluster->num_hosts }} / {{ $cluster->num_effective_hosts }}</td>
                                        <td>{{ $cluster->num_vms_total }} / {{ $cluster->num_vms_powered_on }}</td>
                                        <td>
                                            Total: {{ number_format($cluster->total_cpu_mhz / 1000, 2) }} GHz<br>
                                            Available: {{ number_format($cluster->effective_cpu_mhz / 1000, 2) }} GHz
                                        </td>
                                        <td>
                                            <div class="progress">
                                                <div class="progress-bar @if($cluster->cpu_usage_pct > 80) progress-bar-danger @elseif($cluster->cpu_usage_pct > 60) progress-bar-warning @else progress-bar-success @endif"
                                                     role="progressbar"
                                                     style="width: {{ $cluster->cpu_usage_pct }}%">
                                                    {{ number_format($cluster->cpu_usage_pct, 1) }}%
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            Total: {{ number_format($cluster->total_memory_mb / 1024, 2) }} GB<br>
                                            Available: {{ number_format($cluster->effective_memory_mb / 1024, 2) }} GB
                                        </td>
                                        <td>
                                            <div class="progress">
                                                <div class="progress-bar @if($cluster->memory_usage_pct > 80) progress-bar-danger @elseif($cluster->memory_usage_pct > 60) progress-bar-warning @else progress-bar-success @endif"
                                                     role="progressbar"
                                                     style="width: {{ $cluster->memory_usage_pct }}%">
                                                    {{ number_format($cluster->memory_usage_pct, 1) }}%
                                                </div>
                                            </div>
                                        </td>
                                        <td>{{ \Carbon\Carbon::parse($cluster->updated_at)->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="row">
                        @foreach($clusters as $cluster)
                            <div class="col-md-6">
                                <div class="panel panel-default">
                                    <div class="panel-heading">
                                        <h3 class="panel-title">{{ $cluster->cluster_name }} - Resource Metrics</h3>
                                    </div>
                                    <div class="panel-body">
                                        <div class="row">
                                            <div class="col-sm-6">
                                                <h4>CPU Utilization</h4>
                                                @php
                                                    $usedCpu = $cluster->total_cpu_mhz - $cluster->effective_cpu_mhz;
                                                @endphp
                                                <p>
                                                    <strong>Used:</strong> {{ number_format($usedCpu / 1000, 2) }} GHz<br>
                                                    <strong>Available:</strong> {{ number_format($cluster->effective_cpu_mhz / 1000, 2) }} GHz<br>
                                                    <strong>Total:</strong> {{ number_format($cluster->total_cpu_mhz / 1000, 2) }} GHz
                                                </p>
                                            </div>
                                            <div class="col-sm-6">
                                                <h4>Memory Utilization</h4>
                                                @php
                                                    $usedMemory = $cluster->total_memory_mb - $cluster->effective_memory_mb;
                                                @endphp
                                                <p>
                                                    <strong>Used:</strong> {{ number_format($usedMemory / 1024, 2) }} GB<br>
                                                    <strong>Available:</strong> {{ number_format($cluster->effective_memory_mb / 1024, 2) }} GB<br>
                                                    <strong>Total:</strong> {{ number_format($cluster->total_memory_mb / 1024, 2) }} GB
                                                </p>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-sm-6">
                                                <h4>Hosts</h4>
                                                <p>
                                                    <strong>Total Hosts:</strong> {{ $cluster->num_hosts }}<br>
                                                    <strong>Effective Hosts:</strong> {{ $cluster->num_effective_hosts }}
                                                </p>
                                            </div>
                                            <div class="col-sm-6">
                                                <h4>Virtual Machines</h4>
                                                <p>
                                                    <strong>Total VMs:</strong> {{ $cluster->num_vms_total }}<br>
                                                    <strong>Powered On:</strong> {{ $cluster->num_vms_powered_on }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-device.page>
@endsection
