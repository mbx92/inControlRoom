using InfraControl.Agent.Core.Constants;
using InfraControl.Agent.Core.Infrastructure;
using InfraControl.Agent.Core.Services;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;

HostApplicationBuilder builder = Host.CreateApplicationBuilder(args);

builder.Services.AddWindowsService(options =>
{
    options.ServiceName = AgentConstants.ServiceName;
});

builder.Logging.AddEventLog(settings =>
{
    settings.SourceName = AgentConstants.ServiceName;
    settings.LogName = "Application";
});

builder.Services.AddSingleton<ISecretProtector, DpapiSecretProtector>();
builder.Services.AddSingleton<IAgentConfigurationStore>(services =>
    new FileAgentConfigurationStore(AgentPaths.ConfigPath, services.GetRequiredService<ISecretProtector>()));
builder.Services.AddSingleton<IAgentStatusStore>(_ => new FileAgentStatusStore(AgentPaths.StatusPath));
builder.Services.AddSingleton<IDeviceIdentityProvider, WindowsDeviceIdentityProvider>();
builder.Services.AddSingleton<IDeviceInventoryCollector, WindowsDeviceInventoryCollector>();
builder.Services.AddSingleton<HeartbeatPayloadFactory>();
builder.Services.AddSingleton<BackoffPolicy>();
builder.Services.AddSingleton<AgentRuntimeOrchestrator>();
builder.Services.AddHttpClient<IAgentApiClient, AgentApiClient>(client =>
{
    client.Timeout = TimeSpan.FromSeconds(AgentConstants.HttpTimeoutSeconds);
    client.DefaultRequestHeaders.UserAgent.ParseAdd($"{AgentConstants.ServiceName}/1.0");
});
builder.Services.AddHostedService<AgentWorker>();

await builder.Build().RunAsync();
