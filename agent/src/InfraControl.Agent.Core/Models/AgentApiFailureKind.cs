namespace InfraControl.Agent.Core.Models;

public enum AgentApiFailureKind
{
    None = 0,
    InvalidEnrollmentToken,
    Unauthorized,
    ServerUnreachable,
    ValidationError,
    UnexpectedResponse,
}
