using InfraControl.Agent.Core.Constants;

namespace InfraControl.Agent.Core.Infrastructure;

public static class AgentPaths
{
    public static string InstallDirectory =>
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), AgentConstants.ProductName);

    public static string DataDirectory =>
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), AgentConstants.CompanyName, "Agent");

    public static string ConfigPath => Path.Combine(DataDirectory, "config.json");

    public static string StatusPath => Path.Combine(DataDirectory, "status.json");

    public static string LogsDirectory => Path.Combine(DataDirectory, "logs");
}
