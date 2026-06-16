<?php

namespace Tests\Unit\LabelPrinting;

use App\Models\LabelPrinter;
use App\Services\LabelPrinting\LabelPrintTransportResolver;
use App\Services\LabelPrinting\RawTcpPrintTransport;
use App\Services\LabelPrinting\SmbPrintTransport;
use Tests\TestCase;

class LabelPrintTransportResolverTest extends TestCase
{
    public function test_it_resolves_smb_target_and_transport(): void
    {
        $resolver = new LabelPrintTransportResolver(new SmbPrintTransport(), new RawTcpPrintTransport());
        $printer = new LabelPrinter([
            'connection_mode' => LabelPrinter::CONNECTION_SMB,
            'smb_host' => 'PRINT-SRV-01',
            'share_name' => 'ZEBRA-ZD421',
        ]);

        $this->assertSame('//PRINT-SRV-01/ZEBRA-ZD421', $resolver->connectionTarget($printer));
        $this->assertInstanceOf(SmbPrintTransport::class, $resolver->resolve($printer));
    }

    public function test_it_resolves_raw_tcp_target_and_transport(): void
    {
        $resolver = new LabelPrintTransportResolver(new SmbPrintTransport(), new RawTcpPrintTransport());
        $printer = new LabelPrinter([
            'connection_mode' => LabelPrinter::CONNECTION_RAW_TCP,
            'smb_host' => '192.168.1.50',
            'lan_port' => 9100,
            'driver_language' => LabelPrinter::DRIVER_TSPL,
        ]);

        $this->assertSame('tcp://192.168.1.50:9100', $resolver->connectionTarget($printer));
        $this->assertInstanceOf(RawTcpPrintTransport::class, $resolver->resolve($printer));
    }
}
