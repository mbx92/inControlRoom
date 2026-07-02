namespace InfraControl.Agent.Core.Models;

public sealed class HeartbeatOperationResult
{
    public bool Success { get; private init; }

    public HeartbeatResponse? Response { get; private init; }

    public AgentApiFailureKind FailureKind { get; private init; }

    public string? Message { get; private init; }

    public static HeartbeatOperationResult FromSuccess(HeartbeatResponse response) => new()
    {
        Success = true,
        Response = response,
        FailureKind = AgentApiFailureKind.None,
    };

    public static HeartbeatOperationResult FromFailure(AgentApiFailureKind failureKind, string? message = null) => new()
    {
        Success = false,
        FailureKind = failureKind,
        Message = message,
    };
}
