<?php

declare(strict_types=1);

namespace PII\Modules\MarginWatchdog;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * ExcelExporter — Streams an .xlsx download of the full Margin Watchdog
 * hierarchy: top summary → Bill To rows → items per Bill To.
 *
 * Three sheets:
 *   "Summary"     — company-wide rollup (4 metric rows)
 *   "Bill To"     — one row per Bill To with all derived metrics
 *   "Items"       — one row per (Bill To, item) with all derived metrics
 */
class ExcelExporter
{
    /** @param array $params       baseline_start, baseline_end, comparison_start, comparison_end */
    /** @param array $summary      MetricsCalculator output for company-wide rollup */
    /** @param array $billTos      list of ['raw'=>..., 'metrics'=>..., 'items'=>[...]] */
    /** @param string $appName     for the workbook title */
    public function stream(array $params, array $summary, array $billTos, string $appName): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator($appName)
            ->setTitle('Margin Watchdog Report');

        $this->writeSummarySheet($spreadsheet->getActiveSheet(), $params, $summary);
        $btSheet = $spreadsheet->createSheet();
        $this->writeBillToSheet($btSheet, $params, $billTos);
        $itemSheet = $spreadsheet->createSheet();
        $this->writeItemsSheet($itemSheet, $params, $billTos);

        $filename = sprintf(
            'margin_watchdog_%s_vs_%s.xlsx',
            str_replace('-', '', $params['baseline_start']) . '-' . str_replace('-', '', $params['baseline_end']),
            str_replace('-', '', $params['comparison_start']) . '-' . str_replace('-', '', $params['comparison_end'])
        );

        // Stream to browser
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0, no-cache, must-revalidate');
        header('Pragma: public');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    private function writeSummarySheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $params, array $m): void
    {
        $sheet->setTitle('Summary');

        $sheet->setCellValue('A1', 'Margin Watchdog — Company-wide summary');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->mergeCells('A1:D1');

        $sheet->setCellValue('A2', 'Baseline:');
        $sheet->setCellValue('B2', $params['baseline_start'] . ' — ' . $params['baseline_end']);
        $sheet->setCellValue('A3', 'Comparison:');
        $sheet->setCellValue('B3', $params['comparison_start'] . ' — ' . $params['comparison_end']);
        $sheet->setCellValue('A4', 'Generated:');
        $sheet->setCellValue('B4', date('Y-m-d H:i:s'));

        // Header row
        $row = 6;
        $headers = ['Metric', 'Baseline', 'Comparison', 'Difference'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, $row], $h);
        }
        $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:D{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE7EBF0');
        $row++;

        $rows = [
            ['Total Revenue',          $m['revenue']['baseline'],         $m['revenue']['comparison'],         $m['revenue']['diff_dollars'],          $m['revenue']['diff_pct'],            'pct'],
            ['Total Packed Cost',      $m['packed_cost']['baseline'],     $m['packed_cost']['comparison'],     $m['packed_cost']['diff_dollars'],      $m['packed_cost']['diff_pct'],        'pct'],
            ['$ Over Packed Cost',     $m['dollars_over_cost']['baseline'], $m['dollars_over_cost']['comparison'], $m['dollars_over_cost']['diff_dollars'], $m['dollars_over_cost']['diff_pct'], 'pct'],
            ['Packed Cost % of Rev',   $m['cost_pct_revenue']['baseline'], $m['cost_pct_revenue']['comparison'], null,                                  $m['cost_pct_revenue']['diff_pp'],     'pp'],
        ];
        foreach ($rows as $r) {
            $sheet->setCellValue("A{$row}", $r[0]);
            $sheet->setCellValue("B{$row}", $r[1]);
            $sheet->setCellValue("C{$row}", $r[2]);

            $diffStr = '';
            if ($r[5] === 'pct') {
                if ($r[3] !== null) $diffStr = $this->fmtSignedMoney($r[3]);
                if ($r[4] !== null) $diffStr .= ($diffStr !== '' ? ' / ' : '') . $this->fmtSignedPct($r[4]);
            } else { // pp
                $diffStr = $r[4] === null ? 'N/A' : $this->fmtPp($r[4]);
            }
            $sheet->setCellValue("D{$row}", $diffStr);

            // Format B and C as money or % depending on metric
            if ($r[5] === 'pct') {
                $sheet->getStyle("B{$row}:C{$row}")->getNumberFormat()->setFormatCode('"$"#,##0.00');
            } else {
                $sheet->getStyle("B{$row}:C{$row}")->getNumberFormat()->setFormatCode('0.00"%"');
            }

            $row++;
        }

        foreach (range('A', 'D') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function writeBillToSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $params, array $billTos): void
    {
        $sheet->setTitle('Bill To');

        $headers = [
            'Bill To Code', 'Bill To Name',
            'Baseline Revenue', 'Comparison Revenue', 'Δ Revenue ($)', 'Δ Revenue (%)',
            'Baseline Packed Cost', 'Comparison Packed Cost', 'Δ Cost ($)', 'Δ Cost (%)',
            'Baseline $ Over Cost', 'Comparison $ Over Cost', 'Δ $ Over Cost ($)', 'Δ $ Over Cost (%)',
            'Baseline Cost % of Rev', 'Comparison Cost % of Rev', 'Δ Cost %',
            'Baseline Qty', 'Comparison Qty',
        ];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, 1], $h);
        }
        $sheet->getStyle('A1:S1')->getFont()->setBold(true);
        $sheet->getStyle('A1:S1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE7EBF0');
        $sheet->freezePane('A2');

        $r = 2;
        foreach ($billTos as $bt) {
            $raw = $bt['raw']; $m = $bt['metrics'];
            $sheet->setCellValue("A{$r}", $raw['bill_to']);
            $sheet->setCellValue("B{$r}", $raw['bill_to_name']);
            $sheet->setCellValue("C{$r}", $m['revenue']['baseline']);
            $sheet->setCellValue("D{$r}", $m['revenue']['comparison']);
            $sheet->setCellValue("E{$r}", $m['revenue']['diff_dollars']);
            $sheet->setCellValue("F{$r}", $m['revenue']['diff_pct']);
            $sheet->setCellValue("G{$r}", $m['packed_cost']['baseline']);
            $sheet->setCellValue("H{$r}", $m['packed_cost']['comparison']);
            $sheet->setCellValue("I{$r}", $m['packed_cost']['diff_dollars']);
            $sheet->setCellValue("J{$r}", $m['packed_cost']['diff_pct']);
            $sheet->setCellValue("K{$r}", $m['dollars_over_cost']['baseline']);
            $sheet->setCellValue("L{$r}", $m['dollars_over_cost']['comparison']);
            $sheet->setCellValue("M{$r}", $m['dollars_over_cost']['diff_dollars']);
            $sheet->setCellValue("N{$r}", $m['dollars_over_cost']['diff_pct']);
            $sheet->setCellValue("O{$r}", $m['cost_pct_revenue']['baseline']);
            $sheet->setCellValue("P{$r}", $m['cost_pct_revenue']['comparison']);
            $sheet->setCellValue("Q{$r}", $m['cost_pct_revenue']['diff_pp']);
            $sheet->setCellValue("R{$r}", $m['qty']['baseline']);
            $sheet->setCellValue("S{$r}", $m['qty']['comparison']);
            $r++;
        }

        // Number formats
        $sheet->getStyle("C2:E{$r}")->getNumberFormat()->setFormatCode('"$"#,##0.00');
        $sheet->getStyle("G2:I{$r}")->getNumberFormat()->setFormatCode('"$"#,##0.00');
        $sheet->getStyle("K2:M{$r}")->getNumberFormat()->setFormatCode('"$"#,##0.00');
        $sheet->getStyle("F2:F{$r}")->getNumberFormat()->setFormatCode('0.00"%"');
        $sheet->getStyle("J2:J{$r}")->getNumberFormat()->setFormatCode('0.00"%"');
        $sheet->getStyle("N2:N{$r}")->getNumberFormat()->setFormatCode('0.00"%"');
        $sheet->getStyle("O2:Q{$r}")->getNumberFormat()->setFormatCode('0.00"%"');
        $sheet->getStyle("R2:S{$r}")->getNumberFormat()->setFormatCode('#,##0');

        for ($c = 'A'; $c !== 'T'; $c++) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }
    }

    private function writeItemsSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $params, array $billTos): void
    {
        $sheet->setTitle('Items');

        $headers = [
            'Bill To Code', 'Bill To Name',
            'Alias', 'Description', 'Unit',
            'Baseline Qty', 'Comparison Qty',
            'Baseline Revenue', 'Comparison Revenue', 'Δ Revenue (%)',
            'Avg Sale Baseline', 'Avg Sale Comparison', 'Δ Avg Sale (%)',
            'Avg Cost Baseline', 'Avg Cost Comparison', 'Δ Avg Cost (%)',
            'Avg Cost % Sale Baseline', 'Avg Cost % Sale Comparison', 'Δ Avg Cost % Sale',
            'Expected Packed Cost (today)', 'Expected Cost % of Comp Sale', 'Horizon Δ vs Comp Actual',
            'Mixed UoM?',
        ];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, 1], $h);
        }
        $sheet->getStyle('A1:W1')->getFont()->setBold(true);
        $sheet->getStyle('A1:W1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE7EBF0');
        $sheet->freezePane('A2');

        $r = 2;
        foreach ($billTos as $bt) {
            $raw = $bt['raw'];
            foreach ($bt['items'] as $it) {
                $i = $it['raw']; $m = $it['metrics'];
                $sheet->setCellValue("A{$r}", $raw['bill_to']);
                $sheet->setCellValue("B{$r}", $raw['bill_to_name']);
                $sheet->setCellValue("C{$r}", $i['item_name']);
                $sheet->setCellValue("D{$r}", $i['description']);
                $sheet->setCellValue("E{$r}", $i['unit']);
                $sheet->setCellValue("F{$r}", $m['qty']['baseline']);
                $sheet->setCellValue("G{$r}", $m['qty']['comparison']);
                $sheet->setCellValue("H{$r}", $m['revenue']['baseline']);
                $sheet->setCellValue("I{$r}", $m['revenue']['comparison']);
                $sheet->setCellValue("J{$r}", $m['revenue']['diff_pct']);
                $sheet->setCellValue("K{$r}", $m['avg_sale']['baseline']);
                $sheet->setCellValue("L{$r}", $m['avg_sale']['comparison']);
                $sheet->setCellValue("M{$r}", $m['avg_sale']['diff_pct']);
                $sheet->setCellValue("N{$r}", $m['avg_cost']['baseline']);
                $sheet->setCellValue("O{$r}", $m['avg_cost']['comparison']);
                $sheet->setCellValue("P{$r}", $m['avg_cost']['diff_pct']);
                $sheet->setCellValue("Q{$r}", $m['avg_cost_pct']['baseline']);
                $sheet->setCellValue("R{$r}", $m['avg_cost_pct']['comparison']);
                $sheet->setCellValue("S{$r}", $m['avg_cost_pct']['diff_pp']);
                $sheet->setCellValue("T{$r}", $m['expected_packed_cost']);
                $sheet->setCellValue("U{$r}", $m['expected_cost_pct_of_comparison_sale']['value']);
                $sheet->setCellValue("V{$r}", $m['expected_cost_pct_of_comparison_sale']['horizon_delta_pp']);
                $sheet->setCellValue("W{$r}", !empty($i['unit_mixed']) ? 'YES' : '');
                $r++;
            }
        }

        // Number formats
        $sheet->getStyle("F2:G{$r}")->getNumberFormat()->setFormatCode('#,##0.0000');
        $sheet->getStyle("H2:I{$r}")->getNumberFormat()->setFormatCode('"$"#,##0.00');
        $sheet->getStyle("K2:L{$r}")->getNumberFormat()->setFormatCode('"$"#,##0.0000');
        $sheet->getStyle("N2:O{$r}")->getNumberFormat()->setFormatCode('"$"#,##0.0000');
        $sheet->getStyle("J2:J{$r}")->getNumberFormat()->setFormatCode('0.00"%"');
        $sheet->getStyle("M2:M{$r}")->getNumberFormat()->setFormatCode('0.00"%"');
        $sheet->getStyle("P2:P{$r}")->getNumberFormat()->setFormatCode('0.00"%"');
        $sheet->getStyle("Q2:S{$r}")->getNumberFormat()->setFormatCode('0.00"%"');
        $sheet->getStyle("T2:T{$r}")->getNumberFormat()->setFormatCode('"$"#,##0.0000');
        $sheet->getStyle("U2:V{$r}")->getNumberFormat()->setFormatCode('0.00"%"');

        for ($c = 'A'; $c !== 'X'; $c++) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }
    }

    private function fmtSignedMoney(?float $v): string
    {
        if ($v === null) return 'N/A';
        $sign = $v > 0 ? '+' : ($v < 0 ? '-' : '');
        return $sign . '$' . number_format(abs($v), 2);
    }
    private function fmtSignedPct(?float $v): string
    {
        if ($v === null) return 'N/A';
        $sign = $v > 0 ? '+' : '';
        return $sign . number_format($v, 2) . '%';
    }
    private function fmtPp(?float $v): string
    {
        if ($v === null) return 'N/A';
        $sign = $v > 0 ? '+' : '';
        // Point delta but displayed as "%" — context (adjacent percentage cells) disambiguates.
        return $sign . number_format($v, 2) . '%';
    }
}
