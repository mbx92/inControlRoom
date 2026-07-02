namespace InfraControl.Agent.Core.Models;

public sealed class WindowsServiceSnapshot
{
    public string Name { get; set; } = string.Empty;

    public string DisplayName { get; set; } = string.Empty;

    public string Status { get; set; } = string.Empty;

    public string StartMode { get; set; } = string.Empty;
}
