import { startConfigServer } from './server.mjs';

const { server, url } = await startConfigServer();

console.log(`InfraControl Agent Config running at ${url}`);
console.log('Press Ctrl+C to close.');

process.on('SIGINT', () => {
  server.close(() => process.exit(0));
});

process.on('SIGTERM', () => {
  server.close(() => process.exit(0));
});
