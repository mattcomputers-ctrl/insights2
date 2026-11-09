<?php

declare(strict_types=1);

namespace PII\Modules\OrderReadiness;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * ListExporter — stream a simple header+rows list as XLSX or CSV.
 */
class ListExporter
{
    /** @param list<string> $header @param list<list<mixed>> $rows */
    public static function stream(string $title, array $header, array $rows, string $format): never
    {
        $filename = strtolower(str_replace(' ', '-', $title)) . '-' . date('Y-m-d');

        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn($v) => $v ?? '', $row));
            }
            fclose($out);
            exit;
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($title, 0, 31));

        $sheet->setCellValue([1, 1], $title . ' — exported ' . date('Y-m-d g:i A'));
        $sheet->getStyle([1, 1])->getFont()->setBold(true)->setSize(13);

        foreach ($header as $i => $h) {
            $sheet->setCellValue([$i + 1, 3], $h);
        }
        $sheet->getStyle([1, 3, count($header), 3])->getFont()->setBold(true);

        $r = 4;
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $v) {
                $sheet->setCellValue([$i + 1, $r], $v);
            }
            $r++;
        }
        foreach (range(1, count($header)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }
        $sheet->setAutoFilter([1, 3, count($header), max(3, $r - 1)]);
        $sheet->freezePane([1, 4]);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        header('Cache-Control: no-store');
        (new Xlsx($spreadsheet))->save('php://output');
        exit;
    }
}
