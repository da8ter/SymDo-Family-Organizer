<?php

declare(strict_types=1);

trait QrRenderer
{
    private function RenderQrDataUri(string $payload): string
    {
        // Erst hier laden, nicht auf Dateiebene: das Vendor-Paket sind ~49 KB PHP, und
        // gebraucht werden sie von genau zwei Formularknöpfen. Seit die App-Hälfte im
        // Gateway sitzt, hinge es sonst an jedem TGW_-Sync-Aufruf und am Fünf-Sekunden-
        // Poll der App. Nebeneffekt: eine fehlende Datei kostet nur den QR-Code, nicht
        // das ganze Modul.
        require_once __DIR__ . '/vendor/qrcode.php';
        try {
            $qr = \da8ter\SymDo\QRCode::getMinimumQRCode($payload, QR_ERROR_CORRECT_LEVEL_M);
        } catch (\Throwable $e) {
            $this->SendDebug('QrRenderer', 'QR generation failed: ' . $e->getMessage(), 0);
            return '';
        }
        $count = $qr->getModuleCount();
        if (function_exists('imagecreatetruecolor')) {
            return $this->RenderQrPng($qr, $count);
        }
        return $this->RenderQrSvg($qr, $count);
    }

    private function RenderQrPng(\da8ter\SymDo\QRCode $qr, int $count): string
    {
        $cell  = 8;
        $quiet = 4;
        $size  = ($count + 2 * $quiet) * $cell;
        $image = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $size - 1, $size - 1, $white);
        for ($row = 0; $row < $count; $row++) {
            for ($col = 0; $col < $count; $col++) {
                if ($qr->isDark($row, $col)) {
                    $x = ($col + $quiet) * $cell;
                    $y = ($row + $quiet) * $cell;
                    imagefilledrectangle($image, $x, $y, $x + $cell - 1, $y + $cell - 1, $black);
                }
            }
        }
        ob_start();
        imagepng($image);
        $png = (string)ob_get_clean();
        imagedestroy($image);
        return 'data:image/png;base64,' . base64_encode($png);
    }

    private function RenderQrSvg(\da8ter\SymDo\QRCode $qr, int $count): string
    {
        $cell  = 8;
        $quiet = 4;
        $size  = ($count + 2 * $quiet) * $cell;
        $rects = '';
        for ($row = 0; $row < $count; $row++) {
            for ($col = 0; $col < $count; $col++) {
                if ($qr->isDark($row, $col)) {
                    $x = ($col + $quiet) * $cell;
                    $y = ($row + $quiet) * $cell;
                    $rects .= '<rect x="' . $x . '" y="' . $y . '" width="' . $cell . '" height="' . $cell . '"/>';
                }
            }
        }
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '">'
            . '<rect width="' . $size . '" height="' . $size . '" fill="#ffffff"/>'
            . '<g fill="#000000">' . $rects . '</g></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
