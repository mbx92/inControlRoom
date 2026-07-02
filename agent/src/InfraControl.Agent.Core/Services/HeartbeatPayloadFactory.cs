using InfraControl.Agent.Core.Models;

namespace InfraControl.Agent.Core.Services;

public sealed class HeartbeatPayloadFactory
{
    public EnrollRequest CreateEnrollRequest(AgentConfiguration configuration, DeviceSnapshot device)
    {
        return new EnrollRequest
        {
            EnrollToken = configuration.EnrollmentToken ?? string.Empty,
            DeviceId = device.DeviceId,
            Hostname = device.Hostname,
            Os = device.Os,
            OsVersion = device.OsVersion,
            Arch = device.Arch,
            AgentVersion = device.AgentVersion,
            PrimaryIp = device.PrimaryIp,
        };
    }

    public HeartbeatRequest CreateHeartbeatRequest(DeviceSnapshot device, InventorySnapshot inventory)
    {
        return new HeartbeatRequest
        {
            AgentVersion = device.AgentVersion,
            DeviceId = device.DeviceId,
            Hostname = device.Hostname,
            Os = device.Os,
            OsVersion = device.OsVersion,
            Arch = device.Arch,
            PrimaryIp = device.PrimaryIp,
            Timestamp = DateTimeOffset.UtcNow,
            Metrics = new
            {
                cpu = new
                {
                    usage_percent = inventory.Metrics.CpuUsagePercent,
                },
                memory = new
                {
                    total_bytes = inventory.Metrics.TotalMemoryBytes,
                    free_bytes = inventory.Metrics.FreeMemoryBytes,
                },
                disks = inventory.Metrics.Disks.Select(disk => new
                {
                    name = disk.Name,
                    total_bytes = disk.TotalBytes,
                    free_bytes = disk.FreeBytes,
                }).ToArray(),
            },
            Services = inventory.Services,
            Labels = inventory.Labels,
        };
    }
}
