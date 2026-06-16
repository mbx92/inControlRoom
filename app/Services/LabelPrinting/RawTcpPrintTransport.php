<?php

namespace App\Services\LabelPrinting;

use App\Models\LabelPrinter;
use RuntimeException;

class RawTcpPrintTransport
{
    private const DEFAULT_PORT = 9100;

    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const WRITE_TIMEOUT_SECONDS = 5;

    public function isAvailable(): bool
    {
        return function_exists('stream_socket_client');
    }

    public function print(LabelPrinter $printer, string $rawContent): void
    {
        $target = $this->buildTarget($printer);
        $socket = @stream_socket_client(
            $target,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            throw new RuntimeException(
                trim($errstr) !== ''
                    ? "Cannot connect to printer at {$target}: {$errstr} ({$errno})"
                    : "Cannot connect to printer at {$target}.",
            );
        }

        try {
            stream_set_timeout($socket, self::WRITE_TIMEOUT_SECONDS);

            $length = strlen($rawContent);
            $written = fwrite($socket, $rawContent);

            if ($written === false || $written < $length) {
                throw new RuntimeException("Failed to send label data to printer at {$target}.");
            }
        } finally {
            fclose($socket);
        }
    }

    public function buildTarget(LabelPrinter $printer): string
    {
        $host = trim($printer->smb_host);
        $port = $printer->lan_port ?: self::DEFAULT_PORT;

        return sprintf('tcp://%s:%d', $host, $port);
    }
}
