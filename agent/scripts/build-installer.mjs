import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const agentRoot = fileURLToPath(new URL('..', import.meta.url));
const issPath = path.join(agentRoot, 'installer', 'infracontrol-agent.iss');
const isccPath = resolveIsccPath();

execFileSync(isccPath, [issPath], {
  stdio: 'inherit',
  cwd: agentRoot,
});

function resolveIsccPath() {
  if (process.env.ISCC) {
    return process.env.ISCC;
  }

  const candidates = [
    'iscc',
    'ISCC.exe',
    path.join(process.env['ProgramFiles(x86)'] ?? '', 'Inno Setup 6', 'ISCC.exe'),
    path.join(process.env.ProgramFiles ?? '', 'Inno Setup 6', 'ISCC.exe'),
    path.join(process.env.LocalAppData ?? '', 'Programs', 'Inno Setup 6', 'ISCC.exe'),
  ].filter(Boolean);

  for (const candidate of candidates) {
    if (candidate === 'iscc' || candidate === 'ISCC.exe') {
      continue;
    }

    if (existsSync(candidate)) {
      return candidate;
    }
  }

  throw new Error(
    [
      'Inno Setup compiler (ISCC.exe) was not found.',
      'Install Inno Setup 6 from https://jrsoftware.org/isinfo.php',
      'or set the ISCC environment variable to the full path of ISCC.exe.',
    ].join('\n'),
  );
}
