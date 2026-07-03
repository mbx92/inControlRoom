import assert from 'node:assert/strict';
import test from 'node:test';
import { parseCapacityBytes } from '../src/services/windows-hardware-inventory.mjs';
import { parsePowerShellJson } from '../src/services/windows-powershell-json.mjs';
import { mapServiceRow, parseScQueryState } from '../src/services/windows-service-inventory.mjs';

test('parseCapacityBytes handles numeric strings and whitespace', () => {
  assert.equal(parseCapacityBytes('8589934592'), 8589934592);
  assert.equal(parseCapacityBytes(16_000_000_000), 16_000_000_000);
  assert.equal(parseCapacityBytes(''), 0);
});

test('parsePowerShellJson accepts single object output', () => {
  const rows = parsePowerShellJson('{"Name":"EventLog","State":"Running"}');

  assert.equal(rows.length, 1);
  assert.equal(rows[0].Name, 'EventLog');
});

test('parseScQueryState reads running state from sc output', () => {
  const status = parseScQueryState(`
SERVICE_NAME: EventLog
        TYPE               : 20  WIN32_SHARE_PROCESS
        STATE              : 4  RUNNING
  `);

  assert.equal(status, 'Running');
});

test('mapServiceRow normalizes WMI service state', () => {
  const mapped = mapServiceRow({
    Name: 'EventLog',
    DisplayName: 'Windows Event Log',
    State: 'Running',
    StartMode: 'Auto',
  });

  assert.equal(mapped.name, 'EventLog');
  assert.equal(mapped.status, 'Running');
  assert.equal(mapped.startMode, 'Automatic');
});
