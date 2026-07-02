using InfraControl.Agent.Core.Constants;
using InfraControl.Agent.Core.Models;

namespace InfraControl.Agent.Core.Services;

public sealed class AgentRuntimeOrchestrator
{
    private readonly IAgentConfigurationStore _configurationStore;
    private readonly IAgentStatusStore _statusStore;
    private readonly IDeviceInventoryCollector _inventoryCollector;
    private readonly IAgentApiClient _apiClient;
    private readonly HeartbeatPayloadFactory _payloadFactory;
    private readonly BackoffPolicy _backoffPolicy;
    private int _consecutiveFailures;

    public AgentRuntimeOrchestrator(
        IAgentConfigurationStore configurationStore,
        IAgentStatusStore statusStore,
        IDeviceInventoryCollector inventoryCollector,
        IAgentApiClient apiClient,
        HeartbeatPayloadFactory payloadFactory,
        BackoffPolicy backoffPolicy)
    {
        _configurationStore = configurationStore;
        _statusStore = statusStore;
        _inventoryCollector = inventoryCollector;
        _apiClient = apiClient;
        _payloadFactory = payloadFactory;
        _backoffPolicy = backoffPolicy;
    }

    public async Task<TimeSpan> ExecuteOnceAsync(CancellationToken cancellationToken = default)
    {
        AgentConfiguration configuration = await _configurationStore.LoadAsync(cancellationToken);

        if (!configuration.HasServerUrl || (!configuration.HasAgentToken && !configuration.HasEnrollmentToken))
        {
            await SaveStatusAsync(AgentRuntimeStatusCode.NotConfigured, "Server URL and enrollment token are required before the agent can start.", cancellationToken);

            _consecutiveFailures = 0;
            return TimeSpan.FromSeconds(AgentConstants.DefaultHeartbeatIntervalSeconds);
        }

        DeviceSnapshot device = await _inventoryCollector.CaptureDeviceAsync(cancellationToken);

        if (!configuration.HasAgentToken)
        {
            await SaveStatusAsync(AgentRuntimeStatusCode.Enrolling, "Enrolling the device with InfraControl.", cancellationToken);

            EnrollOperationResult enrollResult = await _apiClient.EnrollAsync(configuration, _payloadFactory.CreateEnrollRequest(configuration, device), cancellationToken);

            if (!enrollResult.Success || enrollResult.Response is null)
            {
                return await HandleEnrollFailureAsync(enrollResult, cancellationToken);
            }

            configuration.AgentToken = enrollResult.Response.AgentToken;
            configuration.EnrollmentToken = null;
            configuration.HeartbeatIntervalSeconds = NormalizeInterval(enrollResult.Response.IntervalSeconds);
            await _configurationStore.SaveAsync(configuration, cancellationToken);
            await SaveStatusAsync(AgentRuntimeStatusCode.Enrolled, "Enrollment completed successfully.", cancellationToken);
            _consecutiveFailures = 0;
        }

        InventorySnapshot inventory = await _inventoryCollector.CaptureInventoryAsync(cancellationToken);
        HeartbeatRequest heartbeat = _payloadFactory.CreateHeartbeatRequest(device, inventory);
        HeartbeatOperationResult heartbeatResult = await _apiClient.SendHeartbeatAsync(configuration, heartbeat, cancellationToken);

        if (!heartbeatResult.Success || heartbeatResult.Response is null)
        {
            return await HandleHeartbeatFailureAsync(heartbeatResult, cancellationToken);
        }

        configuration.HeartbeatIntervalSeconds = NormalizeInterval(heartbeatResult.Response.NextIntervalSeconds);
        await _configurationStore.SaveAsync(configuration, cancellationToken);
        await SaveStatusAsync(AgentRuntimeStatusCode.HeartbeatHealthy, "Heartbeat sent successfully.", cancellationToken);

        _consecutiveFailures = 0;

        return TimeSpan.FromSeconds(configuration.HeartbeatIntervalSeconds);
    }

    private async Task<TimeSpan> HandleEnrollFailureAsync(EnrollOperationResult result, CancellationToken cancellationToken)
    {
        _consecutiveFailures++;

        AgentRuntimeStatusCode statusCode = result.FailureKind switch
        {
            AgentApiFailureKind.InvalidEnrollmentToken => AgentRuntimeStatusCode.InvalidEnrollmentToken,
            AgentApiFailureKind.ServerUnreachable => AgentRuntimeStatusCode.ServerUnreachable,
            _ => AgentRuntimeStatusCode.Error,
        };

        string message = result.Message ?? "Enrollment failed.";
        await SaveStatusAsync(statusCode, message, cancellationToken);

        return _backoffPolicy.GetDelay(_consecutiveFailures);
    }

    private async Task<TimeSpan> HandleHeartbeatFailureAsync(HeartbeatOperationResult result, CancellationToken cancellationToken)
    {
        _consecutiveFailures++;

        AgentRuntimeStatusCode statusCode = result.FailureKind switch
        {
            AgentApiFailureKind.ServerUnreachable => AgentRuntimeStatusCode.ServerUnreachable,
            AgentApiFailureKind.Unauthorized => AgentRuntimeStatusCode.HeartbeatDegraded,
            _ => AgentRuntimeStatusCode.Error,
        };

        string message = result.Message ?? "Heartbeat failed.";
        await SaveStatusAsync(statusCode, message, cancellationToken);

        return _backoffPolicy.GetDelay(_consecutiveFailures);
    }

    private async Task SaveStatusAsync(AgentRuntimeStatusCode statusCode, string message, CancellationToken cancellationToken)
    {
        AgentStatusSnapshot current = await _statusStore.LoadAsync(cancellationToken);

        current.StatusCode = statusCode;
        current.Message = message;

        await _statusStore.SaveAsync(current, cancellationToken);
    }

    private static int NormalizeInterval(int intervalSeconds) =>
        intervalSeconds > 0 ? intervalSeconds : AgentConstants.DefaultHeartbeatIntervalSeconds;
}
