import assert from 'node:assert/strict';
import test from 'node:test';
import {
  mapWmiMemoryRow,
  mapWmiProcessorRow,
  mergeProcessorDetails,
  shouldUseProcessorFallback,
} from '../src/services/windows-hardware-inventory.mjs';

test('maps WMI processor row to agent cpu fields', () => {
  const mapped = mapWmiProcessorRow({
    Name: 'Intel(R) Core(TM) i7-12700 CPU @ 2.10GHz',
    Manufacturer: 'GenuineIntel',
    NumberOfCores: 12,
    NumberOfLogicalProcessors: 20,
  });

  assert.equal(mapped.cpuBrand, 'Intel(R) Core(TM) i7-12700 CPU @ 2.10GHz');
  assert.equal(mapped.cpuManufacturer, 'GenuineIntel');
  assert.equal(mapped.cpuPhysicalCores, 12);
  assert.equal(mapped.cpuCores, 20);
});

test('prefers WMI processor name when systeminformation brand is generic', () => {
  const merged = mergeProcessorDetails(
    {
      brand: 'GenuineIntel',
      manufacturer: 'Intel',
      cores: 20,
      physicalCores: 12,
    },
    {
      Name: 'Intel(R) Core(TM) i7-12700 CPU @ 2.10GHz',
      Manufacturer: 'GenuineIntel',
      NumberOfCores: 12,
      NumberOfLogicalProcessors: 20,
    },
  );

  assert.equal(merged.cpuBrand, 'Intel(R) Core(TM) i7-12700 CPU @ 2.10GHz');
  assert.equal(merged.cpuCores, 20);
});

test('maps WMI memory module rows', () => {
  const mapped = mapWmiMemoryRow({
    DeviceLocator: 'DIMM_A1',
    Capacity: 8589934592,
    SMBIOSMemoryType: 26,
    ConfiguredClockSpeed: 3200,
    Manufacturer: 'Samsung',
    PartNumber: 'M378A1K43CB2',
    FormFactor: 8,
    SerialNumber: '123456',
  }, 0);

  assert.equal(mapped.slot, 'DIMM_A1');
  assert.equal(mapped.sizeBytes, 8589934592);
  assert.equal(mapped.type, 'DDR4');
  assert.equal(mapped.speedMhz, 3200);
  assert.equal(mapped.manufacturer, 'Samsung');
});

test('detects weak processor names from systeminformation', () => {
  assert.equal(shouldUseProcessorFallback({ brand: 'GenuineIntel' }), true);
  assert.equal(shouldUseProcessorFallback({ brand: 'Intel(R) Core(TM) i7-12700' }), false);
});
