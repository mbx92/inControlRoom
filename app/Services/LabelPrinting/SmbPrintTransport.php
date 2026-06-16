<?php

namespace App\Services\LabelPrinting;

use App\Models\LabelPrinter;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class SmbPrintTransport
{
    public function __construct(
        private readonly ?ExecutableFinder $executableFinder = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->findBinary() !== null;
    }

    public function print(LabelPrinter $printer, string $rawContent): void
    {
        $binary = $this->findBinary();

        if (! $binary) {
            throw new RuntimeException('smbclient is not installed on this server. Install Samba client tools to enable SMB label printing.');
        }

        $labelFile = tempnam(sys_get_temp_dir(), 'label-job-');
        $authFile = tempnam(sys_get_temp_dir(), 'smb-auth-');

        if (! $labelFile || ! $authFile) {
            throw new RuntimeException('Unable to allocate temporary files for SMB label printing.');
        }

        try {
            file_put_contents($labelFile, $rawContent);
            file_put_contents($authFile, $this->buildCredentialsFileContents($printer));
            @chmod($authFile, 0600);

            $process = new Process($this->buildCommand($binary, $printer, $labelFile, $authFile));
            $process->setTimeout(20);
            $process->run();

            if (! $process->isSuccessful()) {
                $message = trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'Unknown SMB print failure.';

                throw new RuntimeException($message);
            }
        } finally {
            @unlink($labelFile);
            @unlink($authFile);
        }
    }

    public function buildTarget(LabelPrinter $printer): string
    {
        return $printer->sharePath();
    }

    public function buildCredentialsFileContents(LabelPrinter $printer): string
    {
        $lines = [
            'username = '.$printer->username,
            'password = '.$printer->password,
        ];

        if ($printer->domain) {
            $lines[] = 'domain = '.$printer->domain;
        }

        return implode("\n", $lines)."\n";
    }

    public function buildCommand(string $binary, LabelPrinter $printer, string $labelFile, string $authFile): array
    {
        return [
            $binary,
            $this->buildTarget($printer),
            '-A',
            $authFile,
            '-c',
            'print '.$labelFile,
        ];
    }

    private function findBinary(): ?string
    {
        return ($this->executableFinder ?? new ExecutableFinder())->find('smbclient');
    }
}
