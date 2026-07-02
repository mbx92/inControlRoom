namespace InfraControl.Agent.Core.Models;

public sealed class MetricsSnapshot
{
    public double CpuUsagePercent { get; set; }

    public long TotalMemoryBytes { get; set; }

    public long FreeMemoryBytes { get; set; }

    public IReadOnlyList<DiskMetricSnapshot> Disks { get; set; } = Array.Empty<DiskMetricSnapshot>();
}
