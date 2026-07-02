namespace InfraControl.Agent.Core.Services;

public sealed class BackoffPolicy
{
    public TimeSpan GetDelay(int consecutiveFailures)
    {
        if (consecutiveFailures <= 0)
        {
            return TimeSpan.Zero;
        }

        int multiplier = 1 << Math.Min(consecutiveFailures - 1, 5);
        int seconds = Math.Min(120, 5 * multiplier);

        return TimeSpan.FromSeconds(seconds);
    }
}
