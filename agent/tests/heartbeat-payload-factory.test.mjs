import assert from 'node:assert/strict';
import test from 'node:test';
import { createHeartbeatPayloadFactory } from '../src/services/heartbeat-payload-factory.mjs';

test('creates expected heartbeat payload shape', () => {
  const factory = createHeartbeatPayloadFactory();
  const device = {
    deviceId: 'device-1',
    hostname: 'CLIENT-01',
    os: 'Windows',
    osVersion: '11 Pro',
    arch: 'x64',
    primaryIp: '10.20.30.40',
    agentVersion: '1.0.0',
  };
  const inventory = {
    metrics: {
      cpuUsagePercent: 18.5,
      cpuBrand: 'Intel Core i7-12700',
      cpuManufacturer: 'Intel',
      cpuCores: 20,
      cpuPhysicalCores: 12,
      totalMemoryBytes: 16000,
      freeMemoryBytes: 8000,
      memorySlotsUsed: 2,
      memorySlots: [
        {
          slot: 'BANK 0',
          sizeBytes: 8000,
          type: 'DDR4',
          speedMhz: 3200,
          manufacturer: 'Samsung',
          partNumber: 'M378A1K43CB2',
          formFactor: 'DIMM',
          serialNumber: 'SN-1',
        },
      ],
      disks: [
        {
          name: 'C:\\',
          totalBytes: 1000,
          freeBytes: 250,
          filesystem: 'NTFS',
          type: 'NTFS',
        },
      ],
      storageDevices: [
        {
          device: '\\\\.\\PHYSICALDRIVE0',
          name: 'Samsung SSD 980',
          type: 'SSD',
          vendor: 'Samsung',
          sizeBytes: 500_000_000_000,
          interfaceType: 'NVMe',
          serialNumber: 'SSD-123',
          firmwareRevision: '1.0',
          smartStatus: 'Ok',
        },
      ],
      network: [
        {
          iface: 'Ethernet',
          operstate: 'up',
          mac: '00-11-22-33-44-55',
          ipv4: '10.20.30.40',
          speedMbps: 1000,
          rxBytes: 1000,
          txBytes: 2000,
          rxErrors: 0,
          txErrors: 0,
          rxDropped: 0,
          txDropped: 0,
        },
      ],
      usbDevices: [
        {
          name: 'USB Keyboard',
          type: 'Keyboard',
          vendor: 'Logitech',
          manufacturer: 'Logitech',
          deviceId: 'USB\\VID_046D',
          serialNumber: 'KB-1',
        },
      ],
    },
    services: [
      {
        name: 'Spooler',
        displayName: 'Print Spooler',
        status: 'Running',
        startMode: 'Automatic',
      },
    ],
    labels: ['branch-a'],
  };

  const request = factory.createHeartbeatRequest(device, inventory);

  assert.equal(request.device_id, 'device-1');
  assert.equal(request.hostname, 'CLIENT-01');
  assert.equal(request.os, 'Windows');
  assert.equal(request.os_version, '11 Pro');
  assert.equal(request.arch, 'x64');
  assert.equal(request.primary_ip, '10.20.30.40');
  assert.deepEqual(request.labels, ['branch-a']);
  assert.equal(request.services.length, 1);
  assert.equal(request.metrics.cpu.usage_percent, 18.5);
  assert.equal(request.metrics.cpu.brand, 'Intel Core i7-12700');
  assert.equal(request.metrics.cpu.manufacturer, 'Intel');
  assert.equal(request.metrics.cpu.cores, 20);
  assert.equal(request.metrics.cpu.physical_cores, 12);
  assert.equal(request.metrics.memory.total_bytes, 16000);
  assert.equal(request.metrics.memory.slots_used, 2);
  assert.equal(request.metrics.memory.slots[0].part_number, 'M378A1K43CB2');
  assert.equal(request.metrics.disks[0].free_bytes, 250);
  assert.equal(request.metrics.storage_devices[0].interface_type, 'NVMe');
  assert.equal(request.metrics.network[0].rx_bytes, 1000);
  assert.equal(request.metrics.usb_devices[0].device_id, 'USB\\VID_046D');
});
