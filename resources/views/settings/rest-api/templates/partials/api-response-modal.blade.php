{{-- API Response Preview Modal with Field Mapping Interface --}}
<div class="modal fade" id="apiResponseModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-xxl" role="document" style="max-width: 90vw;">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-database"></i> API Response Preview & Field Mapping
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>

            <div class="modal-body" style="max-height: 80vh; overflow-y: auto;">
                <div x-data="responsePreviewData()" x-init="init()" class="row">
                    
                    <!-- Left: Raw JSON Response -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-secondary text-white">
                                <h6 class="mb-0">
                                    <i class="fas fa-code"></i> API Response (JSON)
                                </h6>
                            </div>
                            <div class="card-body" style="max-height: 600px; overflow-y: auto; background: #f5f5f5;">
                                <pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word;"><code x-text="formattedJson"></code></pre>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Field Mapping Interface -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0">
                                    <i class="fas fa-arrows-alt-h"></i> Field Mapping
                                </h6>
                            </div>
                            <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                                
                                <!-- Recommendations Summary -->
                                <div x-show="recommendations.length > 0" class="alert alert-success mb-3">
                                    <i class="fas fa-lightbulb"></i>
                                    <strong>Recommendations Found:</strong>
                                    <p class="mb-0" x-text="`${recommendations.length} field mappings suggested based on API response`"></p>
                                </div>

                                <!-- Field Mapping Rows -->
                                <div class="field-mappings">
                                    <template x-for="(field, index) in apiFields" :key="index">
                                        <div class="form-group mb-3 p-2 border rounded" style="background: #f9f9f9;">
                                            <!-- API Response Field Name (read-only) -->
                                            <label class="small font-weight-bold text-muted">Response Field:</label>
                                            <div class="input-group mb-2">
                                                <input type="text" 
                                                       class="form-control form-control-sm" 
                                                       :value="field.name" 
                                                       readonly 
                                                       style="background: #e9ecef;">
                                                <div class="input-group-append">
                                                    <span class="input-group-text small" 
                                                          :title="JSON.stringify(field.sampleValue)">
                                                        <i class="fas fa-eye"></i>
                                                        <span x-text="typeof field.sampleValue === 'object' ? 'object' : field.sampleValue"></span>
                                                    </span>
                                                </div>
                                            </div>

                                            <!-- Recommendation (if available) -->
                                            <template x-if="field.recommendation">
                                                <div class="alert alert-info p-2 mb-2">
                                                    <small>
                                                        <strong>Suggested:</strong>
                                                        <code x-text="field.recommendation.librenms_table + '.' + field.recommendation.librenms_field"></code>
                                                    </small>
                                                </div>
                                            </template>

                                            <!-- LibreNMS Mapped Field -->
                                            <label class="small font-weight-bold text-muted">Map To (table.field):</label>
                                            <input type="text" 
                                                   class="form-control form-control-sm" 
                                                   x-model="field.mappedTo"
                                                   placeholder="e.g., sensors.sensor_current or storage.storage_used"
                                                   @input="field.mappedTo = $event.target.value">
                                        </div>
                                    </template>

                                    <!-- No Fields Message -->
                                    <div x-show="apiFields.length === 0" class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        No fields detected in API response
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="fas fa-times"></i> Close
                </button>
                <button type="button" class="btn btn-primary" @click="saveMappings()">
                    <i class="fas fa-save"></i> Save Field Mappings
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function responsePreviewData() {
    return {
        apiResponse: null,
        recommendations: [],
        apiFields: [],
        formattedJson: '',

        init() {
            // This will be populated by the parent component
            console.log('Response Preview Modal Initialized');
        },

        setData(response, recs) {
            this.apiResponse = response;
            this.recommendations = recs || [];
            this.extractFields();
            this.formatJson();
        },

        extractFields() {
            this.apiFields = [];
            
            if (!this.apiResponse) return;

            // Extract top-level fields from response
            if (Array.isArray(this.apiResponse)) {
                // If response is an array, get fields from first item
                if (this.apiResponse.length > 0 && typeof this.apiResponse[0] === 'object') {
                    this.processObject(this.apiResponse[0], '');
                }
            } else if (typeof this.apiResponse === 'object') {
                // If response is an object, extract its fields
                this.processObject(this.apiResponse, '');
            }
        },

        processObject(obj, prefix) {
            for (const key in obj) {
                if (obj.hasOwnProperty(key)) {
                    const fieldName = prefix ? `${prefix}.${key}` : key;
                    const value = obj[key];
                    
                    // Find recommendation if exists
                    const rec = this.recommendations.find(r => r.api_field === fieldName);
                    
                    this.apiFields.push({
                        name: fieldName,
                        sampleValue: value,
                        recommendation: rec,
                        mappedTo: rec ? `${rec.librenms_table}.${rec.librenms_field}` : ''
                    });
                }
            }
        },

        formatJson() {
            try {
                this.formattedJson = JSON.stringify(this.apiResponse, null, 2);
            } catch (e) {
                this.formattedJson = 'Error formatting JSON';
            }
        },

        saveMappings() {
            // Collect all mappings
            const mappings = this.apiFields
                .filter(f => f.mappedTo)
                .map(f => ({
                    api_field: f.name,
                    mapped_to: f.mappedTo,
                    sample_value: f.sampleValue
                }));

            console.log('Saved Mappings:', mappings);
            alert('Mappings saved! (' + mappings.length + ' fields mapped)');
            
            // TODO: Send to server to save
            // This would POST the mappings to update the endpoint configuration
        }
    }
}
</script>

<style>
#apiResponseModal pre {
    border-radius: 4px;
    padding: 12px;
    background: #f8f9fa;
    border-left: 4px solid #007bff;
}

#apiResponseModal .field-mappings input {
    font-size: 0.875rem;
}

#apiResponseModal code {
    background: #f1f1f1;
    padding: 2px 4px;
    border-radius: 3px;
    font-size: 0.85rem;
}
</style>
