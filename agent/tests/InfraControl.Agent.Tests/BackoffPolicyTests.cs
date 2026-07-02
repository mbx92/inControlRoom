using InfraControl.Agent.Core.Services;

namespace InfraControl.Agent.Tests;

public sealed class BackoffPolicyTests
{
    [Theory]
    [InlineData(0, 0)]
    [InlineData(1, 5)]
    [InlineData(2, 10)]
    [InlineData(3, 20)]
    [InlineData(6, 120)]
    public void returns_expected_delay_curve(int failureCount, int expectedSeconds)
    {
        BackoffPolicy policy = new();

        TimeSpan delay = policy.GetDelay(failureCount);

        Assert.Equal(TimeSpan.FromSeconds(expectedSeconds), delay);
    }
}
