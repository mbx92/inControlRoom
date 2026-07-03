import { createAgentRuntime, runAgentWorker } from './worker.mjs';

let stopping = false;

process.on('SIGINT', () => {
  stopping = true;
});

process.on('SIGTERM', () => {
  stopping = true;
});

const { orchestrator, statusStore } = createAgentRuntime();

await runAgentWorker({
  orchestrator,
  statusStore,
  shouldStop: () => stopping,
});
