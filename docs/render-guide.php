<?php
require __DIR__.'/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml(file_get_contents(__DIR__.'/kozy-user-guide.source.html'), 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
$canvas->page_text(
    495, 800,
    "Page {PAGE_NUM} of {PAGE_COUNT}",
    $font, 8, [0.55, 0.6, 0.65],
    0.0, 0.0, 0.0
);

$out = $argv[1] ?? __DIR__.'/kozy-user-guide.pdf';
file_put_contents($out, $dompdf->output());
echo "written: $out\n";
