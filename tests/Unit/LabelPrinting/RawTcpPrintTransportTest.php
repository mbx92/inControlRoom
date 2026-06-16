<?php

namespace Tests\Unit\LabelPrinting;

use App\Models\LabelPrinter;
use App\Services\LabelPrinting\RawTcpPrintTransport;
use Tests\TestCase;

class RawTcpPrintTransportTest extends TestCase
{
    public function test_it_builds_tcp_target_from_printer_host_and_port(): void
    {
        $transport = new RawTcpPrintTransport();
        $printer = new LabelPrinter([
            'smb_host' => '192.168.1.50',
            'lan_port' => 9100,
        ]);

        $this->assertSame('tcp://192.168.1.50:9100', $transport->buildTarget($printer));
    }

    public function test_it_defaults_to_port_9100_when_lan_port_is_missing(): void
    {
        $transport = new RawTcpPrintTransport();
        $printer = new LabelPrinter([
            'smb_host' => '10.0.0.25',
            'lan_port' => null,
        ]);

        $this->assertSame('tcp://10.0.0.25:9100', $transport->buildTarget($printer));
    }
}
