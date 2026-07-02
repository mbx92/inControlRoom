namespace InfraControl.Agent.Core.Models;

public sealed class EnrollResponse
{
    public string AgentId { get; set; } = string.Empty;

    public string AgentToken { get; set; } = string.Empty;

    public string SiteId { get; set; } = string.Empty;

    public int IntervalSeconds { get; set; }
}
