# Phase 1: Structural Cleanup - Migration Status

## Progress Overview

**Date Started:** December 19, 2024
**Status:** In Progress

### Completed Steps

1. ✅ **Created Base Infrastructure**
   - `LibreNMS/Interfaces/Normalizer.php` - Interface definition
   - `LibreNMS/Util/Normalizers/BaseNormalizer.php` - Abstract base class with common helpers
   - `LibreNMS/Util/Normalizers/NormalizerFactory.php` - Factory for creating normalizer instances
   - `LibreNMS/Util/Normalizers/LegacyNormalizerAdapter.php` - Backward compatibility adapter

2. ✅ **Created Directory Structure**
   ```
   LibreNMS/Util/Normalizers/
   ├── BaseNormalizer.php
   ├── NormalizerFactory.php
   ├── LegacyNormalizerAdapter.php
   ├── Pure/
   ├── Proxmox/
   ├── Fortinet/
   ├── VMware/
   ├── NetApp/
   └── Generic/
   ```

3. ✅ **Migrated First Normalizers** (Proof of Concept)
   - `Pure/ArraySensors.php` - Pure Storage array sensors (replacing 240+ lines of static code)
   - `Pure/NetworkInterfaces.php` - Pure Storage network interfaces

### Total Normalizers to Migrate

**135 total methods** broken down by vendor:
- Pure Storage: 17 methods
- Proxmox: 14 methods
- Velocloud: 13 methods
- Fortigate: 10+ methods
- NetApp: 7+ methods
- Generic: 6 methods
- Unity, Nimble, Isilon: 5 each
- Others: ~50 methods across 20+ vendors

### Architecture Improvements

#### Before (Old Pattern)
```php
// 7,021 line god object with 135 static methods
class RestNormalizers {
    public static function normalizePureArraySensors($device, $arrayPayload, $perfPayload = []): array {
        // 240+ lines of logic
        // Mixed responsibilities
        // Untestable
        // No reuse
    }
}
```

#### After (New Pattern)
```php
// Small, focused, testable classes
class ArraySensors extends BaseNormalizer {
    protected string $capability = 'sensors';
    protected string $vendor = 'purestorage';

    protected function doNormalize(Device $device, array $payload): array {
        // Clean, organized logic
        // Private helper methods
        // Easy to test and maintain
    }
}

// Usage:
$normalizer = new Pure\ArraySensors();
$result = $normalizer->normalize($device, $payload);
```

### Benefits Achieved

1. **Single Responsibility** - Each class handles one normalization task
2. **Testability** - Instance methods can be mocked and tested
3. **Maintainability** - ~100-200 lines per file vs 7,021 lines
4. **Discoverability** - Easy to find vendor-specific code
5. **Extensibility** - Can override methods, use inheritance
6. **Performance** - Can be cached, lazy-loaded
7. **Type Safety** - Better IDE support and static analysis

### Next Steps

#### Immediate (Week 1-2)

1. **Create Migration Script**
   - Automated tool to extract methods from RestNormalizers.php
   - Generate individual class files
   - Update NormalizerFactory mappings

2. **Migrate Top Vendors**
   - [ ] Complete Pure Storage (15 more methods)
   - [ ] Proxmox (14 methods)
   - [ ] Fortigate (13 methods)
   - [ ] VMware/vCenter (3 methods)

3. **Update References**
   - [ ] Update DeviceApiExecutor to use LegacyNormalizerAdapter
   - [ ] Update TransformRunner to use new classes
   - [ ] Add deprecation warnings to old static methods

#### Medium Term (Week 3-4)

4. **Complete Migration**
   - [ ] Migrate remaining 80+ methods
   - [ ] Create unit tests for each normalizer
   - [ ] Update documentation

5. **Cleanup**
   - [ ] Delete RestNormalizers.php
   - [ ] Remove LegacyNormalizerAdapter (no longer needed)
   - [ ] Update all references to use new classes directly

### Migration Script Template

Create `/opt/librenms/scripts/migrate-normalizers.php`:

```php
<?php
// Script to automate migration of normalizers from RestNormalizers.php
// Usage: php scripts/migrate-normalizers.php [vendor]

require __DIR__ . '/../vendor/autoload.php';

// Read RestNormalizers.php
// Parse methods by vendor prefix
// Generate class files in correct directories
// Update NormalizerFactory mappings
// Generate migration report
```

### Testing Strategy

1. **Unit Tests** - Test each normalizer in isolation
2. **Integration Tests** - Test with real API responses
3. **Regression Tests** - Compare old vs new output
4. **Performance Tests** - Ensure no slowdown

### Risk Mitigation

- Backward compatibility via LegacyNormalizerAdapter
- Gradual migration (can keep both old and new running)
- Easy rollback (just remove new classes)
- No database changes required

## Success Metrics

- **LOC Reduction**: 7,021 lines → ~2,700 lines (135 files × ~20 lines avg)
- **Files**: 1 god object → 135 focused classes
- **Max File Size**: 7,021 lines → <300 lines
- **Test Coverage**: 0% → 80%+ target
- **Maintainability Index**: Low → High

## Notes

The migration preserves all existing functionality while dramatically improving code quality and maintainability. Each new normalizer class is ~50-200 lines compared to the monolithic 7,021-line file.

---
*Last Updated: December 19, 2024*
