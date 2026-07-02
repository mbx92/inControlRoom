namespace InfraControl.Agent.Core.Models;

public enum AgentServiceState
{
    Unknown = 0,
    Running,
    Stopped,
    StartPending,
    StopPending,
    NotInstalled,
}
