import { execFileSync } from 'node:child_process';

export function createDpapiSecretProtector() {
  return {
    protect(plainText) {
      if (!plainText) {
        return '';
      }

      return runDpapi('Protect', plainText);
    },

    unprotect(cipherText) {
      if (!cipherText?.trim()) {
        return '';
      }

      return runDpapi('Unprotect', cipherText, true);
    },
  };
}

function runDpapi(action, value, isCipherText = false) {
  const script = `
Add-Type -AssemblyName System.Security
$inputValue = [System.Environment]::GetEnvironmentVariable('IC_AGENT_DPAPI_VALUE')
$bytes = if ('${isCipherText ? 'true' : 'false'}' -eq 'true') {
  [Convert]::FromBase64String($inputValue)
} else {
  [Text.Encoding]::UTF8.GetBytes($inputValue)
}
$result = [System.Security.Cryptography.ProtectedData]::${action}(
  $bytes,
  $null,
  [System.Security.Cryptography.DataProtectionScope]::LocalMachine
)
if ('${action}' -eq 'Protect') {
  [Convert]::ToBase64String($result)
} else {
  [Text.Encoding]::UTF8.GetString($result)
}
`.trim();

  const stdout = execFileSync(
    'powershell.exe',
    ['-NoProfile', '-NonInteractive', '-Command', script],
    {
      env: {
        ...process.env,
        IC_AGENT_DPAPI_VALUE: value,
      },
      windowsHide: true,
      encoding: 'utf8',
    },
  );

  return stdout.trim();
}
