using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using InfraControl.Agent.Core.Models;

namespace InfraControl.Agent.Core.Services;

public sealed class AgentApiClient : IAgentApiClient
{
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);
    private readonly HttpClient _httpClient;

    public AgentApiClient(HttpClient httpClient)
    {
        _httpClient = httpClient;
    }

    public async Task<EnrollOperationResult> EnrollAsync(AgentConfiguration configuration, EnrollRequest request, CancellationToken cancellationToken = default)
    {
        try
        {
            using HttpRequestMessage message = new(HttpMethod.Post, BuildEndpoint(configuration.ServerUrl, "api/agents/enroll"))
            {
                Content = JsonContent.Create(request, options: JsonOptions),
            };

            using HttpResponseMessage response = await _httpClient.SendAsync(message, cancellationToken);

            if (response.IsSuccessStatusCode)
            {
                EnrollResponse? payload = await response.Content.ReadFromJsonAsync<EnrollResponse>(JsonOptions, cancellationToken);

                return payload is null
                    ? EnrollOperationResult.FromFailure(AgentApiFailureKind.UnexpectedResponse, "Server returned an empty enrollment payload.")
                    : EnrollOperationResult.FromSuccess(payload);
            }

            string? errorMessage = await ReadMessageAsync(response, cancellationToken);

            return response.StatusCode switch
            {
                HttpStatusCode.UnprocessableEntity => EnrollOperationResult.FromFailure(AgentApiFailureKind.InvalidEnrollmentToken, errorMessage),
                HttpStatusCode.BadRequest => EnrollOperationResult.FromFailure(AgentApiFailureKind.ValidationError, errorMessage),
                _ => EnrollOperationResult.FromFailure(AgentApiFailureKind.UnexpectedResponse, errorMessage),
            };
        }
        catch (HttpRequestException exception)
        {
            return EnrollOperationResult.FromFailure(AgentApiFailureKind.ServerUnreachable, exception.Message);
        }
        catch (TaskCanceledException exception)
        {
            return EnrollOperationResult.FromFailure(AgentApiFailureKind.ServerUnreachable, exception.Message);
        }
    }

    public async Task<HeartbeatOperationResult> SendHeartbeatAsync(AgentConfiguration configuration, HeartbeatRequest request, CancellationToken cancellationToken = default)
    {
        try
        {
            using HttpRequestMessage message = new(HttpMethod.Post, BuildEndpoint(configuration.ServerUrl, "api/agents/heartbeat"))
            {
                Content = JsonContent.Create(request, options: JsonOptions),
            };
            message.Headers.Authorization = new AuthenticationHeaderValue("Bearer", configuration.AgentToken);

            using HttpResponseMessage response = await _httpClient.SendAsync(message, cancellationToken);

            if (response.IsSuccessStatusCode)
            {
                HeartbeatResponse? payload = await response.Content.ReadFromJsonAsync<HeartbeatResponse>(JsonOptions, cancellationToken);

                return payload is null
                    ? HeartbeatOperationResult.FromFailure(AgentApiFailureKind.UnexpectedResponse, "Server returned an empty heartbeat payload.")
                    : HeartbeatOperationResult.FromSuccess(payload);
            }

            string? errorMessage = await ReadMessageAsync(response, cancellationToken);

            return response.StatusCode switch
            {
                HttpStatusCode.Unauthorized => HeartbeatOperationResult.FromFailure(AgentApiFailureKind.Unauthorized, errorMessage),
                HttpStatusCode.BadRequest => HeartbeatOperationResult.FromFailure(AgentApiFailureKind.ValidationError, errorMessage),
                _ => HeartbeatOperationResult.FromFailure(AgentApiFailureKind.UnexpectedResponse, errorMessage),
            };
        }
        catch (HttpRequestException exception)
        {
            return HeartbeatOperationResult.FromFailure(AgentApiFailureKind.ServerUnreachable, exception.Message);
        }
        catch (TaskCanceledException exception)
        {
            return HeartbeatOperationResult.FromFailure(AgentApiFailureKind.ServerUnreachable, exception.Message);
        }
    }

    private static Uri BuildEndpoint(string? serverUrl, string relativePath)
    {
        string baseUrl = string.IsNullOrWhiteSpace(serverUrl) ? "http://localhost/" : serverUrl.Trim();

        if (!baseUrl.EndsWith('/'))
        {
            baseUrl += "/";
        }

        return new Uri(new Uri(baseUrl, UriKind.Absolute), relativePath);
    }

    private static async Task<string?> ReadMessageAsync(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        try
        {
            JsonDocument? document = await response.Content.ReadFromJsonAsync<JsonDocument>(cancellationToken: cancellationToken);

            if (document?.RootElement.TryGetProperty("message", out JsonElement message) == true)
            {
                return message.GetString();
            }
        }
        catch
        {
            string text = await response.Content.ReadAsStringAsync(cancellationToken);

            return string.IsNullOrWhiteSpace(text) ? null : text;
        }

        return null;
    }
}
