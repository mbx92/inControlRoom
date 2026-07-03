# InfraControl Windows Agent

`agent/` contains the Windows-only v1 agent implementation for InfraControl, built with Node.js.

## Projects

- `src/services/`: shared configuration, inventory collection, API client, and runtime orchestration.
- `src/service/`: Windows Service host for enroll + heartbeat.
- `src/config/`: local web UI for operators to configure the agent and control the service.
- `tests/`: unit tests for core behavior.
- `installer/`: Inno Setup script that packages the release bundle into a Windows installer.

## Prerequisites

- Node.js 20+
- Windows 10/11 or Windows Server 2019+
- [Inno Setup 6](https://jrsoftware.org/isinfo.php) for building the installer

## Development

From the `agent/` directory:

```powershell
# Install dependencies (recommended on Windows)
.\install.cmd
# or: corepack pnpm install

# Run tests (works without pnpm on PATH)
npm test

# Run locally
npm run start:service
npm run start:config
```

> **Note:** If `npm install` fails with `Class extends value undefined`, your Node and npm installs are mismatched (common when nvm and a system Node install coexist). Use `corepack pnpm install` or `.\install.cmd` instead.

If `pnpm` is not recognized, run it through Corepack:

```powershell
corepack pnpm install
corepack pnpm test
```

To expose `pnpm` on PATH permanently:

```powershell
corepack enable
corepack prepare pnpm@9.15.4 --activate
```

Then restart PowerShell.

## Build Notes

Prepare the release bundle (portable Node runtime + app files):

```powershell
cd agent
corepack pnpm install
npm run build
```

Build the Windows installer (requires Inno Setup `iscc` on PATH):

```powershell
cd agent
npm run build:installer
```

The installer is written to `agent/dist/installer/InfraControl.Agent.Setup.exe`.

## Runtime Files

- Live config: `%ProgramData%\InfraControl\Agent\config.json`
- Runtime status: `%ProgramData%\InfraControl\Agent\status.json`
- Start Menu shortcut: `InfraControl Agent Config`

## Troubleshooting

### `npm install` fails with `Class extends value undefined`

This usually means multiple Node installs are fighting on PATH (nvm + system Node + Laragon).

Check which Node is active:

```powershell
node --version
where.exe node
where.exe npm
```

On this machine, the nvm slot `22.19.0` contains **Node v24** binaries (corrupted install). Running `nvm use 22.19.0` still leaves npm broken.

**Fix option A — use pnpm (recommended for this project):**

```powershell
cd agent
.\install.cmd
npm test
```

**Fix option B — repair the nvm slot:**

```powershell
nvm uninstall 22.19.0
nvm install 22.19.0
nvm use 22.19.0
```

Close and reopen PowerShell, then:

```powershell
node --version   # should show v22.19.0
npm install
```

**Fix option C — use a known-good nvm version:**

```powershell
nvm use 22.11.0
```

Open a **new** PowerShell window (nvm may require admin to swap `C:\Program Files\nodejs`), verify `node --version` shows `v22.11.0`, then run `npm install`.

