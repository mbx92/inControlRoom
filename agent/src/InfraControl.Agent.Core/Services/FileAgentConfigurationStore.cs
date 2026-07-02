using System.Text.Json;
using InfraControl.Agent.Core.Constants;
using InfraControl.Agent.Core.Models;

namespace InfraControl.Agent.Core.Services;

public sealed class FileAgentConfigurationStore : IAgentConfigurationStore
{
    private readonly string _path;
    private readonly ISecretProtector _secretProtector;
    private readonly JsonSerializerOptions _jsonOptions = new(JsonSerializerDefaults.Web)
    {
        WriteIndented = true,
    };

    public FileAgentConfigurationStore(string path, ISecretProtector secretProtector)
    {
        _path = path;
        _secretProtector = secretProtector;
    }

    public async Task<AgentConfiguration> LoadAsync(CancellationToken cancellationToken = default)
    {
        if (!File.Exists(_path))
        {
            return new AgentConfiguration();
        }

        await using FileStream stream = File.OpenRead(_path);
        PersistedAgentConfiguration? persisted = await JsonSerializer.DeserializeAsync<PersistedAgentConfiguration>(stream, _jsonOptions, cancellationToken);

        if (persisted is null)
        {
            return new AgentConfiguration();
        }

        return new AgentConfiguration
        {
            ServerUrl = persisted.ServerUrl,
            EnrollmentToken = persisted.EnrollmentToken,
            AgentToken = string.IsNullOrWhiteSpace(persisted.AgentToken)
                ? null
                : _secretProtector.Unprotect(persisted.AgentToken),
            HeartbeatIntervalSeconds = persisted.HeartbeatIntervalSeconds > 0
                ? persisted.HeartbeatIntervalSeconds
                : AgentConstants.DefaultHeartbeatIntervalSeconds,
        };
    }

    public async Task SaveAsync(AgentConfiguration configuration, CancellationToken cancellationToken = default)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(_path)!);

        PersistedAgentConfiguration persisted = new()
        {
            ServerUrl = Normalize(configuration.ServerUrl),
            EnrollmentToken = Normalize(configuration.EnrollmentToken),
            AgentToken = string.IsNullOrWhiteSpace(configuration.AgentToken)
                ? null
                : _secretProtector.Protect(configuration.AgentToken),
            HeartbeatIntervalSeconds = configuration.HeartbeatIntervalSeconds > 0
                ? configuration.HeartbeatIntervalSeconds
                : AgentConstants.DefaultHeartbeatIntervalSeconds,
        };

        await using FileStream stream = File.Create(_path);
        await JsonSerializer.SerializeAsync(stream, persisted, _jsonOptions, cancellationToken);
    }

    private static string? Normalize(string? value) =>
        string.IsNullOrWhiteSpace(value) ? null : value.Trim();

    private sealed class PersistedAgentConfiguration
    {
        public string? ServerUrl { get; set; }

        public string? EnrollmentToken { get; set; }

        public string? AgentToken { get; set; }

        public int HeartbeatIntervalSeconds { get; set; } = AgentConstants.DefaultHeartbeatIntervalSeconds;
    }
}
