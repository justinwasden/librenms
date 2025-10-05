@php
$connections = $template->template_data['connections'] ?? [];
$connection = $connections[0] ?? [];
@endphp

<div class="form-group">
    <label>Connection Name</label>
    <input type="text" class="form-control" name="template_data[connections][0][name]" value="{{ $connection['name'] ?? '' }}">
</div>

<div class="form-group">
    <label>Base URL</label>
    <input type="text" class="form-control" name="template_data[connections][0][base_url]" value="{{ $connection['base_url'] ?? '' }}">
</div>

<div class="form-group">
    <label>Rate Limit</label>
    <input type="number" class="form-control" name="template_data[connections][0][rate_limit]" value="{{ $connection['rate_limit'] ?? 60 }}">
</div>
