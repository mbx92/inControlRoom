export const COMPANY_NAME = 'InfraControl';
export const PRODUCT_NAME = 'InfraControl Agent';
export const SERVICE_NAME = 'InfraControlAgentService';
export const SERVICE_DISPLAY_NAME = 'InfraControl Agent Service';
export const DEFAULT_HEARTBEAT_INTERVAL_SECONDS = 30;
export const HTTP_TIMEOUT_MS = 15_000;

export const AgentRuntimeStatusCode = {
  NotConfigured: 0,
  Enrolling: 1,
  Enrolled: 2,
  InvalidEnrollmentToken: 3,
  ServerUnreachable: 4,
  HeartbeatHealthy: 5,
  HeartbeatDegraded: 6,
  ServiceStopped: 7,
  Error: 8,
};

export const AgentServiceState = {
  Unknown: 0,
  Running: 1,
  Stopped: 2,
  StartPending: 3,
  StopPending: 4,
  NotInstalled: 5,
};

export const AgentApiFailureKind = {
  InvalidEnrollmentToken: 'invalid_enrollment_token',
  ServerUnreachable: 'server_unreachable',
  Unauthorized: 'unauthorized',
  ValidationError: 'validation_error',
  UnexpectedResponse: 'unexpected_response',
};
