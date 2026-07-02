namespace InfraControl.Agent.Core.Models;

public sealed class DeviceSnapshot
{
    public string DeviceId { get; set; } = string.Empty;

    public string Hostname { get; set; } = string.Empty;

    public string Os { get; set; } = string.Empty;

    public string OsVersion { get; set; } = string.Empty;

    public string Arch { get; set; } = string.Empty;

    public string? PrimaryIp { get; set; }

    public string AgentVersion { get; set; } = string.Empty;
}
