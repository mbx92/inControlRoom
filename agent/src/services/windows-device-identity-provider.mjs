import { createHash } from 'node:crypto';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);

export function createWindowsDeviceIdentityProvider() {
  return {
    async getDeviceId() {
      const machineGuid = await readMachineGuid();
      if (machineGuid) {
        return machineGuid;
      }

      const fingerprintParts = [
        process.env.COMPUTERNAME ?? '',
        await querySingleValue('Win32_ComputerSystemProduct', 'UUID'),
        await querySingleValue('Win32_BIOS', 'SerialNumber'),
      ].filter((value) => value?.trim());

      if (fingerprintParts.length === 0) {
        return process.env.COMPUTERNAME ?? 'unknown-device';
      }

      return createHash('sha256').update(fingerprintParts.join('|'), 'utf8').digest('hex');
    },
  };
}

async function readMachineGuid() {
  try {
    const { stdout } = await execFileAsync(
      'reg',
      ['query', 'HKLM\\SOFTWARE\\Microsoft\\Cryptography', '/v', 'MachineGuid'],
      { windowsHide: true },
    );

    const match = stdout.match(/MachineGuid\s+REG_SZ\s+(.+)/i);
    return match?.[1]?.trim() ?? null;
  } catch {
    return null;
  }
}

async function querySingleValue(className, propertyName) {
  const script = `$value = (Get-CimInstance -ClassName ${className} | Select-Object -First 1 -ExpandProperty ${propertyName}); if ($value) { Write-Output $value }`;

  try {
    const { stdout } = await execFileAsync(
      'powershell.exe',
      ['-NoProfile', '-NonInteractive', '-Command', script],
      { windowsHide: true },
    );

    const value = stdout.trim();
    return value || null;
  } catch {
    return null;
  }
}
