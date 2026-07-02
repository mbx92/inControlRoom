namespace InfraControl.Agent.Core.Models;

public enum AgentRuntimeStatusCode
{
    NotConfigured = 0,
    Enrolling,
    Enrolled,
    InvalidEnrollmentToken,
    ServerUnreachable,
    HeartbeatHealthy,
    HeartbeatDegraded,
    ServiceStopped,
    Error,
}
