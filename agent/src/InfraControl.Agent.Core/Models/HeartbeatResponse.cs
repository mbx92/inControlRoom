namespace InfraControl.Agent.Core.Models;

public sealed class HeartbeatResponse
{
    public bool Ok { get; set; }

    public int NextIntervalSeconds { get; set; }

    public IReadOnlyList<object> Commands { get; set; } = Array.Empty<object>();
}
