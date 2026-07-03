import path from 'node:path';
import { COMPANY_NAME, PRODUCT_NAME } from './constants.mjs';

const programFiles = process.env.ProgramFiles ?? 'C:\\Program Files';
const programData = process.env.ProgramData ?? 'C:\\ProgramData';

export const installDirectory = path.join(programFiles, PRODUCT_NAME);
export const dataDirectory = path.join(programData, COMPANY_NAME, 'Agent');
export const configPath = path.join(dataDirectory, 'config.json');
export const statusPath = path.join(dataDirectory, 'status.json');
export const logsDirectory = path.join(dataDirectory, 'logs');
