namespace InfraControl.Agent.Core.Models;

public sealed class DiskMetricSnapshot
{
    public string Name { get; set; } = string.Empty;

    public long TotalBytes { get; set; }

    public long FreeBytes { get; set; }
}
