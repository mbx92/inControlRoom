<?php

namespace Tests\Unit\LabelPrinting;

use App\Models\InventoryAsset;
use App\Models\LabelPrinter;
use App\Models\Site;
use App\Services\LabelPrinting\AssetLabelTemplateRenderer;
use Tests\TestCase;

class AssetLabelTemplateRendererTest extends TestCase
{
    public function test_it_renders_zpl_asset_label_content(): void
    {
        $renderer = new AssetLabelTemplateRenderer();
        $printer = new LabelPrinter(['driver_language' => 'zpl']);
        $asset = new InventoryAsset([
            'name' => 'Dell Optiplex Micro',
            'asset_tag' => 'AST-1001',
            'category' => 'Mini PC',
            'manufacturer' => 'Dell',
            'model' => 'OptiPlex 7090',
            'primary_ip' => '192.168.1.50',
        ]);
        $asset->id = '019ec123-e255-71de-828d-39b45dd6b887';

        $rendered = $renderer->renderAssetLabel($printer, $asset, 'https://example.test/scan');

        $this->assertStringContainsString('^XA', $rendered['content']);
        $this->assertStringContainsString('^PW400', $rendered['content']);
        $this->assertStringContainsString('^LL120', $rendered['content']);
        $this->assertStringContainsString('^FO16,20^A0N,22,22', $rendered['content']);
        $this->assertStringContainsString('^FO262,6^BQN,2,3', $rendered['content']);
        $this->assertStringContainsString('^FO16,44^A0N,16,16', $rendered['content']);
        $this->assertStringContainsString('Dell Optiplex Micro', $rendered['content']);
        $this->assertStringContainsString('TAG: AST-1001', $rendered['content']);
        $this->assertStringContainsString('Mini PC', $rendered['content']);
        $this->assertStringContainsString('Dell OptiPlex 7090', $rendered['content']);
        $this->assertStringContainsString('IP: 192.168.1.50', $rendered['content']);
        $this->assertStringContainsString('https://example.test/scan', $rendered['content']);
        $this->assertSame('AST-1001', $rendered['meta']['asset_tag']);
    }

    public function test_it_renders_tspl_asset_label_content(): void
    {
        $renderer = new AssetLabelTemplateRenderer();
        $printer = new LabelPrinter(['driver_language' => 'tspl']);
        $asset = new InventoryAsset([
            'name' => 'Dell Optiplex Micro',
            'asset_tag' => 'AST-1001',
            'category' => 'Server',
        ]);
        $asset->id = '019ec123-e255-71de-828d-39b45dd6b887';

        $rendered = $renderer->renderAssetLabel($printer, $asset, 'https://example.test/scan');

        $this->assertStringContainsString('SIZE 50 mm,15 mm', $rendered['content']);
        $this->assertStringContainsString('TEXT 16,20,"0",0,1,1', $rendered['content']);
        $this->assertStringContainsString('QRCODE 262,6,L,3,A,0', $rendered['content']);
        $this->assertStringContainsString('TAG: AST-1001', $rendered['content']);
        $this->assertStringContainsString('Server', $rendered['content']);
    }

    public function test_it_includes_site_location_and_serial_in_label_lines(): void
    {
        $renderer = new AssetLabelTemplateRenderer();
        $printer = new LabelPrinter(['driver_language' => 'zpl']);

        $site = new Site(['name' => 'Homelab']);
        $site->id = '019ec123-e255-71de-828d-39b45dd6b888';

        $asset = new InventoryAsset([
            'name' => 'Core Switch',
            'asset_tag' => 'SW-01',
            'serial_number' => 'SN-7788',
            'location_label' => 'Rack A1',
        ]);
        $asset->id = '019ec123-e255-71de-828d-39b45dd6b887';
        $asset->setRelation('site', $site);

        $rendered = $renderer->renderAssetLabel($printer, $asset, 'https://example.test/scan');

        $this->assertStringContainsString('Homelab · Rack A1', $rendered['content']);
        $this->assertStringContainsString('S/N: SN-7788', $rendered['content']);
        $this->assertSame('Homelab', $rendered['meta']['site']);
        $this->assertSame('Rack A1', $rendered['meta']['location']);
    }

    public function test_it_truncates_long_text_before_qr_column(): void
    {
        $renderer = new AssetLabelTemplateRenderer();
        $printer = new LabelPrinter(['driver_language' => 'zpl']);

        $longName = 'Very Long Asset Name That Should Be Truncated Before QR Code Area';
        $asset = new InventoryAsset([
            'name' => $longName,
            'asset_tag' => 'AST-1001',
            'manufacturer' => 'Manufacturer With Extraordinarily Long Product Name Here',
        ]);
        $asset->id = '019ec123-e255-71de-828d-39b45dd6b887';

        $rendered = $renderer->renderAssetLabel($printer, $asset, 'https://example.test/scan');

        $this->assertStringNotContainsString($longName, $rendered['content']);
        $this->assertStringContainsString('…', $rendered['content']);
        $this->assertStringNotContainsString('Extraordinarily Long Product Name Here', $rendered['content']);
    }

    public function test_it_falls_back_to_serial_number_or_uuid_suffix_for_identifier(): void
    {
        $renderer = new AssetLabelTemplateRenderer();

        $assetWithSerial = new InventoryAsset([
            'name' => 'Asset A',
            'serial_number' => 'SER-8899',
        ]);
        $assetWithSerial->id = '019ec123-e255-71de-828d-39b45dd6b887';
        $assetWithoutTagOrSerial = new InventoryAsset([
            'name' => 'Asset B',
        ]);
        $assetWithoutTagOrSerial->id = '019ec123-e255-71de-828d-39b45dd6b887';

        $this->assertSame('SER-8899', $renderer->assetIdentifier($assetWithSerial));
        $this->assertSame('5DD6B887', $renderer->assetIdentifier($assetWithoutTagOrSerial));
    }
}
