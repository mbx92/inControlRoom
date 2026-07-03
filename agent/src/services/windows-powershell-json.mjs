import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);

export async function queryPowerShellJson(script) {
  try {
    const { stdout } = await execFileAsync(
      'powershell.exe',
      ['-NoProfile', '-NonInteractive', '-Command', script],
      { windowsHide: true, maxBuffer: 8 * 1024 * 1024 },
    );

    return parsePowerShellJson(stdout);
  } catch {
    return [];
  }
}

export function parsePowerShellJson(stdout) {
  const trimmed = stripBom(String(stdout ?? '').trim());

  if (!trimmed) {
    return [];
  }

  try {
    return normalizeJsonRows(JSON.parse(trimmed));
  } catch {
    const match = trimmed.match(/(\[[\s\S]*\]|\{[\s\S]*\})/);

    if (!match) {
      return [];
    }

    try {
      return normalizeJsonRows(JSON.parse(match[1]));
    } catch {
      return [];
    }
  }
}

function normalizeJsonRows(parsed) {
  if (Array.isArray(parsed)) {
    return parsed;
  }

  return parsed ? [parsed] : [];
}

function stripBom(value) {
  return value.replace(/^\uFEFF/, '');
}
