using InfraControl.Agent.Core.Constants;
using InfraControl.Agent.Core.Models;
using InfraControl.Agent.Core.Services;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;

namespace InfraControl.Agent.Service;

public sealed class AgentWorker : BackgroundService
{
    private readonly AgentRuntimeOrchestrator _orchestrator;
    private readonly IAgentStatusStore _statusStore;
    private readonly ILogger<AgentWorker> _logger;

    public AgentWorker(
        AgentRuntimeOrchestrator orchestrator,
        IAgentStatusStore statusStore,
        ILogger<AgentWorker> logger)
    {
        _orchestrator = orchestrator;
        _statusStore = statusStore;
        _logger = logger;
    }

    public override async Task StartAsync(CancellationToken cancellationToken)
    {
        await UpdateServiceStateAsync(AgentServiceState.Running, AgentRuntimeStatusCode.NotConfigured, "Service is starting.", cancellationToken);
        await base.StartAsync(cancellationToken);
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                TimeSpan delay = await _orchestrator.ExecuteOnceAsync(stoppingToken);

                if (delay <= TimeSpan.Zero)
                {
                    delay = TimeSpan.FromSeconds(AgentConstants.DefaultHeartbeatIntervalSeconds);
                }

                await Task.Delay(delay, stoppingToken);
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception exception)
            {
                _logger.LogError(exception, "Unhandled exception in agent worker loop.");
                await UpdateServiceStateAsync(AgentServiceState.Running, AgentRuntimeStatusCode.Error, exception.Message, CancellationToken.None);
                await Task.Delay(TimeSpan.FromSeconds(15), stoppingToken);
            }
        }
    }

    public override async Task StopAsync(CancellationToken cancellationToken)
    {
        await UpdateServiceStateAsync(AgentServiceState.Stopped, AgentRuntimeStatusCode.ServiceStopped, "Service stopped.", CancellationToken.None);
        await base.StopAsync(cancellationToken);
    }

    private async Task UpdateServiceStateAsync(
        AgentServiceState serviceState,
        AgentRuntimeStatusCode fallbackStatusCode,
        string fallbackMessage,
        CancellationToken cancellationToken)
    {
        AgentStatusSnapshot snapshot = await _statusStore.LoadAsync(cancellationToken);

        snapshot.ServiceState = serviceState;

        if (snapshot.StatusCode == AgentRuntimeStatusCode.NotConfigured ||
            snapshot.StatusCode == AgentRuntimeStatusCode.ServiceStopped ||
            snapshot.StatusCode == AgentRuntimeStatusCode.Error)
        {
            snapshot.StatusCode = fallbackStatusCode;
            snapshot.Message = fallbackMessage;
        }

        await _statusStore.SaveAsync(snapshot, cancellationToken);
    }
}
