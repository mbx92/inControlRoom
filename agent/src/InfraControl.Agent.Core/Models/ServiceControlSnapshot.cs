namespace InfraControl.Agent.Core.Models;

public sealed class ServiceControlSnapshot
{
    public bool Installed { get; set; }

    public AgentServiceState State { get; set; } = AgentServiceState.Unknown;

    public string DisplayText { get; set; } = "Unknown";
}
