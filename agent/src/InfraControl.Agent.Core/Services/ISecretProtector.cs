namespace InfraControl.Agent.Core.Services;

public interface ISecretProtector
{
    string Protect(string plainText);

    string Unprotect(string cipherText);
}
