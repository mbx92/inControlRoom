namespace InfraControl.Agent.Core.Models;

public sealed class InventorySnapshot
{
    public MetricsSnapshot Metrics { get; set; } = new();

    public IReadOnlyList<WindowsServiceSnapshot> Services { get; set; } = Array.Empty<WindowsServiceSnapshot>();

    public IReadOnlyList<string> Labels { get; set; } = Array.Empty<string>();
}
