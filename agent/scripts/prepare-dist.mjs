import { createWriteStream } from 'node:fs';
import { access, cp, mkdir, realpath, rm, writeFile } from 'node:fs/promises';
import { get } from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const agentRoot = fileURLToPath(new URL('..', import.meta.url));
const distDirectory = path.join(agentRoot, 'dist');
const nodeVersion = process.env.NODE_VERSION ?? '20.19.2';
const nodeArchiveName = `node-v${nodeVersion}-win-x64`;
const nodeDownloadUrl = `https://nodejs.org/dist/v${nodeVersion}/${nodeArchiveName}.zip`;
const winswDownloadUrl =
  process.env.WINSW_DOWNLOAD_URL ??
  'https://github.com/winsw/winsw/releases/download/v2.12.0/WinSW-x64.exe';

await rm(distDirectory, { recursive: true, force: true });
await mkdir(distDirectory, { recursive: true });

const nodeZipPath = path.join(distDirectory, `${nodeArchiveName}.zip`);
await downloadFile(nodeDownloadUrl, nodeZipPath);
await extractZip(nodeZipPath, distDirectory);

const extractedNodeDirectory = path.join(distDirectory, nodeArchiveName);
await cp(path.join(extractedNodeDirectory, 'node.exe'), path.join(distDirectory, 'node.exe'));
await rm(extractedNodeDirectory, { recursive: true, force: true });
await rm(nodeZipPath, { force: true });

const winswPath = path.join(distDirectory, 'InfraControlAgentService.exe');
await downloadFile(winswDownloadUrl, winswPath);
await cp(
  path.join(agentRoot, 'installer', 'InfraControlAgentService.xml'),
  path.join(distDirectory, 'InfraControlAgentService.xml'),
);

await cp(path.join(agentRoot, 'package.json'), path.join(distDirectory, 'package.json'));
await cp(path.join(agentRoot, 'src'), path.join(distDirectory, 'src'), { recursive: true });
await cp(path.join(agentRoot, 'assets'), path.join(distDirectory, 'assets'), { recursive: true });
await copyProductionDependencies();

await writeFile(
  path.join(distDirectory, 'InfraControl.Agent.Service.cmd'),
  '@echo off\r\n"%~dp0node.exe" "%~dp0src\\service\\index.mjs"\r\n',
  'utf8',
);

await writeFile(
  path.join(distDirectory, 'InfraControl.Agent.Config.cmd'),
  '@echo off\r\ncd /d "%~dp0"\r\nstart "" "%~dp0node.exe" "%~dp0src\\config\\index.mjs"\r\n',
  'utf8',
);

console.log(`Release bundle prepared in ${distDirectory}`);

async function downloadFile(url, destination) {
  await new Promise((resolve, reject) => {
    get(url, (response) => {
      if (response.statusCode && response.statusCode >= 300 && response.statusCode < 400 && response.headers.location) {
        downloadFile(response.headers.location, destination).then(resolve).catch(reject);
        return;
      }

      if (response.statusCode !== 200) {
        reject(new Error(`Failed to download ${url}: HTTP ${response.statusCode}`));
        return;
      }

      const fileStream = createWriteStream(destination);
      response.pipe(fileStream);
      fileStream.on('finish', resolve);
      fileStream.on('error', reject);
    }).on('error', reject);
  });
}

async function extractZip(zipPath, destination) {
  if (process.platform === 'win32') {
    execFileSync(
      'powershell.exe',
      [
        '-NoProfile',
        '-NonInteractive',
        '-Command',
        `Expand-Archive -LiteralPath '${zipPath.replace(/'/g, "''")}' -DestinationPath '${destination.replace(/'/g, "''")}' -Force`,
      ],
      { stdio: 'inherit' },
    );
    return;
  }

  throw new Error('ZIP extraction is only implemented for Windows build hosts.');
}

async function copyProductionDependencies() {
  const dependencyName = 'systeminformation';
  const sourceModule = path.join(agentRoot, 'node_modules', dependencyName);
  const targetModule = path.join(distDirectory, 'node_modules', dependencyName);

  try {
    await access(sourceModule);
  } catch {
    throw new Error(
      `Missing ${dependencyName}. Install agent dependencies first with ".\\install.cmd" or "corepack pnpm install".`,
    );
  }

  await mkdir(path.join(distDirectory, 'node_modules'), { recursive: true });
  const resolvedSource = await realpath(sourceModule);
  await cp(resolvedSource, targetModule, { recursive: true, verbatimSymlinks: false });
}
