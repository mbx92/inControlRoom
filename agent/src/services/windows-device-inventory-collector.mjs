import os from 'node:os';
import si from 'systeminformation';
import {
  mapWmiMemoryRow,
  mergeProcessorDetails,
} from './windows-hardware-inventory.mjs';
import { queryPowerShellJson } from './windows-powershell-json.mjs';
import { captureImportantServices } from './windows-service-inventory.mjs';

export function createWindowsDeviceInventoryCollector(identityProvider, agentVersion = '1.0.0') {
  return {
    async captureDevice() {
      const deviceId = await identityProvider.getDeviceId();

      return {
        deviceId,
        hostname: os.hostname(),
        os: 'Windows',
        osVersion: `${os.type()} ${os.release()}`,
        arch: process.arch,
        primaryIp: getPrimaryIpAddress(),
        agentVersion,
      };
    },

    async captureInventory() {
      const [
        cpuLoad,
        cpuInfo,
        memory,
        memLayout,
        fsSize,
        networkStats,
        networkInterfaces,
        diskLayout,
        usbDevices,
        services,
        wmiProcessors,
        wmiMemoryModules,
      ] = await Promise.all([
        si.currentLoad().catch(() => ({ currentLoad: 0 })),
        si.cpu().catch(() => ({})),
        si.mem().catch(() => ({ total: 0, free: 0 })),
        si.memLayout().catch(() => []),
        si.fsSize().catch(() => []),
        si.networkStats().catch(() => []),
        si.networkInterfaces().catch(() => []),
        si.diskLayout().catch(() => []),
        si.usb().catch(() => []),
        captureImportantServices(),
        queryPowerShellJson(
          'Get-CimInstance Win32_Processor | Select-Object Name, Manufacturer, NumberOfCores, NumberOfLogicalProcessors | ConvertTo-Json -Compress',
        ),
        queryPowerShellJson(
          'Get-CimInstance Win32_PhysicalMemory | Select-Object DeviceLocator, BankLabel, Capacity, MemoryType, SMBIOSMemoryType, Speed, ConfiguredClockSpeed, Manufacturer, PartNumber, FormFactor, SerialNumber | ConvertTo-Json -Compress',
        ),
      ]);

      const processor = mergeProcessorDetails(cpuInfo, wmiProcessors[0] ?? {});
      const memorySlots = resolveMemorySlots(memLayout, wmiMemoryModules);
      const network = mapNetworkInterfaces(networkStats, networkInterfaces);

      return {
        metrics: {
          cpuUsagePercent: round(cpuLoad.currentLoad ?? 0),
          cpuBrand: processor.cpuBrand,
          cpuManufacturer: processor.cpuManufacturer,
          cpuCores: processor.cpuCores,
          cpuPhysicalCores: processor.cpuPhysicalCores,
          totalMemoryBytes: memory.total ?? 0,
          freeMemoryBytes: memory.free ?? 0,
          memorySlots,
          memorySlotsUsed: memorySlots.length,
          disks: fsSize.map((disk) => ({
            name: disk.mount.endsWith('\\') ? disk.mount : `${disk.mount}\\`,
            totalBytes: disk.size ?? 0,
            freeBytes: disk.available ?? 0,
            filesystem: disk.fs ?? null,
            type: disk.type ?? null,
          })),
          storageDevices: diskLayout.map((disk) => ({
            device: disk.device ?? null,
            name: disk.name ?? disk.vendor ?? 'Unknown storage device',
            type: disk.type ?? null,
            vendor: disk.vendor ?? null,
            sizeBytes: disk.size ?? 0,
            interfaceType: disk.interfaceType ?? null,
            serialNumber: disk.serialNum ?? null,
            firmwareRevision: disk.firmwareRevision ?? null,
            smartStatus: disk.smartStatus ?? null,
          })),
          network,
          usbDevices: usbDevices.map((device) => ({
            name: device.name ?? 'Unknown USB device',
            type: device.type ?? null,
            vendor: device.vendor ?? null,
            manufacturer: device.manufacturer ?? null,
            deviceId: device.deviceId ?? null,
            serialNumber: device.serialNumber ?? null,
          })),
        },
        services,
        labels: [],
      };
    },
  };
}

function mapMemorySlots(memLayout) {
  return memLayout
    .map((slot, index) => mapMemorySlot(slot, index))
    .filter(Boolean)
    .sort((left, right) => left.slot.localeCompare(right.slot, undefined, { sensitivity: 'base' }));
}

function mapMemorySlot(slot, index) {
  const sizeBytes = Number(slot.size ?? 0);

  if (!Number.isFinite(sizeBytes) || sizeBytes <= 0) {
    return null;
  }

  return {
    slot: slot.bank || slot.locator || `Slot ${index + 1}`,
    sizeBytes,
    type: slot.type ?? null,
    speedMhz: slot.clockSpeed ?? null,
    manufacturer: slot.manufacturer ?? null,
    partNumber: slot.partNum ?? null,
    formFactor: slot.formFactor ?? null,
    serialNumber: slot.serialNum ?? null,
  };
}

function resolveMemorySlots(memLayout, wmiRows) {
  const wmiSlots = wmiRows
    .map((row, index) => mapWmiMemoryRow(row, index))
    .filter(Boolean)
    .sort((left, right) => left.slot.localeCompare(right.slot, undefined, { sensitivity: 'base' }));

  if (wmiSlots.length > 0) {
    return wmiSlots;
  }

  return mapMemorySlots(memLayout);
}

function mapNetworkInterfaces(networkStats, networkInterfaces) {
  const interfaceMap = new Map(
    networkInterfaces.map((entry) => [entry.iface, entry]),
  );

  return networkStats
    .filter((entry) => !isLoopbackInterface(entry.iface))
    .map((entry) => {
      const details = interfaceMap.get(entry.iface) ?? {};

      return {
        iface: entry.iface,
        operstate: entry.operstate ?? details.operstate ?? 'unknown',
        mac: details.mac ?? null,
        ipv4: pickIpv4(details.ip4) ?? null,
        speedMbps: details.speed ?? null,
        rxBytes: entry.rx_bytes ?? 0,
        txBytes: entry.tx_bytes ?? 0,
        rxErrors: entry.rx_errors ?? 0,
        txErrors: entry.tx_errors ?? 0,
        rxDropped: entry.rx_dropped ?? 0,
        txDropped: entry.tx_dropped ?? 0,
      };
    })
    .sort((left, right) => left.iface.localeCompare(right.iface, undefined, { sensitivity: 'base' }));
}

function pickIpv4(value) {
  if (!value) {
    return null;
  }

  return String(value).split('%')[0];
}

function isLoopbackInterface(name) {
  const normalized = String(name ?? '').toLowerCase();

  return normalized.includes('loopback') || normalized === 'lo';
}

function getPrimaryIpAddress() {
  const interfaces = os.networkInterfaces();

  for (const entries of Object.values(interfaces)) {
    for (const entry of entries ?? []) {
      if (entry.family === 'IPv4' && !entry.internal) {
        return entry.address;
      }
    }
  }

  return null;
}

function round(value) {
  return Math.round(value * 100) / 100;
}
