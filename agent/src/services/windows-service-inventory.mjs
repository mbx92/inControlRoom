import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { queryPowerShellJson } from './windows-powershell-json.mjs';

const execFileAsync = promisify(execFile);

export const IMPORTANT_SERVICE_NAMES = [
  'EventLog',
  'Dhcp',
  'Dnscache',
  'LanmanServer',
  'Spooler',
  'TermService',
  'W32Time',
  'Winmgmt',
];

export async function captureImportantServices(serviceNames = IMPORTANT_SERVICE_NAMES) {
  let services = await queryServicesFromWmi(serviceNames);

  if (!services.some((service) => service.name.toLowerCase() === 'eventlog')) {
    services = mergeServiceLists(services, await queryServicesFromSc(['EventLog']));
  }

  if (services.length === 0) {
    services = await queryServicesFromSc(serviceNames);
  }

  return services.sort((left, right) => (
    left.name.localeCompare(right.name, undefined, { sensitivity: 'base' })
  ));
}

export function mapServiceRow(row) {
  return {
    name: row?.Name ?? 'Unknown',
    displayName: row?.DisplayName ?? row?.Name ?? 'Unknown',
    status: normalizeServiceStatus(row?.State ?? row?.Status),
    startMode: normalizeStartMode(row?.StartMode ?? row?.StartType),
  };
}

export function parseScQueryState(stdout) {
  const match = String(stdout ?? '').match(/STATE\s+:\s+(\d+)\s+([A-Z_]+)/i);

  if (!match) {
    return null;
  }

  return normalizeServiceStatus(match[1]);
}

function mergeServiceLists(primary, secondary) {
  const byName = new Map(primary.map((service) => [service.name.toLowerCase(), service]));

  for (const service of secondary) {
    byName.set(service.name.toLowerCase(), service);
  }

  return [...byName.values()];
}

async function queryServicesFromWmi(serviceNames) {
  const quotedNames = serviceNames.map((name) => `'${name.replace(/'/g, "''")}'`).join(',');
  const script = `
$names = @(${quotedNames})
Get-CimInstance Win32_Service |
  Where-Object { $names -contains $_.Name } |
  Select-Object Name, DisplayName, State, StartMode |
  ConvertTo-Json -Compress
`;

  const rows = await queryPowerShellJson(script);

  return rows.map(mapServiceRow);
}

async function queryServicesFromSc(serviceNames) {
  const results = [];

  for (const serviceName of serviceNames) {
    try {
      const { stdout } = await execFileAsync(
        'sc',
        ['query', serviceName],
        { windowsHide: true, maxBuffer: 512 * 1024 },
      );
      const status = parseScQueryState(stdout);

      if (!status) {
        continue;
      }

      results.push({
        name: serviceName,
        displayName: serviceName,
        status,
        startMode: 'Unknown',
      });
    } catch {
      // Service is not installed on this machine.
    }
  }

  return results;
}

function normalizeServiceStatus(value) {
  const normalized = String(value ?? 'Unknown').trim();

  if (/^\d+$/.test(normalized)) {
    const code = Number(normalized);

    if (code === 4) {
      return 'Running';
    }

    if (code === 1) {
      return 'Stopped';
    }

    if (code === 2) {
      return 'StartPending';
    }

    if (code === 3) {
      return 'StopPending';
    }

    return 'Unknown';
  }

  const lower = normalized.toLowerCase();

  if (lower === 'running') {
    return 'Running';
  }

  if (lower === 'stopped') {
    return 'Stopped';
  }

  if (lower === 'start pending') {
    return 'StartPending';
  }

  if (lower === 'stop pending') {
    return 'StopPending';
  }

  return normalized.charAt(0).toUpperCase() + normalized.slice(1).toLowerCase();
}

function normalizeStartMode(value) {
  const normalized = String(value ?? 'Unknown').trim();

  if (normalized === '2' || normalized.toLowerCase() === 'automatic' || normalized === 'Auto') {
    return 'Automatic';
  }

  if (normalized === '3' || normalized.toLowerCase() === 'manual') {
    return 'Manual';
  }

  if (normalized === '4' || normalized.toLowerCase() === 'disabled') {
    return 'Disabled';
  }

  return normalized;
}
