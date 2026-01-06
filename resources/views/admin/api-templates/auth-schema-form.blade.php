@extends('layouts.librenmsv1')

@section('title', $schema ? 'Edit Auth Schema: ' . $schema->name : 'Create Auth Schema')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <i class="fa fa-{{ $schema ? 'edit' : 'plus' }}"></i>
                        {{ $schema ? 'Edit Auth Schema' : 'Create Auth Schema' }}
                        @if($schema && $schema->is_system)
                            <span class="label label-info">System</span>
                        @endif
                    </h3>
                </div>
                <div class="panel-body">
                    @if($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form action="{{ $schema ? route('admin.api-auth-schemas.update', $schema) : route('admin.api-auth-schemas.store') }}"
                          method="POST" id="schemaForm">
                        @csrf
                        @if($schema)
                            @method('PUT')
                        @endif

                        <div class="form-group">
                            <label for="key">Schema Key <span class="text-danger">*</span></label>
                            <input type="text" name="key" id="key" class="form-control"
                                   value="{{ old('key', $schema->key ?? '') }}" required
                                   pattern="[a-z0-9_]+" title="Lowercase letters, numbers, and underscores only"
                                   placeholder="e.g., bearer_token"
                                   {{ $schema && $schema->is_system ? 'readonly' : '' }}>
                            <span class="help-block">Unique identifier (lowercase, no spaces)</span>
                        </div>

                        <div class="form-group">
                            <label for="name">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="name" class="form-control"
                                   value="{{ old('name', $schema->name ?? '') }}" required
                                   placeholder="e.g., Bearer Token Authentication">
                        </div>

                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea name="description" id="description" class="form-control" rows="2"
                                      placeholder="Brief description of this authentication method">{{ old('description', $schema->description ?? '') }}</textarea>
                        </div>

                        <hr>

                        <h4>
                            <i class="fa fa-list"></i> Credential Fields
                            <button type="button" class="btn btn-success btn-sm pull-right" id="addFieldBtn">
                                <i class="fa fa-plus"></i> Add Field
                            </button>
                        </h4>
                        <p class="text-muted">Define the fields that will be collected when configuring API credentials for devices.</p>

                        <div id="fieldsContainer">
                            @php
                                $fields = old('fields', $schema->fields ?? []);
                            @endphp
                            @foreach($fields as $index => $field)
                                <div class="well well-sm field-row" data-index="{{ $index }}">
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>Field Name <span class="text-danger">*</span></label>
                                                <input type="text" name="fields[{{ $index }}][name]" class="form-control input-sm"
                                                       value="{{ $field['name'] ?? '' }}" required
                                                       pattern="[a-z0-9_]+" placeholder="api_token">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>Label <span class="text-danger">*</span></label>
                                                <input type="text" name="fields[{{ $index }}][label]" class="form-control input-sm"
                                                       value="{{ $field['label'] ?? '' }}" required
                                                       placeholder="API Token">
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <label>Type</label>
                                                <select name="fields[{{ $index }}][type]" class="form-control input-sm">
                                                    <option value="text" {{ ($field['type'] ?? 'text') == 'text' ? 'selected' : '' }}>Text</option>
                                                    <option value="password" {{ ($field['type'] ?? '') == 'password' ? 'selected' : '' }}>Password</option>
                                                    <option value="number" {{ ($field['type'] ?? '') == 'number' ? 'selected' : '' }}>Number</option>
                                                    <option value="checkbox" {{ ($field['type'] ?? '') == 'checkbox' ? 'selected' : '' }}>Checkbox</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <label>Placeholder</label>
                                                <input type="text" name="fields[{{ $index }}][placeholder]" class="form-control input-sm"
                                                       value="{{ $field['placeholder'] ?? '' }}">
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <label>&nbsp;</label>
                                                <div>
                                                    <label class="checkbox-inline">
                                                        <input type="hidden" name="fields[{{ $index }}][required]" value="0">
                                                        <input type="checkbox" name="fields[{{ $index }}][required]" value="1"
                                                               {{ ($field['required'] ?? false) ? 'checked' : '' }}> Required
                                                    </label>
                                                    <label class="checkbox-inline">
                                                        <input type="hidden" name="fields[{{ $index }}][encrypted]" value="0">
                                                        <input type="checkbox" name="fields[{{ $index }}][encrypted]" value="1"
                                                               {{ ($field['encrypted'] ?? false) ? 'checked' : '' }}> Encrypted
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-danger btn-xs remove-field-btn" style="position: absolute; top: 5px; right: 5px;">
                                        <i class="fa fa-times"></i>
                                    </button>
                                </div>
                            @endforeach
                        </div>

                        <div id="noFieldsMessage" class="alert alert-info" style="{{ count($fields) > 0 ? 'display:none' : '' }}">
                            No fields defined. Click "Add Field" to add credential fields.
                        </div>

                        <hr>

                        <div class="form-group">
                            <a href="{{ route('admin.api-auth-schemas.index') }}" class="btn btn-default">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-save"></i> {{ $schema ? 'Update Schema' : 'Create Schema' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Field Template (hidden) -->
<template id="fieldTemplate">
    <div class="well well-sm field-row" data-index="__INDEX__">
        <div class="row">
            <div class="col-md-3">
                <div class="form-group">
                    <label>Field Name <span class="text-danger">*</span></label>
                    <input type="text" name="fields[__INDEX__][name]" class="form-control input-sm"
                           required pattern="[a-z0-9_]+" placeholder="api_token">
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label>Label <span class="text-danger">*</span></label>
                    <input type="text" name="fields[__INDEX__][label]" class="form-control input-sm"
                           required placeholder="API Token">
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label>Type</label>
                    <select name="fields[__INDEX__][type]" class="form-control input-sm">
                        <option value="text">Text</option>
                        <option value="password">Password</option>
                        <option value="number">Number</option>
                        <option value="checkbox">Checkbox</option>
                    </select>
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label>Placeholder</label>
                    <input type="text" name="fields[__INDEX__][placeholder]" class="form-control input-sm">
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label>&nbsp;</label>
                    <div>
                        <label class="checkbox-inline">
                            <input type="hidden" name="fields[__INDEX__][required]" value="0">
                            <input type="checkbox" name="fields[__INDEX__][required]" value="1"> Required
                        </label>
                        <label class="checkbox-inline">
                            <input type="hidden" name="fields[__INDEX__][encrypted]" value="0">
                            <input type="checkbox" name="fields[__INDEX__][encrypted]" value="1" checked> Encrypted
                        </label>
                    </div>
                </div>
            </div>
        </div>
        <button type="button" class="btn btn-danger btn-xs remove-field-btn" style="position: absolute; top: 5px; right: 5px;">
            <i class="fa fa-times"></i>
        </button>
    </div>
</template>

@endsection

@section('scripts')
<script>
$(function() {
    var fieldIndex = {{ count($fields) }};

    // Add field
    $('#addFieldBtn').on('click', function() {
        var template = $('#fieldTemplate').html();
        template = template.replace(/__INDEX__/g, fieldIndex);
        $('#fieldsContainer').append(template);
        $('#noFieldsMessage').hide();
        fieldIndex++;
    });

    // Remove field
    $(document).on('click', '.remove-field-btn', function() {
        $(this).closest('.field-row').remove();
        if ($('.field-row').length === 0) {
            $('#noFieldsMessage').show();
        }
    });
});
</script>
@endsection
