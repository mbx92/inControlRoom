using System.Net;
using System.Net.Http.Headers;
using System.Text;
using InfraControl.Agent.Core.Models;
using InfraControl.Agent.Core.Services;

namespace InfraControl.Agent.Tests;

public sealed class AgentApiClientTests
{
    [Fact]
    public async Task enroll_maps_invalid_token_response()
    {
        RecordingHandler handler = new((request, cancellationToken) =>
        {
            HttpResponseMessage response = new(HttpStatusCode.UnprocessableEntity)
            {
                Content = new StringContent("{\"message\":\"bad token\"}", Encoding.UTF8, "application/json"),
            };

            return Task.FromResult(response);
        });

        AgentApiClient client = new(new HttpClient(handler));

        EnrollOperationResult result = await client.EnrollAsync(
            new AgentConfiguration { ServerUrl = "https://example.test" },
            new EnrollRequest { EnrollToken = "bad-token", DeviceId = "device-1", Hostname = "CLIENT-01" });

        Assert.False(result.Success);
        Assert.Equal(AgentApiFailureKind.InvalidEnrollmentToken, result.FailureKind);
        Assert.Equal("bad token", result.Message);
        Assert.Equal("https://example.test/api/agents/enroll", handler.LastRequest?.RequestUri?.ToString());
    }

    [Fact]
    public async Task heartbeat_sends_bearer_token_and_parses_interval()
    {
        RecordingHandler handler = new((request, cancellationToken) =>
        {
            HttpResponseMessage response = new(HttpStatusCode.OK)
            {
                Content = new StringContent("{\"ok\":true,\"next_interval_seconds\":45,\"commands\":[]}", Encoding.UTF8, "application/json"),
            };

            return Task.FromResult(response);
        });

        AgentApiClient client = new(new HttpClient(handler));

        HeartbeatOperationResult result = await client.SendHeartbeatAsync(
            new AgentConfiguration
            {
                ServerUrl = "https://example.test/",
                AgentToken = "agent-token-123",
            },
            new HeartbeatRequest
            {
                AgentVersion = "1.0.0",
                DeviceId = "device-1",
                Hostname = "CLIENT-01",
                Os = "Windows",
                OsVersion = "11",
                Arch = "x64",
                Timestamp = DateTimeOffset.UtcNow,
            });

        Assert.True(result.Success);
        Assert.NotNull(result.Response);
        Assert.Equal(45, result.Response!.NextIntervalSeconds);
        Assert.Equal("Bearer", handler.LastRequest?.Headers.Authorization?.Scheme);
        Assert.Equal("agent-token-123", handler.LastRequest?.Headers.Authorization?.Parameter);
        Assert.Equal("https://example.test/api/agents/heartbeat", handler.LastRequest?.RequestUri?.ToString());
    }

    private sealed class RecordingHandler : HttpMessageHandler
    {
        private readonly Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> _handler;

        public RecordingHandler(Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> handler)
        {
            _handler = handler;
        }

        public HttpRequestMessage? LastRequest { get; private set; }

        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            LastRequest = request;
            return _handler(request, cancellationToken);
        }
    }
}
