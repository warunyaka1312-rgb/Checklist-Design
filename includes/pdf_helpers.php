<?php
/**
 * Thai-capable PDF export helpers, built on FPDF (vendor/fpdf, single-file
 * library, no Composer) plus the bundled Sarabun font (SIL OFL) converted to
 * FPDF's cp874 (Thai) encoding via FPDF's own makefont utility.
 *
 * FPDF's core "standard 14" fonts have no Thai glyphs at all, so any UTF-8
 * Thai text must go through a custom embedded TTF font instead. Thai fits in
 * a single-byte codepage (unlike CJK), so plain FPDF + cp874 is sufficient;
 * there was no need to reach for a larger UTF-8 PDF library.
 */

require_once __DIR__ . '/../vendor/fpdf/fpdf.php';

/**
 * FPDF subclass pre-registered with the Sarabun (Thai) font.
 */
class ThaiPdf extends FPDF
{
    public function __construct()
    {
        parent::__construct('P', 'mm', 'A4');
        $fontDir = dirname(__DIR__) . '/vendor/fpdf/font/';
        $this->AddFont('Sarabun', '', 'sarabun.json', $fontDir);
        $this->AddFont('Sarabun', 'B', 'sarabunb.json', $fontDir);
        $this->SetFont('Sarabun', '', 11);
        $this->SetMargins(15, 15, 15);
        $this->SetAutoPageBreak(true, 18);
    }
}

/**
 * Convert a UTF-8 string (Thai + Latin mixed) to the cp874 (Thai) byte
 * encoding the embedded Sarabun font definition expects.
 */
function pdfText(?string $utf8): string
{
    if ($utf8 === null || $utf8 === '') {
        return '';
    }
    $out = @iconv('UTF-8', 'CP874//TRANSLIT', $utf8);
    return $out !== false ? $out : $utf8;
}
