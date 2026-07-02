using InfraControl.Agent.Core.Models;

namespace InfraControl.Agent.Core.Services;

public interface IAgentApiClient
{
    Task<EnrollOperationResult> EnrollAsync(AgentConfiguration configuration, EnrollRequest request, CancellationToken cancellationToken = default);

    Task<HeartbeatOperationResult> SendHeartbeatAsync(AgentConfiguration configuration, HeartbeatRequest request, CancellationToken cancellationToken = default);
}
