export function createBackoffPolicy() {
  return {
    getDelaySeconds(consecutiveFailures) {
      if (consecutiveFailures <= 0) {
        return 0;
      }

      const multiplier = 1 << Math.min(consecutiveFailures - 1, 5);
      return Math.min(120, 5 * multiplier);
    },
  };
}
