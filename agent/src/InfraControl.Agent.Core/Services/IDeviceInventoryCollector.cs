using InfraControl.Agent.Core.Models;

namespace InfraControl.Agent.Core.Services;

public interface IDeviceInventoryCollector
{
    Task<DeviceSnapshot> CaptureDeviceAsync(CancellationToken cancellationToken = default);

    Task<InventorySnapshot> CaptureInventoryAsync(CancellationToken cancellationToken = default);
}
