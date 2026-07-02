using InfraControl.Agent.Core.Models;

namespace InfraControl.Agent.Core.Services;

public sealed class WindowsServiceManager
{
    private readonly string _serviceName;

    public WindowsServiceManager(string serviceName)
    {
        _serviceName = serviceName;
    }

    public ServiceControlSnapshot GetSnapshot()
    {
        using System.ServiceProcess.ServiceController? controller = GetController();

        if (controller is null)
        {
            return new ServiceControlSnapshot
            {
                Installed = false,
                State = AgentServiceState.NotInstalled,
                DisplayText = "Not installed",
            };
        }

        return new ServiceControlSnapshot
        {
            Installed = true,
            State = MapState(controller.Status),
            DisplayText = controller.Status.ToString(),
        };
    }

    public Task StartAsync(CancellationToken cancellationToken = default) =>
        Task.Run(() =>
        {
            using System.ServiceProcess.ServiceController? controller = GetController()
                ?? throw new InvalidOperationException("InfraControl Agent service is not installed.");

            if (controller.Status == System.ServiceProcess.ServiceControllerStatus.Running ||
                controller.Status == System.ServiceProcess.ServiceControllerStatus.StartPending)
            {
                return;
            }

            controller.Start();
            controller.WaitForStatus(System.ServiceProcess.ServiceControllerStatus.Running, TimeSpan.FromSeconds(20));
        }, cancellationToken);

    public Task StopAsync(CancellationToken cancellationToken = default) =>
        Task.Run(() =>
        {
            using System.ServiceProcess.ServiceController? controller = GetController()
                ?? throw new InvalidOperationException("InfraControl Agent service is not installed.");

            if (!controller.CanStop ||
                controller.Status == System.ServiceProcess.ServiceControllerStatus.Stopped ||
                controller.Status == System.ServiceProcess.ServiceControllerStatus.StopPending)
            {
                return;
            }

            controller.Stop();
            controller.WaitForStatus(System.ServiceProcess.ServiceControllerStatus.Stopped, TimeSpan.FromSeconds(20));
        }, cancellationToken);

    private System.ServiceProcess.ServiceController? GetController()
    {
        System.ServiceProcess.ServiceController[] services = System.ServiceProcess.ServiceController.GetServices();
        return services.FirstOrDefault(service => service.ServiceName.Equals(_serviceName, StringComparison.OrdinalIgnoreCase));
    }

    private static AgentServiceState MapState(System.ServiceProcess.ServiceControllerStatus status) =>
        status switch
        {
            System.ServiceProcess.ServiceControllerStatus.Running => AgentServiceState.Running,
            System.ServiceProcess.ServiceControllerStatus.Stopped => AgentServiceState.Stopped,
            System.ServiceProcess.ServiceControllerStatus.StartPending => AgentServiceState.StartPending,
            System.ServiceProcess.ServiceControllerStatus.StopPending => AgentServiceState.StopPending,
            _ => AgentServiceState.Unknown,
        };
}
