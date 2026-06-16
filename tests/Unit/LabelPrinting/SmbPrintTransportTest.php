<?php

namespace Tests\Unit\LabelPrinting;

use App\Models\LabelPrinter;
use App\Services\LabelPrinting\SmbPrintTransport;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class SmbPrintTransportTest extends TestCase
{
    public function test_it_builds_target_credentials_and_command_for_smbclient(): void
    {
        $printer = new LabelPrinter([
            'smb_host' => 'PRINT-SRV-01',
            'share_name' => 'ZEBRA-ZD421',
            'username' => 'infra-print',
            'password' => 'secret',
            'domain' => 'WORKGROUP',
        ]);

        $transport = new SmbPrintTransport();

        $this->assertSame('//PRINT-SRV-01/ZEBRA-ZD421', $transport->buildTarget($printer));
        $this->assertStringContainsString('username = infra-print', $transport->buildCredentialsFileContents($printer));
        $this->assertStringContainsString('domain = WORKGROUP', $transport->buildCredentialsFileContents($printer));

        $command = $transport->buildCommand('/usr/bin/smbclient', $printer, '/tmp/label.txt', '/tmp/auth.txt');

        $this->assertSame('/usr/bin/smbclient', $command[0]);
        $this->assertSame('//PRINT-SRV-01/ZEBRA-ZD421', $command[1]);
        $this->assertSame('print /tmp/label.txt', $command[5]);
    }

    public function test_it_fails_clearly_when_smbclient_is_missing(): void
    {
        $finder = $this->createMock(ExecutableFinder::class);
        $finder->method('find')->with('smbclient')->willReturn(null);

        $transport = new SmbPrintTransport($finder);
        $printer = new LabelPrinter([
            'smb_host' => 'PRINT-SRV-01',
            'share_name' => 'ZEBRA-ZD421',
            'username' => 'infra-print',
            'password' => 'secret',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('smbclient is not installed');

        $transport->print($printer, 'TEST');
    }
}
