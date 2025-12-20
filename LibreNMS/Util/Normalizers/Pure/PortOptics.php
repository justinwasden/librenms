<?php

namespace LibreNMS\Util\Normalizers\Pure;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Pure - PortOptics Normalizer
 *
 * Capability: transceivers
 * Vendor: pure
 */
class PortOptics extends BaseNormalizer
{
    protected string $capability = 'transceivers';
    protected string $vendor = 'pure';

    protected function doNormalize(Device $device, array $payload): array
    {
if (!is_array($payload)) {
            return [];
        }

        $transceivers = [];
        $sensors = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return ['transceivers' => $transceivers, 'sensors' => $sensors];
        }

        foreach ($payload['items'] as $port) {
            $name = strtolower($port['name'] ?? 'unknown');
            $index = $this->stableIndexFromName($name);
            $static = $port['static'] ?? [];

            // Only process ports that have transceiver data
            if (!empty($static) && isset($static['vendor_name'])) {
                // Parse wavelength and distance to numeric values
                $wavelength = $this->parseWavelength($static['wavelength'] ?? null);
                $distance = $this->parseLinkLength($static['link_length'] ?? null);
                
                // Build transceiver record
                $transceiver = [
                    'ifName' => $name,
                    'index' => $index,
                    'type' => $static['identifier'] ?? null,
                    'vendor' => $static['vendor_name'] ?? null,
                    'oui' => $static['vendor_oui'] ?? null,
                    'model' => $static['vendor_part_number'] ?? null,
                    'revision' => $static['vendor_revision'] ?? null,
                    'serial' => $static['vendor_serial_number'] ?? null,
                    'date' => $static['vendor_date_code'] ?? null,
                    'encoding' => $static['encoding'] ?? null,
                    'connector' => $static['connector_type'] ?? null,
                    'wavelength' => $wavelength,
                    'distance' => $distance,
                    'cable' => $static['cable_technology'] ?? null,
                    'channels' => 1, // Default to 1, will be overridden if multi-channel
                ];

                // Detect number of channels from tx_power or rx_power arrays
                if (isset($port['tx_power']) && is_array($port['tx_power'])) {
                    $transceiver['channels'] = count($port['tx_power']);
                } elseif (isset($port['rx_power']) && is_array($port['rx_power'])) {
                    $transceiver['channels'] = count($port['rx_power']);
                }

                $transceivers[] = $transceiver;

                // Create sensors for temperature, voltage, and optical power
                // Temperature sensor
                if (isset($port['temperature']) && is_array($port['temperature'])) {
                    foreach ($port['temperature'] as $temp) {
                        if (isset($temp['measurement']) && $temp['measurement'] != 0) {
                            $sensors[] = [
                                'sensor_class' => 'temperature',
                                'sensor_type' => 'purestorage',
                                'sensor_descr' => $name . ' Temperature',
                                'sensor_index' => 'port_temp_' . $index,
                                'sensor_current' => round($temp['measurement']),
                                'sensor_limit' => $static['temperature_thresholds']['alarm_high'] ?? 70,
                                'sensor_limit_low' => $static['temperature_thresholds']['alarm_low'] ?? -5,
                            ];
                            break; // Only one temperature sensor per port
                        }
                    }
                }

                // Voltage sensor
                if (isset($port['voltage']) && is_array($port['voltage'])) {
                    foreach ($port['voltage'] as $volt) {
                        if (isset($volt['measurement']) && $volt['measurement'] != 0) {
                            $sensors[] = [
                                'sensor_class' => 'voltage',
                                'sensor_type' => 'purestorage',
                                'sensor_descr' => $name . ' Voltage',
                                'sensor_index' => 'port_volt_' . $index,
                                'sensor_current' => $volt['measurement'],
                                'sensor_limit' => $static['voltage_thresholds']['alarm_high'] ?? 3.6,
                                'sensor_limit_low' => $static['voltage_thresholds']['alarm_low'] ?? 3.0,
                            ];
                            break; // Only one voltage sensor per port
                        }
                    }
                }

                // TX/RX Power sensors for each channel
                if (isset($port['rx_power']) && is_array($port['rx_power'])) {
                    foreach ($port['rx_power'] as $rx) {
                        $channel = $rx['channel'] ?? '';
                        $measurement = $rx['measurement'] ?? null;
                        if ($measurement !== null && $measurement != 0) {
                            $channelSuffix = $channel ? " Ch{$channel}" : '';
                            $sensors[] = [
                                'sensor_class' => 'dbm',
                                'sensor_type' => 'purestorage',
                                'sensor_descr' => $name . $channelSuffix . ' RX Power',
                                'sensor_index' => 'port_rx_' . $index . ($channel ? '_ch' . $channel : ''),
                                'sensor_current' => $measurement,
                                'sensor_limit' => $static['rx_power_thresholds']['alarm_high'] ?? 0,
                                'sensor_limit_low' => $static['rx_power_thresholds']['alarm_low'] ?? -20,
                            ];
                        }
                    }
                }

                if (isset($port['tx_power']) && is_array($port['tx_power'])) {
                    foreach ($port['tx_power'] as $tx) {
                        $channel = $tx['channel'] ?? '';
                        $measurement = $tx['measurement'] ?? null;
                        if ($measurement !== null && $measurement != 0) {
                            $channelSuffix = $channel ? " Ch{$channel}" : '';
                            $sensors[] = [
                                'sensor_class' => 'dbm',
                                'sensor_type' => 'purestorage',
                                'sensor_descr' => $name . $channelSuffix . ' TX Power',
                                'sensor_index' => 'port_tx_' . $index . ($channel ? '_ch' . $channel : ''),
                                'sensor_current' => $measurement,
                                'sensor_limit' => $static['tx_power_thresholds']['alarm_high'] ?? 2,
                                'sensor_limit_low' => $static['tx_power_thresholds']['alarm_low'] ?? -10,
                            ];
                        }
                    }
                }

                // TX Bias sensors for each channel
                if (isset($port['tx_bias']) && is_array($port['tx_bias'])) {
                    foreach ($port['tx_bias'] as $bias) {
                        $channel = $bias['channel'] ?? '';
                        $measurement = $bias['measurement'] ?? null;
                        if ($measurement !== null && $measurement != 0) {
                            $channelSuffix = $channel ? " Ch{$channel}" : '';
                            $sensors[] = [
                                'sensor_class' => 'current',
                                'sensor_type' => 'purestorage',
                                'sensor_descr' => $name . $channelSuffix . ' TX Bias',
                                'sensor_index' => 'port_txbias_' . $index . ($channel ? '_ch' . $channel : ''),
                                'sensor_current' => $measurement,
                                'sensor_limit' => $static['tx_bias_thresholds']['alarm_high'] ?? 100,
                                'sensor_limit_low' => $static['tx_bias_thresholds']['alarm_low'] ?? 0,
                            ];
                        }
                    }
                }
            }
        }

        return ['transceivers' => $transceivers, 'sensors' => $sensors];
    }
}
