using System.Security.Cryptography;
using System.Text;

namespace InfraControl.Agent.Core.Services;

public sealed class DpapiSecretProtector : ISecretProtector
{
    public string Protect(string plainText)
    {
        if (string.IsNullOrEmpty(plainText))
        {
            return string.Empty;
        }

        byte[] plainBytes = Encoding.UTF8.GetBytes(plainText);
        byte[] protectedBytes = ProtectedData.Protect(plainBytes, optionalEntropy: null, DataProtectionScope.LocalMachine);

        return Convert.ToBase64String(protectedBytes);
    }

    public string Unprotect(string cipherText)
    {
        if (string.IsNullOrWhiteSpace(cipherText))
        {
            return string.Empty;
        }

        byte[] protectedBytes = Convert.FromBase64String(cipherText);
        byte[] plainBytes = ProtectedData.Unprotect(protectedBytes, optionalEntropy: null, DataProtectionScope.LocalMachine);

        return Encoding.UTF8.GetString(plainBytes);
    }
}
