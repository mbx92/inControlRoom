using System.Management;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Runtime.InteropServices;
using InfraControl.Agent.Core.Models;

namespace InfraControl.Agent.Core.Services;

public sealed class WindowsDeviceInventoryCollector : IDeviceInventoryCollector
{
    private static readonly string[] ImportantServiceNames =
    [
        "Dhcp",
        "Dnscache",
        "EventLog",
        "LanmanServer",
        "Spooler",
        "TermService",
        "W32Time",
        "Winmgmt",
    ];

    private readonly IDeviceIdentityProvider _deviceIdentityProvider;

    public WindowsDeviceInventoryCollector(IDeviceIdentityProvider deviceIdentityProvider)
    {
        _deviceIdentityProvider = deviceIdentityProvider;
    }

    public Task<DeviceSnapshot> CaptureDeviceAsync(CancellationToken cancellationToken = default)
    {
        DeviceSnapshot snapshot = new()
        {
            DeviceId = _deviceIdentityProvider.GetDeviceId(),
            Hostname = Environment.MachineName,
            Os = "Windows",
            OsVersion = Environment.OSVersion.VersionString,
            Arch = RuntimeInformation.OSArchitecture.ToString().ToLowerInvariant(),
            PrimaryIp = GetPrimaryIpAddress(),
            AgentVersion = GetAgentVersion(),
        };

        return Task.FromResult(snapshot);
    }

    public Task<InventorySnapshot> CaptureInventoryAsync(CancellationToken cancellationToken = default)
    {
        InventorySnapshot snapshot = new()
        {
            Metrics = CaptureMetrics(),
            Services = CaptureServices(),
            Labels = Array.Empty<string>(),
        };

        return Task.FromResult(snapshot);
    }

    private static string GetAgentVersion()
    {
        Version? version = typeof(WindowsDeviceInventoryCollector).Assembly.GetName().Version;

        return version is null ? "1.0.0" : $"{version.Major}.{version.Minor}.{version.Build}";
    }

    private static string? GetPrimaryIpAddress()
    {
        foreach (NetworkInterface networkInterface in NetworkInterface.GetAllNetworkInterfaces())
        {
            if (networkInterface.OperationalStatus != OperationalStatus.Up ||
                networkInterface.NetworkInterfaceType == NetworkInterfaceType.Loopback)
            {
                continue;
            }

            IPInterfaceProperties properties = networkInterface.GetIPProperties();
            foreach (UnicastIPAddressInformation address in properties.UnicastAddresses)
            {
                if (address.Address.AddressFamily == AddressFamily.InterNetwork)
                {
                    return address.Address.ToString();
                }
            }
        }

        return null;
    }

    private static MetricsSnapshot CaptureMetrics()
    {
        (long totalMemoryBytes, long freeMemoryBytes) = GetMemoryStatus();

        return new MetricsSnapshot
        {
            CpuUsagePercent = QueryCpuUsagePercent(),
            TotalMemoryBytes = totalMemoryBytes,
            FreeMemoryBytes = freeMemoryBytes,
            Disks = DriveInfo.GetDrives()
                .Where(drive => drive.IsReady)
                .Select(drive => new DiskMetricSnapshot
                {
                    Name = drive.Name,
                    TotalBytes = drive.TotalSize,
                    FreeBytes = drive.AvailableFreeSpace,
                })
                .ToArray(),
        };
    }

    private static double QueryCpuUsagePercent()
    {
        try
        {
            using ManagementObjectSearcher searcher = new("SELECT LoadPercentage FROM Win32_Processor");
            List<double> values = [];

            foreach (ManagementBaseObject instance in searcher.Get())
            {
                if (double.TryParse(instance["LoadPercentage"]?.ToString(), out double value))
                {
                    values.Add(value);
                }
            }

            if (values.Count > 0)
            {
                return Math.Round(values.Average(), 2);
            }
        }
        catch
        {
            // Ignore transient WMI failures and fall back to zero.
        }

        return 0;
    }

    private static IReadOnlyList<WindowsServiceSnapshot> CaptureServices()
    {
        Dictionary<string, string> startModes = QueryServiceStartModes();

        return System.ServiceProcess.ServiceController.GetServices()
            .Where(service => ImportantServiceNames.Contains(service.ServiceName, StringComparer.OrdinalIgnoreCase))
            .OrderBy(service => service.DisplayName, StringComparer.OrdinalIgnoreCase)
            .Select(service => new WindowsServiceSnapshot
            {
                Name = service.ServiceName,
                DisplayName = service.DisplayName,
                Status = service.Status.ToString(),
                StartMode = startModes.TryGetValue(service.ServiceName, out string? mode) ? mode : "Unknown",
            })
            .ToArray();
    }

    private static Dictionary<string, string> QueryServiceStartModes()
    {
        Dictionary<string, string> startModes = new(StringComparer.OrdinalIgnoreCase);

        try
        {
            using ManagementObjectSearcher searcher = new("SELECT Name, StartMode FROM Win32_Service");

            foreach (ManagementBaseObject instance in searcher.Get())
            {
                string? name = instance["Name"]?.ToString();
                string? startMode = instance["StartMode"]?.ToString();

                if (!string.IsNullOrWhiteSpace(name))
                {
                    startModes[name] = startMode ?? "Unknown";
                }
            }
        }
        catch
        {
            return startModes;
        }

        return startModes;
    }

    private static (long TotalMemoryBytes, long FreeMemoryBytes) GetMemoryStatus()
    {
        MEMORYSTATUSEX memoryStatus = new();
        memoryStatus.dwLength = (uint)Marshal.SizeOf(memoryStatus);

        if (!GlobalMemoryStatusEx(ref memoryStatus))
        {
            return (0, 0);
        }

        return ((long)memoryStatus.ullTotalPhys, (long)memoryStatus.ullAvailPhys);
    }

    [DllImport("kernel32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GlobalMemoryStatusEx(ref MEMORYSTATUSEX lpBuffer);

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Auto)]
    private struct MEMORYSTATUSEX
    {
        public uint dwLength;
        public uint dwMemoryLoad;
        public ulong ullTotalPhys;
        public ulong ullAvailPhys;
        public ulong ullTotalPageFile;
        public ulong ullAvailPageFile;
        public ulong ullTotalVirtual;
        public ulong ullAvailVirtual;
        public ulong ullAvailExtendedVirtual;
    }
}
