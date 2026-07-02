namespace InfraControl.Agent.Core.Models;

public sealed class AgentStatusSnapshot
{
    public AgentRuntimeStatusCode StatusCode { get; set; } = AgentRuntimeStatusCode.NotConfigured;

    public string Message { get; set; } = "Agent is not configured yet.";

    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;

    public AgentServiceState ServiceState { get; set; } = AgentServiceState.Unknown;
}
