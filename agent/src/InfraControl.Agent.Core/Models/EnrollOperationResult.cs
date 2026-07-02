namespace InfraControl.Agent.Core.Models;

public sealed class EnrollOperationResult
{
    public bool Success { get; private init; }

    public EnrollResponse? Response { get; private init; }

    public AgentApiFailureKind FailureKind { get; private init; }

    public string? Message { get; private init; }

    public static EnrollOperationResult FromSuccess(EnrollResponse response) => new()
    {
        Success = true,
        Response = response,
        FailureKind = AgentApiFailureKind.None,
    };

    public static EnrollOperationResult FromFailure(AgentApiFailureKind failureKind, string? message = null) => new()
    {
        Success = false,
        FailureKind = failureKind,
        Message = message,
    };
}
