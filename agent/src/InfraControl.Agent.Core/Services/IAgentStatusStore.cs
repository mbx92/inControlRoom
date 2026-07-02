using InfraControl.Agent.Core.Models;

namespace InfraControl.Agent.Core.Services;

public interface IAgentStatusStore
{
    Task<AgentStatusSnapshot> LoadAsync(CancellationToken cancellationToken = default);

    Task SaveAsync(AgentStatusSnapshot status, CancellationToken cancellationToken = default);
}
