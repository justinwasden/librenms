<?php

namespace LibreNMS\Util\Normalizers;

use LibreNMS\Interfaces\Normalizer;

/**
 * Factory for creating normalizer instances
 * Maps vendor/capability combinations to normalizer classes
 */
class NormalizerFactory
{
    /**
     * Map of normalizer method names to new class names
     * Format: 'methodName' => 'Namespace\\ClassName'
     */
    private static array $normalizerMap = [
        // Pure Storage
        'normalizePureArraySensors' => Pure\ArraySensors::class,
        // Add more as they're created
    
        'normalizePureHardware' => Pure\Hardware::class,
    
        'normalizePureNetworkInterfaces' => Pure\NetworkInterfaces::class,
    
        'normalizePureNetworkPerformance' => Pure\NetworkPerformance::class,
    
        'normalizePurePortOptics' => Pure\PortOptics::class,
    
        'normalizePureVolumes' => Pure\Volumes::class,
    
        'normalizePureHosts' => Pure\Hosts::class,
    
        'normalizePureAlerts' => Pure\Alerts::class,
    
        'normalizePureArrayPerfByLink' => Pure\ArrayPerfByLink::class,
    
        'normalizePureArrayConnections' => Pure\ArrayConnections::class,
    
        'normalizePureConnections' => Pure\Connections::class,
    
        'normalizePureControllers' => Pure\Controllers::class,
    
        'normalizePurePortDetails' => Pure\PortDetails::class,
    
        'normalizePureVolumePerfByArray' => Pure\VolumePerfByArray::class,
    
        'normalizePureSubnets' => Pure\Subnets::class,
    
        'normalizeProxmoxNodeStatus' => Proxmox\NodeStatus::class,
    
        'normalizeProxmoxNodeNetwork' => Proxmox\NodeNetwork::class,
    
        'normalizeProxmoxIpv4' => Proxmox\Ipv4::class,
    
        'normalizeProxmoxNetworkStatistics' => Proxmox\NetworkStatistics::class,
    
        'normalizeProxmoxNodeStorage' => Proxmox\NodeStorage::class,
    
        'normalizeProxmoxClusterStatus' => Proxmox\ClusterStatus::class,
    
        'normalizeProxmoxClusterResources' => Proxmox\ClusterResources::class,
    
        'normalizeFortigateSystemUsage' => Fortinet\SystemUsage::class,
    
        'normalizeFortigateSystemStatus' => Fortinet\SystemStatus::class,
    
        'normalizeFortigateInterfaces' => Fortinet\Interfaces::class,
    
        'normalizeFortigateIpv4' => Fortinet\Ipv4::class,
    
        'normalizeFortigateInterfaceStats' => Fortinet\InterfaceStats::class,
    
        'normalizeFortgateSensorInfo' => Fortinet\SensorInfo::class,
    
        'normalizeFortigateVpnIpsec' => Fortinet\VpnIpsec::class,
    
        'normalizeFortigateVpnSsl' => Fortinet\VpnSsl::class,
    
        'normalizeFortgateDhcp' => Fortinet\Dhcp::class,
    
        'normalizeFortgateLicense' => Fortinet\License::class,
    
        'normalizeFortigatePortsStatistics' => Fortinet\PortsStatistics::class,
    
        'normalizeFortigateVlans' => Fortinet\Vlans::class,
    
        'normalizeFortigateRoutes' => Fortinet\Routes::class,
    
        'normalizeJunosInterfaces' => Juniper\Interfaces::class,
    
        'normalizeJunosInventory' => Juniper\Inventory::class,
    
        'normalizeJunosSystem' => Juniper\System::class,
    
        'normalizeDellSystem' => Dell\System::class,
    
        'normalizeDellInterfaces' => Dell\Interfaces::class,
    
        'normalizeDellSensors' => Dell\Sensors::class,
    
        'normalizeHpeSystem' => HPE\System::class,
    
        'normalizeHpeInterfaces' => HPE\Interfaces::class,
    
        'normalizeHpeSensors' => HPE\Sensors::class,
    
        'normalizeNimbleArrays' => HPE\Arrays::class,
    
        'normalizeNimbleDisks' => HPE\Disks::class,
    
        'normalizeNimbleStats' => HPE\Stats::class,
    
        'normalizeNimbleInterfaces' => HPE\Interfaces::class,
    
        'normalizeNutanixClusters' => Nutanix\Clusters::class,
    
        'normalizeNutanixHosts' => Nutanix\Hosts::class,
    
        'normalizeNutanixStorage' => Nutanix\Storage::class,
    
        'normalizeIseNetworkDevices' => Cisco\NetworkDevices::class,
    
        'normalizeIseEndpoints' => Cisco\Endpoints::class,
    
        'normalizeEsxiVersion' => VMware\Version::class,
    
        'normalizeEsxiHealth' => VMware\Health::class,
    
        'normalizePanInventory' => PaloAlto\Inventory::class,
    
        'normalizePanInterfaces' => PaloAlto\Interfaces::class,
    
        'normalizePanSystem' => PaloAlto\System::class,
    
        'normalizeNxInterfaces' => Cisco\Interfaces::class,
    
        'normalizeNxInventory' => Cisco\Inventory::class,
    
        'normalizeIosxrInterfaces' => Cisco\Interfaces::class,
    
        'normalizeIosxrInventory' => Cisco\Inventory::class,
    
        'normalizeCucmInventory' => Cisco\Inventory::class,
    
        'normalizeCalixDevices' => Calix\Devices::class,
    
        'normalizeCalixInterfaces' => Calix\Interfaces::class,
    
        'normalizeCalixSensors' => Calix\Sensors::class,
    
        'normalizeNdfcDevices' => Cisco\Devices::class,
    
        'normalizeNdfcInterfaces' => Cisco\Interfaces::class,
    
        'normalizeAristaSystem' => Arista\System::class,
    
        'normalizeAristaInterfaces' => Arista\Interfaces::class,
    
        'normalizeAristaSensors' => Arista\Sensors::class,
    
        'normalizeExtremeSystem' => Extreme\System::class,
    
        'normalizeExtremeInterfaces' => Extreme\Interfaces::class,
    
        'normalizeExtremeSensors' => Extreme\Sensors::class,
    
        'normalizeBrocadeSystem' => Brocade\System::class,
    
        'normalizeBrocadeInterfaces' => Brocade\Interfaces::class,
    
        'normalizeSonicSystem' => SonicWall\System::class,
    
        'normalizeSonicInterfaces' => SonicWall\Interfaces::class,
    
        'normalizeSonicSensors' => SonicWall\Sensors::class,
    
        'normalizeCheckpointGateways' => CheckPoint\Gateways::class,
    
        'normalizeCheckpointInterfaces' => CheckPoint\Interfaces::class,
    
        'normalizeOntapEthernetPorts' => NetApp\EthernetPorts::class,
    
        'normalizeOntapVolumesToStorage' => NetApp\VolumesToStorage::class,
    
        'normalizeOntapAggregatesToSensors' => NetApp\AggregatesToSensors::class,
    
        'normalizeOntapNodesToInventory' => NetApp\NodesToInventory::class,
    
        'normalizeOntapDisksToInventory' => NetApp\DisksToInventory::class,
    
        'normalizeOntapNodeMetricsToProcessorsMempools' => NetApp\NodeMetricsToProcessorsMempools::class,
    
        'normalizeUnityPoolsToStorage' => NetApp\PoolsToStorage::class,
    
        'normalizeUnityResourcesToSensors' => NetApp\ResourcesToSensors::class,
    
        'normalizeUnityResourcesToInventory' => NetApp\ResourcesToInventory::class,
    
        'normalizeUnityDisksToInventory' => NetApp\DisksToInventory::class,
    
        'normalizeUnityEthPortsToPorts' => NetApp\EthPortsToPorts::class,
    
        'normalizeIsilonInterfacesToPorts' => NetApp\InterfacesToPorts::class,
    
        'normalizeIsilonPoolsToStorage' => NetApp\PoolsToStorage::class,
    
        'normalizeIsilonNodesToInventory' => NetApp\NodesToInventory::class,
    
        'normalizeIsilonNodesToSensors' => NetApp\NodesToSensors::class,
    
        'normalizeIsilonClusterStatusToSensors' => NetApp\ClusterStatusToSensors::class,
    
        'normalizeGenericHrDevice' => Generic\HrDevice::class,
    
        'normalizeGenericHrSystem' => Generic\HrSystem::class,
    
        'normalizeGenericIpv4Addresses' => Generic\Ipv4Addresses::class,
    
        'normalizeGenericIpv4Mac' => Generic\Ipv4Mac::class,
    
        'normalizeGenericIpv4Networks' => Generic\Ipv4Networks::class,
    
        'normalizeGenericTransceivers' => Generic\Transceivers::class,
    
        'normalizePureVolumesToStorage' => Pure\VolumesToStorage::class,
    
        'normalizeOntapVolumesToStorageDb' => NetApp\VolumesToStorageDb::class,
    
        'normalizePureDeviceInfo' => Pure\DeviceInfo::class,
    
        'normalizeFortigateDeviceInfo' => Fortinet\DeviceInfo::class,
    
        'normalizeJunosDeviceInfo' => Juniper\DeviceInfo::class,
    
        'normalizeDellDeviceInfo' => Dell\DeviceInfo::class,
    
        'normalizeHpeDeviceInfo' => HPE\DeviceInfo::class,
    
        'normalizeProxmoxDeviceInfo' => Proxmox\DeviceInfo::class,
    
        'normalizeNetappDeviceInfo' => NetApp\DeviceInfo::class,
    
        'normalizeNimbleDeviceInfo' => HPE\DeviceInfo::class,
    
        'normalizeProxmoxDiskList' => Proxmox\DiskList::class,
    
        'normalizeProxmoxDiskSmart' => Proxmox\DiskSmart::class,
    
        'normalizeProxmoxStorageStatus' => Proxmox\StorageStatus::class,
    
        'normalizeProxmoxGuestDiscovery' => Proxmox\GuestDiscovery::class,
    
        'normalizeProxmoxClusterInfo' => Proxmox\ClusterInfo::class,
    
        'normalizeProxmoxNodes' => Proxmox\Nodes::class,
    
        'normalizeVelocloudDeviceInfo' => VMware\DeviceInfo::class,
    
        'normalizeVelocloudInventory' => VMware\Inventory::class,
    
        'normalizeVelocloudPorts' => VMware\Ports::class,
    
        'normalizeVelocloudIpv4' => VMware\Ipv4::class,
    
        'normalizeVelocloudSensors' => VMware\Sensors::class,
    
        'normalizeVelocloudProcessors' => VMware\Processors::class,
    
        'normalizeVelocloudMempools' => VMware\Mempools::class,
    
        'normalizeVelocloudVlans' => VMware\Vlans::class,
    
        'normalizeVelocloudSystemMetrics' => VMware\SystemMetrics::class,
    
        'normalizeVelocloudPortStatistics' => VMware\PortStatistics::class,
    
        'normalizeVcenterDeviceInfo' => VMware\DeviceInfo::class,
    
        'normalizeFtdDeviceHostname' => Cisco\DeviceHostname::class,
    
        'normalizeFtdDiskUsage' => Cisco\DiskUsage::class,
    
        'normalizeFtdMetrics' => Cisco\Metrics::class,
    
        'normalizeVelocloudConfigStackPorts' => VMware\ConfigStackPorts::class,
    
        'normalizeVelocloudPortLabels' => VMware\PortLabels::class,
    
        'normalizeVelocloudConfigStackIpv4' => VMware\ConfigStackIpv4::class,
    ];

    /**
     * Create normalizer instance from method name
     *
     * @param string $methodName Old static method name (e.g., 'normalizePureArraySensors')
     * @return Normalizer|null
     */
    public static function make(string $methodName): ?Normalizer
    {
        $className = self::$normalizerMap[$methodName] ?? null;

        if (!$className || !class_exists($className)) {
            return null;
        }

        return new $className();
    }

    /**
     * Check if a normalizer exists for a given method name
     *
     * @param string $methodName
     * @return bool
     */
    public static function exists(string $methodName): bool
    {
        return isset(self::$normalizerMap[$methodName]);
    }

    /**
     * Get all registered normalizers
     *
     * @return array
     */
    public static function getAll(): array
    {
        return self::$normalizerMap;
    }

    /**
     * Register a new normalizer
     *
     * @param string $methodName
     * @param string $className
     * @return void
     */
    public static function register(string $methodName, string $className): void
    {
        self::$normalizerMap[$methodName] = $className;
    }
}
