using System.Text.Json.Serialization;

namespace InfraControl.Agent.Core.Models;

public sealed class HeartbeatResponse
{
    public bool Ok { get; set; }

    [JsonPropertyName("next_interval_seconds")]
    public int NextIntervalSeconds { get; set; }

    public IReadOnlyList<object> Commands { get; set; } = Array.Empty<object>();
}
