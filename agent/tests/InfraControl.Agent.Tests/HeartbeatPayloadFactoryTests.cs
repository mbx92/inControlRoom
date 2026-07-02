using System.Text.Json;
using InfraControl.Agent.Core.Models;
using InfraControl.Agent.Core.Services;

namespace InfraControl.Agent.Tests;

public sealed class HeartbeatPayloadFactoryTests
{
    [Fact]
    public void creates_expected_heartbeat_payload_shape()
    {
        HeartbeatPayloadFactory factory = new();
        DeviceSnapshot device = new()
        {
            DeviceId = "device-1",
            Hostname = "CLIENT-01",
            Os = "Windows",
            OsVersion = "11 Pro",
            Arch = "x64",
            PrimaryIp = "10.20.30.40",
            AgentVersion = "1.0.0",
        };
        InventorySnapshot inventory = new()
        {
            Metrics = new MetricsSnapshot
            {
                CpuUsagePercent = 18.5,
                TotalMemoryBytes = 16000,
                FreeMemoryBytes = 8000,
                Disks =
                [
                    new DiskMetricSnapshot
                    {
                        Name = "C:\\",
                        TotalBytes = 1000,
                        FreeBytes = 250,
                    },
                ],
            },
            Services =
            [
                new WindowsServiceSnapshot
                {
                    Name = "Spooler",
                    DisplayName = "Print Spooler",
                    Status = "Running",
                    StartMode = "Automatic",
                },
            ],
            Labels = ["branch-a"],
        };

        HeartbeatRequest request = factory.CreateHeartbeatRequest(device, inventory);

        Assert.Equal("device-1", request.DeviceId);
        Assert.Equal("CLIENT-01", request.Hostname);
        Assert.Equal("Windows", request.Os);
        Assert.Equal("11 Pro", request.OsVersion);
        Assert.Equal("x64", request.Arch);
        Assert.Equal("10.20.30.40", request.PrimaryIp);
        Assert.Equal("branch-a", Assert.Single(request.Labels));
        Assert.Single(request.Services);

        JsonElement metrics = JsonSerializer.SerializeToElement(request.Metrics);
        Assert.Equal(18.5, metrics.GetProperty("cpu").GetProperty("usage_percent").GetDouble());
        Assert.Equal(16000, metrics.GetProperty("memory").GetProperty("total_bytes").GetInt64());
        Assert.Equal(250, metrics.GetProperty("disks")[0].GetProperty("free_bytes").GetInt64());
    }
}
