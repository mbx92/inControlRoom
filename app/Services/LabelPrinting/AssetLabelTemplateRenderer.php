<?php

namespace App\Services\LabelPrinting;

use App\Models\InventoryAsset;
use App\Models\LabelPrinter;
use Illuminate\Support\Str;

class AssetLabelTemplateRenderer
{
    /** Label: 50 mm × 15 mm at 203 dpi (8 dots/mm). */
    private const LABEL_WIDTH_DOTS = 400;

    private const LABEL_HEIGHT_DOTS = 120;

    private const LEFT_MARGIN_DOTS = 16;

    /** Top margin for text block (2.5 mm). */
    private const TOP_MARGIN_DOTS = 20;

    private const RIGHT_MARGIN_DOTS = 16;

    /** Extra inset so QR sits further from the right edge (~4 mm). */
    private const QR_INSET_DOTS = 32;

    private const TEXT_QR_GAP_DOTS = 8;

    /** Approximate QR width at magnification 3 (BQN,2,3 / TSPL level 3). */
    private const QR_SIZE_DOTS = 90;

    private const QR_MAG = 3;

    private const QR_Y = 6;

    private const TITLE_FONT_HEIGHT = 22;

    private const TITLE_FONT_WIDTH = 22;

    private const BODY_FONT_HEIGHT = 16;

    private const BODY_FONT_WIDTH = 16;

    private const TITLE_Y = self::TOP_MARGIN_DOTS;

    private const BODY_START_Y = self::TITLE_Y + self::TITLE_FONT_HEIGHT + 2;

    private const BODY_LINE_STEP_ZPL = 18;

    private const BODY_LINE_STEP_TSPL = 20;

    /** Conservative average character width for truncation. */
    private const TITLE_CHAR_DOTS = 11;

    private const BODY_CHAR_DOTS = 9;

    private const QR_X = self::LABEL_WIDTH_DOTS - self::RIGHT_MARGIN_DOTS - self::QR_SIZE_DOTS - self::QR_INSET_DOTS;

    private const TEXT_MAX_WIDTH_DOTS = self::QR_X - self::TEXT_QR_GAP_DOTS - self::LEFT_MARGIN_DOTS;

    public function renderAssetLabel(LabelPrinter $printer, InventoryAsset $asset, string $qrUrl): array
    {
        $asset->loadMissing('site');
        $fields = $this->fitLabelFields($this->buildLabelFields($asset));

        return [
            'content' => $printer->driver_language === LabelPrinter::DRIVER_TSPL
                ? $this->renderTspl($fields, $qrUrl)
                : $this->renderZpl($fields, $qrUrl),
            'identifier' => $fields['identifier'],
            'qr_url' => $qrUrl,
            'meta' => $fields['meta'],
        ];
    }

    public function renderTestLabel(LabelPrinter $printer): array
    {
        $fields = $this->fitLabelFields([
            'title' => 'SMB Test Label',
            'lines' => [
                'TAG: TEST-'.now()->format('His'),
                'InfraControl · Label Print',
                now()->format('Y-m-d H:i'),
            ],
            'identifier' => now()->format('Ymd-His'),
            'meta' => ['mode' => 'test'],
        ]);
        $payload = 'TEST-'.$fields['identifier'];

        return [
            'content' => $printer->driver_language === LabelPrinter::DRIVER_TSPL
                ? $this->renderTspl($fields, $payload)
                : $this->renderZpl($fields, $payload),
            'identifier' => $fields['identifier'],
            'qr_url' => null,
            'meta' => $fields['meta'],
        ];
    }

    public function assetIdentifier(InventoryAsset $asset): string
    {
        if ($asset->asset_tag) {
            return $asset->asset_tag;
        }

        if ($asset->serial_number) {
            return $asset->serial_number;
        }

        return strtoupper(Str::substr((string) $asset->id, -8));
    }

    /**
     * @return array{title: string, lines: list<string>, identifier: string, meta: array<string, mixed>}
     */
    private function buildLabelFields(InventoryAsset $asset): array
    {
        $identifier = $this->assetIdentifier($asset);
        $lines = [];

        $tagLine = $asset->asset_tag
            ? 'TAG: '.$this->normalizeText($asset->asset_tag)
            : 'ID: '.$this->normalizeText($identifier);

        if ($asset->category) {
            $lines[] = $tagLine.' · '.$this->normalizeText($asset->category);
        } else {
            $lines[] = $tagLine;
        }

        $makeModel = trim(implode(' ', array_filter([
            $asset->manufacturer,
            $asset->model,
        ])));

        if ($makeModel !== '') {
            $lines[] = $makeModel;
        }

        if ($asset->serial_number && $asset->serial_number !== $identifier) {
            $lines[] = 'S/N: '.$this->normalizeText($asset->serial_number);
        }

        $siteLocation = array_values(array_filter([
            $asset->site?->name ? $this->normalizeText($asset->site->name) : null,
            $asset->location_label ? $this->normalizeText($asset->location_label) : null,
        ]));

        if ($siteLocation !== []) {
            $lines[] = implode(' · ', $siteLocation);
        }

        if ($asset->primary_ip) {
            $lines[] = 'IP: '.$this->normalizeText($asset->primary_ip);
        }

        return [
            'title' => $this->normalizeText($asset->name) ?: 'Unknown Asset',
            'lines' => array_slice($lines, 0, 4),
            'identifier' => $identifier,
            'meta' => [
                'asset_name' => $asset->name,
                'asset_tag' => $asset->asset_tag,
                'serial_number' => $asset->serial_number,
                'category' => $asset->category,
                'manufacturer' => $asset->manufacturer,
                'model' => $asset->model,
                'site' => $asset->site?->name,
                'location' => $asset->location_label,
                'primary_ip' => $asset->primary_ip,
                'status' => $asset->status,
            ],
        ];
    }

    /**
     * @param  array{title: string, lines: list<string>, identifier: string, meta: array<string, mixed>}  $fields
     * @return array{title: string, lines: list<string>, identifier: string, meta: array<string, mixed>}
     */
    private function fitLabelFields(array $fields): array
    {
        return [
            ...$fields,
            'title' => $this->truncateToWidth($fields['title'], self::TEXT_MAX_WIDTH_DOTS, self::TITLE_CHAR_DOTS),
            'lines' => array_map(
                fn (string $line) => $this->truncateToWidth($line, self::TEXT_MAX_WIDTH_DOTS, self::BODY_CHAR_DOTS),
                $fields['lines'],
            ),
        ];
    }

    /**
     * @param  array{title: string, lines: list<string>}  $fields
     */
    private function renderZpl(array $fields, string $qrValue): string
    {
        $commands = [
            '^XA',
            '^PW'.self::LABEL_WIDTH_DOTS,
            '^LL'.self::LABEL_HEIGHT_DOTS,
            '^CI28',
            sprintf(
                '^FO%d,%d^A0N,%d,%d^FD%s^FS',
                self::LEFT_MARGIN_DOTS,
                self::TITLE_Y,
                self::TITLE_FONT_HEIGHT,
                self::TITLE_FONT_WIDTH,
                $this->escapeZpl($fields['title']),
            ),
        ];

        $y = self::BODY_START_Y;
        foreach ($fields['lines'] as $line) {
            $commands[] = sprintf(
                '^FO%d,%d^A0N,%d,%d^FD%s^FS',
                self::LEFT_MARGIN_DOTS,
                $y,
                self::BODY_FONT_HEIGHT,
                self::BODY_FONT_WIDTH,
                $this->escapeZpl($line),
            );
            $y += self::BODY_LINE_STEP_ZPL;
        }

        $commands[] = sprintf(
            '^FO%d,%d^BQN,2,%d^FDLA,%s^FS',
            self::QR_X,
            self::QR_Y,
            self::QR_MAG,
            $this->escapeZpl($qrValue),
        );
        $commands[] = '^XZ';

        return implode("\n", $commands);
    }

    /**
     * @param  array{title: string, lines: list<string>}  $fields
     */
    private function renderTspl(array $fields, string $qrValue): string
    {
        $commands = [
            'SIZE 50 mm,15 mm',
            'GAP 2 mm,0 mm',
            'DIRECTION 1',
            'CLS',
            sprintf(
                'TEXT %d,%d,"0",0,1,1,"%s"',
                self::LEFT_MARGIN_DOTS,
                self::TITLE_Y,
                $this->escapeTspl($fields['title']),
            ),
        ];

        $y = self::BODY_START_Y;
        foreach ($fields['lines'] as $line) {
            $commands[] = sprintf(
                'TEXT %d,%d,"0",0,1,1,"%s"',
                self::LEFT_MARGIN_DOTS,
                $y,
                $this->escapeTspl($line),
            );
            $y += self::BODY_LINE_STEP_TSPL;
        }

        $commands[] = sprintf(
            'QRCODE %d,%d,L,%d,A,0,"A,%s"',
            self::QR_X,
            self::QR_Y,
            self::QR_MAG,
            $this->escapeTspl($qrValue),
        );
        $commands[] = 'PRINT 1,1';

        return implode("\n", $commands);
    }

    private function truncateToWidth(string $text, int $maxDots, int $charDotWidth): string
    {
        $normalized = $this->normalizeText($text) ?: 'Unknown Asset';
        $maxChars = max(1, (int) floor($maxDots / $charDotWidth));

        if (mb_strlen($normalized) <= $maxChars) {
            return $normalized;
        }

        if ($maxChars <= 1) {
            return Str::limit($normalized, 1, '');
        }

        return Str::limit($normalized, $maxChars - 1, '…');
    }

    private function normalizeText(?string $value): string
    {
        return preg_replace('/\s+/', ' ', trim((string) $value)) ?: '';
    }

    private function escapeZpl(string $value): string
    {
        return str_replace(['^', '~'], [' ', ' '], $value);
    }

    private function escapeTspl(string $value): string
    {
        return str_replace('"', "'", $value);
    }
}
