using InfraControl.Agent.Core.Models;

namespace InfraControl.Agent.Core.Services;

public interface IAgentConfigurationStore
{
    Task<AgentConfiguration> LoadAsync(CancellationToken cancellationToken = default);

    Task SaveAsync(AgentConfiguration configuration, CancellationToken cancellationToken = default);
}
