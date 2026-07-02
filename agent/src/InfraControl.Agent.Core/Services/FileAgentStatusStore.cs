using System.Text.Json;
using InfraControl.Agent.Core.Models;

namespace InfraControl.Agent.Core.Services;

public sealed class FileAgentStatusStore : IAgentStatusStore
{
    private readonly string _path;
    private readonly JsonSerializerOptions _jsonOptions = new(JsonSerializerDefaults.Web)
    {
        WriteIndented = true,
    };

    public FileAgentStatusStore(string path)
    {
        _path = path;
    }

    public async Task<AgentStatusSnapshot> LoadAsync(CancellationToken cancellationToken = default)
    {
        if (!File.Exists(_path))
        {
            return new AgentStatusSnapshot();
        }

        await using FileStream stream = File.OpenRead(_path);
        AgentStatusSnapshot? status = await JsonSerializer.DeserializeAsync<AgentStatusSnapshot>(stream, _jsonOptions, cancellationToken);

        return status ?? new AgentStatusSnapshot();
    }

    public async Task SaveAsync(AgentStatusSnapshot status, CancellationToken cancellationToken = default)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(_path)!);
        status.UpdatedAt = DateTimeOffset.UtcNow;

        await using FileStream stream = File.Create(_path);
        await JsonSerializer.SerializeAsync(stream, status, _jsonOptions, cancellationToken);
    }
}
