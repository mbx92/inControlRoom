using InfraControl.Agent.Core.Constants;

namespace InfraControl.Agent.Core.Models;

public sealed class AgentConfiguration
{
    public string? ServerUrl { get; set; }

    public string? EnrollmentToken { get; set; }

    public string? AgentToken { get; set; }

    public int HeartbeatIntervalSeconds { get; set; } = AgentConstants.DefaultHeartbeatIntervalSeconds;

    public bool HasAgentToken => !string.IsNullOrWhiteSpace(AgentToken);

    public bool HasEnrollmentToken => !string.IsNullOrWhiteSpace(EnrollmentToken);

    public bool HasServerUrl => !string.IsNullOrWhiteSpace(ServerUrl);
}
