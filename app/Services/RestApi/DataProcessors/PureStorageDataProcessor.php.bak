<?php

namespace App\Services\RestApi\DataProcessors;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Pure Storage FlashArray Data Processor
 * 
 * Handles field conversions, calculations, and transformations for Pure Storage
 * API data before it's stored in LibreNMS tables. Processes:
 * 
 * - Storage calculations (free, percentage)
 * - Interface error aggregations
 * - Transceiver specifications parsing and conversions
 * - DOM threshold extraction
 * - Wavelength/bitrate conversions
 * - Sensor description generation
 */
class PureStorageDataProcessor
{
    /**
     * Process raw API data through conversion functions
     * 
     * @param array $rawData Raw API response data
     * @param array $mapping Template response mapping
     * @param string $resourceType Type of resource (volume, optic, interface, etc.)
     * @param int $deviceId LibreNMS device ID
     * @return array Processed data ready for database insertion
     */
    public static function process(array $rawData, array $mapping, string $resourceType, int $deviceId): array
    {
        $processed = [];

        foreach ($mapping as $key => $mapValue) {
            if (!is_string($mapValue)) {
                continue;
            }

            if (strpos($mapValue, 'calculated:') === 0) {
                // Calculation type
                $calcType = substr($mapValue, strlen('calculated:'));
                $processed[$key] = self::calculateField($calcType, $rawData, $deviceId);
            } elseif (strpos($mapValue, 'resolved:') === 0) {
                // Runtime resolution (e.g., port_id from port name)
                $resolveType = substr($mapValue, strlen('resolved:'));
                $processed[$key] = self::resolveField($resolveType, $rawData, $deviceId);
            } else {
                // Direct value pass-through (kept unchanged)
                $processed[$key] = $mapValue;
            }
        }

        return $processed;
    }

    /**
     * Calculate derived fields
     * 
     * @param string $calcType Type of calculation to perform
     * @param array $rawData Full raw data set for context
     * @param int $deviceId LibreNMS device ID
     * @return mixed Calculated value
     */
    private static function calculateField(string $calcType, array $rawData, int $deviceId): mixed
    {
        switch ($calcType) {
            // ================================================================
            // STORAGE CALCULATIONS
            // ================================================================
            case 'storage_free':
                $size = $rawData['space']['total_provisioned'] ?? 0;
                $used = $rawData['space']['total_physical'] ?? 0;
                return max(0, $size - $used);

            case 'storage_perc':
                $size = $rawData['space']['total_provisioned'] ?? 1;
                $used = $rawData['space']['total_physical'] ?? 0;
                return $size > 0 ? (int) (($used / $size) * 100) : 0;

            // ================================================================
            // INTERFACE ERROR AGGREGATIONS
            // ================================================================
            case 'eth_in_errors':
                $crcErrors = $rawData['eth']['received_crc_errors_per_sec'] ?? 0;
                $frameErrors = $rawData['eth']['received_frame_errors_per_sec'] ?? 0;
                $otherErrors = $rawData['eth']['other_errors_per_sec'] ?? 0;
                return $crcErrors + $frameErrors + $otherErrors;

            case 'eth_out_errors':
                $droppedErrors = $rawData['eth']['transmitted_dropped_errors_per_sec'] ?? 0;
                $carrierErrors = $rawData['eth']['transmitted_carrier_errors_per_sec'] ?? 0;
                return $droppedErrors + $carrierErrors;

            // ================================================================
            // WAVELENGTH CONVERSION
            // ================================================================
            case 'wavelength_nm':
                return self::extractWavelengthNm($rawData['static']['wavelength'] ?? null);

            // ================================================================
            // BITRATE CONVERSIONS
            // ================================================================
            case 'nominal_bitrate_from_signaling':
                return self::convertSignalingRateToBps($rawData['static']['signaling_rate'] ?? null);

            case 'bitrate_tolerance_max':
                return self::extractBitrateTolerance($rawData['static']['signaling_rate_max'] ?? null, 'max');

            case 'bitrate_tolerance_min':
                return self::extractBitrateTolerance($rawData['static']['signaling_rate_min'] ?? null, 'min');

            // ================================================================
            // SENSOR DESCRIPTION GENERATION
            // ================================================================
            case 'port_temp_sensor_descr':
                return ($rawData['name'] ?? 'Port') . ' Optic Temperature';

            case 'port_voltage_sensor_descr':
                return ($rawData['name'] ?? 'Port') . ' Optic Vcc';

            case 'tx_bias_sensor_descr':
                return ($rawData['name'] ?? 'Port') . ' TX Bias Current';

            case 'tx_power_sensor_descr':
                return ($rawData['name'] ?? 'Port') . ' TX Optical Power';

            case 'rx_power_sensor_descr':
                return ($rawData['name'] ?? 'Port') . ' RX Optical Power';

            case 'tx_fault_sensor_descr':
                return ($rawData['name'] ?? 'Port') . ' TX Fault Flag';

            case 'rx_los_sensor_descr':
                return ($rawData['name'] ?? 'Port') . ' RX Loss of Signal';

            // ================================================================
            // DOM THRESHOLDS
            // ================================================================
            case 'temp_limit_high':
                return self::extractThreshold($rawData['static']['temperature_thresholds'] ?? [], 'alarm_high');
            case 'temp_limit_low':
                return self::extractThreshold($rawData['static']['temperature_thresholds'] ?? [], 'alarm_low');
            case 'temp_warn_high':
                return self::extractThreshold($rawData['static']['temperature_thresholds'] ?? [], 'warn_high');
            case 'temp_warn_low':
                return self::extractThreshold($rawData['static']['temperature_thresholds'] ?? [], 'warn_low');

            case 'voltage_limit_high':
                return self::extractThreshold($rawData['static']['voltage_thresholds'] ?? [], 'alarm_high');
            case 'voltage_limit_low':
                return self::extractThreshold($rawData['static']['voltage_thresholds'] ?? [], 'alarm_low');
            case 'voltage_warn_high':
                return self::extractThreshold($rawData['static']['voltage_thresholds'] ?? [], 'warn_high');
            case 'voltage_warn_low':
                return self::extractThreshold($rawData['static']['voltage_thresholds'] ?? [], 'warn_low');

            case 'tx_bias_limit_high':
                return self::extractThreshold($rawData['static']['tx_bias_thresholds'] ?? [], 'alarm_high');
            case 'tx_bias_limit_low':
                return self::extractThreshold($rawData['static']['tx_bias_thresholds'] ?? [], 'alarm_low');
            case 'tx_bias_warn_high':
                return self::extractThreshold($rawData['static']['tx_bias_thresholds'] ?? [], 'warn_high');
            case 'tx_bias_warn_low':
                return self::extractThreshold($rawData['static']['tx_bias_thresholds'] ?? [], 'warn_low');

            case 'tx_power_limit_high':
                return self::extractThreshold($rawData['static']['tx_power_thresholds'] ?? [], 'alarm_high');
            case 'tx_power_limit_low':
                return self::extractThreshold($rawData['static']['tx_power_thresholds'] ?? [], 'alarm_low');
            case 'tx_power_warn_high':
                return self::extractThreshold($rawData['static']['tx_power_thresholds'] ?? [], 'warn_high');
            case 'tx_power_warn_low':
                return self::extractThreshold($rawData['static']['tx_power_thresholds'] ?? [], 'warn_low');

            case 'rx_power_limit_high':
                return self::extractThreshold($rawData['static']['rx_power_thresholds'] ?? [], 'alarm_high');
            case 'rx_power_limit_low':
                return self::extractThreshold($rawData['static']['rx_power_thresholds'] ?? [], 'alarm_low');
            case 'rx_power_warn_high':
                return self::extractThreshold($rawData['static']['rx_power_thresholds'] ?? [], 'warn_high');
            case 'rx_power_warn_low':
                return self::extractThreshold($rawData['static']['rx_power_thresholds'] ?? [], 'warn_low');

            // ================================================================
            // VOLUME PERFORMANCE SENSOR DESCRIPTIONS
            // ================================================================
            case 'volume_name_read_bw':
                return ($rawData['name'] ?? 'Volume') . ' Read Throughput';
            case 'volume_name_write_bw':
                return ($rawData['name'] ?? 'Volume') . ' Write Throughput';
            case 'volume_name_read_iops':
                return ($rawData['name'] ?? 'Volume') . ' Read IOPS';
            case 'volume_name_write_iops':
                return ($rawData['name'] ?? 'Volume') . ' Write IOPS';

            default:
                Log::warning("PureStorageDataProcessor: Unknown calculation type: $calcType");
                return null;
        }
    }

    /**
     * Resolve runtime values
     * 
     * @param string $resolveType Type of resolution
     * @param array $rawData Full raw data
     * @param int $deviceId LibreNMS device ID
     * @return mixed Resolved value
     */
    private static function resolveField(string $resolveType, array $rawData, int $deviceId): mixed
    {
        switch ($resolveType) {
            case 'port_id_from_name':
                $portName = $rawData['name'] ?? null;
                return $portName ? self::resolvePortIdByName($portName, $deviceId) : null;

            case 'device_id':
                return $deviceId;

            default:
                Log::warning("PureStorageDataProcessor: Unknown resolution type: $resolveType");
                return null;
        }
    }

    /**
     * Extract wavelength in nanometers from string like "850 nm"
     */
    private static function extractWavelengthNm(?string $value): ?int
    {
        if (!$value) {
            return null;
        }

        if (preg_match('/(\d+)\s*nm/i', $value, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Convert signaling rate to bits per second
     * 
     * Converts from "14000 MBd", "14 Gbps", etc. to bps
     */
    private static function convertSignalingRateToBps(?string $value): ?int
    {
        if (!$value) {
            return null;
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*(MBd|Mbps|Gbps)/i', $value, $matches)) {
            $rate = (float) $matches[1];
            $unit = strtolower($matches[2]);

            return match ($unit) {
                'mbd', 'mbps' => (int) ($rate * 1_000_000),
                'gbps' => (int) ($rate * 1_000_000_000),
                default => (int) $rate,
            };
        }

        return null;
    }

    /**
     * Extract bitrate tolerance percentage
     */
    private static function extractBitrateTolerance(?string $value, string $type): ?float
    {
        if (!$value) {
            return null;
        }

        if (preg_match('/([+-]?\d+(?:\.\d+)?)\s*%/i', $value, $matches)) {
            return (float) $matches[1];
        }

        return null;
    }

    /**
     * Extract threshold value from thresholds array
     * 
     * Structure:
     * {
     *   "alarm_high": 80.0,
     *   "alarm_low": -10.0,
     *   "warn_high": 70.0,
     *   "warn_low": 0.0
     * }
     */
    private static function extractThreshold(?array $thresholds, string $key): ?float
    {
        if (!$thresholds || !isset($thresholds[$key])) {
            return null;
        }

        $value = $thresholds[$key];
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Resolve port_id by matching port name
     * 
     * Queries ports table to find port_id by ifName and device_id
     */
    private static function resolvePortIdByName(string $portName, int $deviceId): ?int
    {
        try {
            $port = DB::table('ports')
                ->where('ifName', $portName)
                ->where('device_id', $deviceId)
                ->select('port_id')
                ->first();

            return $port ? (int) $port->port_id : null;
        } catch (\Exception $e) {
            Log::warning("Failed to resolve port_id for $portName", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Normalize JSON array fields
     * 
     * For fields like specifications, fc_technology, convert to JSON string
     */
    public static function normalizeArrayField(?array $value): ?string
    {
        if (!$value || !is_array($value)) {
            return null;
        }

        return json_encode($value);
    }

    /**
     * Parse FC link lengths string into structured format
     * 
     * Input: "SM: 10 km, OM1: 20 m"
     * Output: {"SM": {"value": 10, "unit": "km"}, "OM1": {"value": 20, "unit": "m"}}
     */
    public static function parseFcLinkLengths(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $lengths = [];
        $parts = explode(',', $value);

        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/^([A-Z0-9]+):\s*(\d+)\s*([a-z]+)/i', $part, $matches)) {
                $lengths[$matches[1]] = [
                    'value' => (int) $matches[2],
                    'unit' => strtolower($matches[3]),
                ];
            }
        }

        return !empty($lengths) ? json_encode($lengths) : null;
    }

    /**
     * Convert date code like "190221" (YYMMDD) to proper date
     */
    public static function parseDateCode(?string $dateCode): ?string
    {
        if (!$dateCode || strlen($dateCode) < 6) {
            return null;
        }

        try {
            $yy = substr($dateCode, 0, 2);
            $mm = substr($dateCode, 2, 2);
            $dd = substr($dateCode, 4, 2);

            $year = $yy >= 50 ? '19' . $yy : '20' . $yy;

            return "$year-$mm-$dd";
        } catch (\Exception $e) {
            Log::warning("Failed to parse date code: $dateCode", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Normalize status strings to standard values
     */
    public static function normalizeStatus(string $status): string
    {
        return match (strtolower($status)) {
            'ok' => 'ok',
            'ready' => 'up',
            'down' => 'down',
            'warning' => 'warning',
            'critical' => 'critical',
            'fault' => 'down',
            default => $status,
        };
    }
}
