<div class=\\"row\\">
    <div class=\\"col-md-12\\">
        @if ($clusters->isEmpty())
            <div class=\\"alert alert-info\\">No cluster data available for this device.</div>
        @else
            <div class=\\"panel panel-default panel-condensed\\">
                <div class=\\"panel-heading\\">
                    <i class=\\"fa fa-object-group fa-lg icon-theme\\" aria-hidden=\\"true\\"></i>
                    <strong>Cluster Metrics</strong>
                </div>

                <table class=\\"table table-hover table-condensed table-striped\\">
                    <thead>
                        <tr>
                            <th>Cluster Name</th>
                            <th>Provider</th>
                            <th>Nodes (Effective / Total)</th>
                            <th>CPU</th>
                            <th>Memory</th>
                            <th>Storage</th>
                            <th>Network</th>
                            <th>State</th>
                            <th>Last Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($clusters as $cluster)
                            @php
                                // Derive node counts from relation if eager loaded; otherwise could query count
                                $totalNodes = method_exists($cluster, 'nodes') ? $cluster->nodes()->count() : null;
                                $effectiveNodes = method_exists($cluster, 'nodes') ? $cluster->nodes()->where('effective', true)->count() : null;

                                // Fetch latest metric for summary
                                $latestMetric = method_exists($cluster, 'metrics') ? $cluster->metrics()->latest('timestamp')->first() : null;

                                $cpuUsage = $latestMetric->cpu_usage_pct ?? null;
                                $cpuClass = is_null($cpuUsage) ? 'default' : ($cpuUsage > 90 ? 'danger' : ($cpuUsage > 75 ? 'warning' : 'success'));

                                $memUsage = $latestMetric->memory_usage_pct ?? null;
                                $memClass = is_null($memUsage) ? 'default' : ($memUsage > 90 ? 'danger' : ($memUsage > 75 ? 'warning' : 'success'));

                                $storageUsage = $latestMetric->storage_usage_pct ?? null;
                                $storageClass = is_null($storageUsage) ? 'default' : ($storageUsage > 90 ? 'danger' : ($storageUsage > 75 ? 'warning' : 'success'));

                                $netUsage = $latestMetric->network_usage_pct ?? null;
                                $netClass = is_null($netUsage) ? 'default' : ($netUsage > 90 ? 'danger' : ($netUsage > 75 ? 'warning' : 'success'));

                                $lastUpdated = $latestMetric->timestamp ?? $cluster->updated_at ?? null;
                            @endphp
                            <tr>
                                <td>{{ $cluster->cluster_name }}</td>
                                <td>{{ $cluster->provider_type }}</td>
                                <td>
                                    @if (!is_null($effectiveNodes) && !is_null($totalNodes))
                                        {{ $effectiveNodes }} / {{ $totalNodes }}
                                    @else
                                        N/A
                                    @endif
                                </td>
                                <td>
                                    @if (!is_null($cpuUsage))
                                        <div class=\\"progress\\" style=\\"margin-bottom: 5px;\\">
                                            <div class=\\"progress-bar progress-bar-{{ $cpuClass }}\\" role=\\"progressbar\\" style=\\"width: {{ $cpuUsage }}%;\\">
                                                {{ number_format($cpuUsage, 1) }}%
                                            </div>
                                        </div>
                                    @else
                                        <span class=\\"text-muted\\">N/A</span>
                                    @endif
                                </td>
                                <td>
                                    @if (!is_null($memUsage))
                                        <div class=\\"progress\\" style=\\"margin-bottom: 5px;\\">
                                            <div class=\\"progress-bar progress-bar-{{ $memClass }}\\" role=\\"progressbar\\" style=\\"width: {{ $memUsage }}%;\\">
                                                {{ number_format($memUsage, 1) }}%
                                            </div>
                                        </div>
                                    @else
                                        <span class=\\"text-muted\\">N/A</span>
                                    @endif
                                </td>
                                <td>
                                    @if (!is_null($storageUsage))
                                        <div class=\\"progress\\" style=\\"margin-bottom: 5px;\\">
                                            <div class=\\"progress-bar progress-bar-{{ $storageClass }}\\" role=\\"progressbar\\" style=\\"width: {{ $storageUsage }}%;\\">
                                                {{ number_format($storageUsage, 1) }}%
                                            </div>
                                        </div>
                                    @else
                                        <span class=\\"text-muted\\">N/A</span>
                                    @endif
                                </td>
                                <td>
                                    @if (!is_null($netUsage))
                                        <div class=\\"progress\\" style=\\"margin-bottom: 5px;\\">
                                            <div class=\\"progress-bar progress-bar-{{ $netClass }}\\" role=\\"progressbar\\" style=\\"width: {{ $netUsage }}%;\\">
                                                {{ number_format($netUsage, 1) }}%
                                            </div>
                                        </div>
                                    @else
                                        <span class=\\"text-muted\\">N/A</span>
                                    @endif
                                </td>
                                <td>
                                    <span class=\\"label {{ ($cluster->state === 'healthy') ? 'label-success' : (($cluster->state === 'degraded' || $cluster->state === 'warning') ? 'label-warning' : 'label-danger') }}\\">
                                        {{ $cluster->state ?? 'Unknown' }}
                                    </span>
                                </td>
                                <td>
                                    @if ($lastUpdated)
                                        <small class=\\"text-muted\\">{{ \\Illuminate\\Support\\Carbon::parse($lastUpdated)->format('Y-m-d H:i:s') }}</small>
                                    @else
                                        <small class=\\"text-muted\\">N/A</small>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class=\\"panel panel-default panel-condensed\\">
            <div class=\\"panel-heading\\">
                <i class=\\"fa fa-info-circle fa-lg icon-theme\\" aria-hidden=\\"true\\"></i>
                <strong>About Cluster Metrics</strong>
            </div>

            <table class=\\"table table-hover table-condensed table-striped\\">
                <tbody>
                    <tr>
                        <td>
                            <ul style=\\"margin-bottom: 0;\\">
                                <li><strong>Nodes:</strong> Total vs effective nodes contributing capacity</li>
                                <li><strong>CPU/Memory:</strong> Utilization summary from latest metrics</li>
                                <li><strong>Storage:</strong> Usage percentage across the cluster</li>
                                <li><strong>Network:</strong> Utilization percentage (if available)</li>
                                <li><strong>State:</strong> Overall health state of the cluster</li>
                            </ul>
                            <p class=\\"text-muted\\" style=\\"margin: 5px 0 0 0;\\">
                                <small>Data is collected from provider APIs and updated during device polling.</small>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
