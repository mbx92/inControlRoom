using System.Management;
using System.Security.Cryptography;
using System.Text;
using Microsoft.Win32;

namespace InfraControl.Agent.Core.Services;

public sealed class WindowsDeviceIdentityProvider : IDeviceIdentityProvider
{
    public string GetDeviceId()
    {
        string? machineGuid = ReadMachineGuid();

        if (!string.IsNullOrWhiteSpace(machineGuid))
        {
            return machineGuid;
        }

        string fingerprintSource = string.Join("|", new[]
        {
            Environment.MachineName,
            QuerySingleValue("Win32_ComputerSystemProduct", "UUID"),
            QuerySingleValue("Win32_BIOS", "SerialNumber"),
        }.Where(value => !string.IsNullOrWhiteSpace(value)));

        if (string.IsNullOrWhiteSpace(fingerprintSource))
        {
            return Environment.MachineName;
        }

        byte[] hash = SHA256.HashData(Encoding.UTF8.GetBytes(fingerprintSource));

        return Convert.ToHexString(hash).ToLowerInvariant();
    }

    private static string? ReadMachineGuid()
    {
        using RegistryKey? key = Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Microsoft\Cryptography");

        return key?.GetValue("MachineGuid")?.ToString();
    }

    private static string? QuerySingleValue(string className, string propertyName)
    {
        try
        {
            using ManagementObjectSearcher searcher = new($"SELECT {propertyName} FROM {className}");

            foreach (ManagementBaseObject instance in searcher.Get())
            {
                string? value = instance[propertyName]?.ToString();

                if (!string.IsNullOrWhiteSpace(value))
                {
                    return value.Trim();
                }
            }
        }
        catch
        {
            return null;
        }

        return null;
    }
}
