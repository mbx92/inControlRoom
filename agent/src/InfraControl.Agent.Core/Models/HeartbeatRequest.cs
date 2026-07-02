namespace InfraControl.Agent.Core.Models;

public sealed class HeartbeatRequest
{
    public string AgentVersion { get; set; } = string.Empty;

    public string DeviceId { get; set; } = string.Empty;

    public string Hostname { get; set; } = string.Empty;

    public string Os { get; set; } = string.Empty;

    public string OsVersion { get; set; } = string.Empty;

    public string Arch { get; set; } = string.Empty;

    public string? PrimaryIp { get; set; }

    public DateTimeOffset Timestamp { get; set; }

    public object Metrics { get; set; } = new { };

    public IReadOnlyList<WindowsServiceSnapshot> Services { get; set; } = Array.Empty<WindowsServiceSnapshot>();

    public IReadOnlyList<string> Labels { get; set; } = Array.Empty<string>();
}
