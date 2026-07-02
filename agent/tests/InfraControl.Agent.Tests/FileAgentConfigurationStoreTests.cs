using InfraControl.Agent.Core.Models;
using InfraControl.Agent.Core.Services;
using System.Text;

namespace InfraControl.Agent.Tests;

public sealed class FileAgentConfigurationStoreTests
{
    [Fact]
    public async Task save_and_load_round_trip_encrypts_agent_token()
    {
        string directory = Path.Combine(Path.GetTempPath(), "infra-agent-tests", Guid.NewGuid().ToString("N"));
        string path = Path.Combine(directory, "config.json");
        FileAgentConfigurationStore store = new(path, new FakeSecretProtector());

        try
        {
            await store.SaveAsync(new AgentConfiguration
            {
                ServerUrl = "https://example.test",
                EnrollmentToken = "enroll-once",
                AgentToken = "agent-secret",
                HeartbeatIntervalSeconds = 45,
            });

            string rawJson = await File.ReadAllTextAsync(path);
            Assert.DoesNotContain("agent-secret", rawJson, StringComparison.Ordinal);
            Assert.Contains("encrypted::", rawJson, StringComparison.Ordinal);

            AgentConfiguration loaded = await store.LoadAsync();

            Assert.Equal("https://example.test", loaded.ServerUrl);
            Assert.Equal("enroll-once", loaded.EnrollmentToken);
            Assert.Equal("agent-secret", loaded.AgentToken);
            Assert.Equal(45, loaded.HeartbeatIntervalSeconds);
        }
        finally
        {
            if (Directory.Exists(directory))
            {
                Directory.Delete(directory, recursive: true);
            }
        }
    }

    private sealed class FakeSecretProtector : ISecretProtector
    {
        public string Protect(string plainText) => $"encrypted::{Convert.ToBase64String(Encoding.UTF8.GetBytes(plainText))}";

        public string Unprotect(string cipherText) =>
            Encoding.UTF8.GetString(Convert.FromBase64String(cipherText.Replace("encrypted::", string.Empty, StringComparison.Ordinal)));
    }
}
