export function createFakeSecretProtector() {
  return {
    protect(plainText) {
      return `encrypted::${Buffer.from(plainText, 'utf8').toString('base64')}`;
    },

    unprotect(cipherText) {
      return Buffer.from(cipherText.replace(/^encrypted::/, ''), 'base64').toString('utf8');
    },
  };
}
