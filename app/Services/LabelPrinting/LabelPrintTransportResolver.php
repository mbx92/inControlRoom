<?php

namespace App\Services\LabelPrinting;

use App\Models\LabelPrinter;
use RuntimeException;

class LabelPrintTransportResolver
{
    public function __construct(
        private readonly SmbPrintTransport $smbTransport,
        private readonly RawTcpPrintTransport $rawTcpTransport,
    ) {
    }

    public function resolve(LabelPrinter $printer): SmbPrintTransport|RawTcpPrintTransport
    {
        return match ($printer->connection_mode) {
            LabelPrinter::CONNECTION_SMB => $this->smbTransport,
            LabelPrinter::CONNECTION_RAW_TCP => $this->rawTcpTransport,
            default => throw new RuntimeException("Unsupported label printer connection mode: {$printer->connection_mode}"),
        };
    }

    public function isAvailable(LabelPrinter $printer): bool
    {
        return $this->resolve($printer)->isAvailable();
    }

    public function print(LabelPrinter $printer, string $rawContent): void
    {
        $this->resolve($printer)->print($printer, $rawContent);
    }

    public function connectionTarget(LabelPrinter $printer): string
    {
        return match ($printer->connection_mode) {
            LabelPrinter::CONNECTION_SMB => $printer->sharePath(),
            LabelPrinter::CONNECTION_RAW_TCP => $this->rawTcpTransport->buildTarget($printer),
            default => $printer->sharePath(),
        };
    }
}
