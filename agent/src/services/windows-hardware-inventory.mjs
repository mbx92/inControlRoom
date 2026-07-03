const MEMORY_TYPE_LABELS = {
  20: 'DDR',
  21: 'DDR2',
  22: 'DDR2',
  24: 'DDR3',
  26: 'DDR4',
  34: 'DDR5',
};

const FORM_FACTOR_LABELS = {
  8: 'DIMM',
  12: 'SODIMM',
};

export function mapWmiProcessorRow(row) {
  const name = normalizeWmiString(row?.Name);

  return {
    cpuBrand: name || null,
    cpuManufacturer: normalizeWmiString(row?.Manufacturer) || null,
    cpuCores: toPositiveInt(row?.NumberOfLogicalProcessors),
    cpuPhysicalCores: toPositiveInt(row?.NumberOfCores),
  };
}

export function mapWmiMemoryRow(row, index = 0) {
  const sizeBytes = parseCapacityBytes(row?.Capacity);

  if (!sizeBytes) {
    return null;
  }

  return {
    slot: normalizeWmiString(row?.DeviceLocator)
      || normalizeWmiString(row?.BankLabel)
      || `Slot ${index + 1}`,
    sizeBytes,
    type: mapMemoryType(row?.SMBIOSMemoryType ?? row?.MemoryType),
    speedMhz: toPositiveInt(row?.ConfiguredClockSpeed ?? row?.Speed),
    manufacturer: normalizeWmiString(row?.Manufacturer),
    partNumber: normalizeWmiString(row?.PartNumber),
    formFactor: mapFormFactor(row?.FormFactor),
    serialNumber: normalizeWmiString(row?.SerialNumber),
  };
}

export function shouldUseProcessorFallback(cpuInfo) {
  const brand = normalizeWmiString(cpuInfo?.brand);

  if (!brand) {
    return true;
  }

  const lower = brand.toLowerCase();

  if (['unknown', 'generic', 'processor', 'genuineintel', 'authenticamd', 'arm'].includes(lower)) {
    return true;
  }

  return brand.length < 8;
}

export function mergeProcessorDetails(cpuInfo, wmiRow) {
  const wmi = mapWmiProcessorRow(wmiRow);
  const siBrand = normalizeWmiString(cpuInfo?.brand);

  return {
    cpuBrand: pickBetterProcessorName(siBrand, wmi.cpuBrand),
    cpuManufacturer: normalizeWmiString(cpuInfo?.manufacturer) || wmi.cpuManufacturer,
    cpuCores: cpuInfo?.cores ?? wmi.cpuCores,
    cpuPhysicalCores: cpuInfo?.physicalCores ?? wmi.cpuPhysicalCores,
  };
}

function pickBetterProcessorName(siBrand, wmiBrand) {
  if (!siBrand) {
    return wmiBrand;
  }

  if (!wmiBrand) {
    return siBrand;
  }

  if (shouldUseProcessorFallback({ brand: siBrand })) {
    return wmiBrand;
  }

  return wmiBrand.length > siBrand.length ? wmiBrand : siBrand;
}

function mapMemoryType(value) {
  const code = Number(value);

  if (!Number.isFinite(code) || code === 0) {
    return null;
  }

  return MEMORY_TYPE_LABELS[code] ?? `Type ${code}`;
}

function mapFormFactor(value) {
  const code = Number(value);

  if (!Number.isFinite(code) || code === 0) {
    return null;
  }

  return FORM_FACTOR_LABELS[code] ?? `Form ${code}`;
}

function normalizeWmiString(value) {
  if (value === null || value === undefined) {
    return null;
  }

  const normalized = String(value).replace(/\u0000/g, '').trim();

  return normalized === '' ? null : normalized;
}

export function parseCapacityBytes(value) {
  if (value === null || value === undefined || value === '') {
    return 0;
  }

  if (typeof value === 'bigint') {
    return Number(value);
  }

  const parsed = Number(String(value).replace(/[^\d.-]/g, ''));

  if (!Number.isFinite(parsed) || parsed <= 0) {
    return 0;
  }

  return Math.trunc(parsed);
}

function toPositiveInt(value) {
  const parsed = Number(value);

  if (!Number.isFinite(parsed) || parsed <= 0) {
    return null;
  }

  return Math.trunc(parsed);
}
