<?php

declare(strict_types=1);

namespace PII\Modules\Scheduling;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * ScheduleExporter — one sheet per mill, days as sections, runs in order.
 * No time estimates on the output (deliberate — see engine doc).
 */
class ScheduleExporter
{
    public function stream(array $schedule, string $appName): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator($appName)
            ->setTitle('Production Schedule — week of ' . ($schedule['week_start'] ?? ''));

        $first = true;
        foreach ($schedule['mills'] ?? [] as $mill) {
            $sheet = $first ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $first = false;

            // Sheet titles: max 31 chars, no special chars
            $title = substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '', (string) $mill['mill_name']), 0, 31) ?: 'Mill';
            $sheet->setTitle($title);

            $r = 1;
            $sheet->setCellValue("A{$r}", $mill['mill_name'] . ' — week of ' . $schedule['week_start']);
            $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(14);
            $sheet->mergeCells("A{$r}:G{$r}");
            $r += 2;

            foreach ($mill['days'] ?? [] as $day) {
                if (empty($day['enabled'])) continue;

                $sheet->setCellValue("A{$r}", $day['dow'] . ' ' . $day['date']);
                $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(12);
                $sheet->getStyle("A{$r}:G{$r}")->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFDCE6F1');
                $sheet->mergeCells("A{$r}:G{$r}");
                $r++;

                if (empty($day['runs'])) {
                    $sheet->setCellValue("A{$r}", '(no runs scheduled)');
                    $sheet->getStyle("A{$r}")->getFont()->setItalic(true);
                    $r += 2;
                    continue;
                }

                // Header row
                $headers = ['#', 'Item', 'Description', 'Color', 'Passes', 'Total Lbs', 'Pack Breakdown'];
                foreach ($headers as $i => $h) {
                    $sheet->setCellValue([$i + 1, $r], $h);
                }
                $sheet->getStyle("A{$r}:G{$r}")->getFont()->setBold(true);
                $r++;

                $n = 0;
                foreach ($day['runs'] as $run) {
                    $n++;
                    // Bulk item description (engine-supplied); fall back to
                    // the first pack's description for older payloads.
                    $desc = (string) ($run['description'] ?? '');
                    $packParts = [];
                    foreach ($run['pack_breakdown'] ?? [] as $pk) {
                        if ($desc === '') $desc = (string) ($pk['description'] ?? '');
                        $packParts[] = $pk['pack'] . ': ' . number_format((float) $pk['lbs'], 0) . ' lbs';
                    }

                    $label = (string) $run['bulk'];
                    if (($run['batch_count'] ?? 1) > 1) {
                        $label .= ' (batch ' . $run['batch_no'] . '/' . $run['batch_count'] . ')';
                    }
                    if (($run['source'] ?? '') === 'ticket' && !empty($run['ticket'])) {
                        $label .= '  [TICKET ' . $run['ticket']
                            . (!empty($run['due']) ? ' due ' . $run['due'] : '') . ']';
                    }
                    if (!empty($run['materials_short'])) {
                        $label .= '  [SHORT MATERIALS]';
                    }
                    if (!empty($run['mto'])) {
                        $label .= '  [MTO]';
                    }
                    if (!empty($run['carryover'])) {
                        $label .= '  ⟵ CARRYOVER (continued from previous day)';
                    }

                    $colorCell = strtoupper((string) $run['color']);
                    if (!empty($run['dry_grind'])) {
                        $colorCell .= '  (DRY GRIND)';
                    }
                    $sheet->setCellValue("A{$r}", $n);
                    $sheet->setCellValue("B{$r}", $label);
                    $sheet->setCellValue("C{$r}", $desc);
                    $sheet->setCellValue("D{$r}", $colorCell);
                    $sheet->setCellValue("E{$r}", !empty($run['carryover']) ? '' : (int) ($run['passes'] ?? 1));
                    $sheet->setCellValue("F{$r}", !empty($run['carryover']) ? '' : (float) $run['lbs']);
                    $sheet->setCellValue("G{$r}", implode(' · ', $packParts));

                    if (!empty($run['carryover'])) {
                        $sheet->getStyle("A{$r}:G{$r}")->getFont()->setItalic(true);
                        $sheet->getStyle("A{$r}:G{$r}")->getFill()->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()->setARGB('FFF5F0DC');
                    }
                    if (!empty($run['tier1']) && empty($run['carryover'])) {
                        $sheet->getStyle("B{$r}")->getFont()->setBold(true);
                    }
                    $r++;
                }
                $r++;   // gap between days
            }

            $sheet->getStyle('F:F')->getNumberFormat()->setFormatCode('#,##0');
            foreach (range('A', 'G') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
        }

        // Unscheduled sheet (if any)
        if (!empty($schedule['unscheduled'])) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('Unscheduled');
            $sheet->setCellValue('A1', 'Could not fit this week — most popular first');
            $sheet->getStyle('A1')->getFont()->setBold(true);
            $headers = ['Item', 'Description', 'Color', 'Lbs', 'Priority', 'Why unscheduled', 'Trailing 91d lbs sold', 'Pack Breakdown'];
            foreach ($headers as $i => $h) {
                $sheet->setCellValue([$i + 1, 2], $h);
            }
            $sheet->getStyle('A2:H2')->getFont()->setBold(true);
            $r = 3;
            foreach ($schedule['unscheduled'] as $u) {
                $packParts = [];
                foreach ($u['pack_breakdown'] ?? [] as $pk) {
                    $packParts[] = $pk['pack'] . ': ' . number_format((float) $pk['lbs'], 0) . ' lbs';
                }
                $colorCell = strtoupper((string) $u['color']);
                if (!empty($u['dry_grind'])) $colorCell .= '  (DRY GRIND)';
                $sheet->setCellValue("A{$r}", $u['bulk']);
                $sheet->setCellValue("B{$r}", (string) ($u['description'] ?? ''));
                $sheet->setCellValue("C{$r}", $colorCell);
                $sheet->setCellValue("D{$r}", (float) $u['lbs']);
                $priority = match ($u['source'] ?? '') {
                    'ticket' => 'TICKET ' . ($u['ticket'] ?? '') . (!empty($u['materials_short']) ? ' (SHORT MATERIALS)' : ''),
                    'order'  => 'ORDER SHORTFALL',
                    'near'   => 'Near min',
                    default  => 'Below min',
                };
                $sheet->setCellValue("E{$r}", $priority);
                $sheet->setCellValue("F{$r}", (string) ($u['reason'] ?? ''));
                $sheet->setCellValue("G{$r}", (float) ($u['popularity'] ?? 0));
                $sheet->setCellValue("H{$r}", implode(' · ', $packParts));
                $r++;
            }
            foreach (range('A', 'H') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        $filename = 'production_schedule_' . str_replace('-', '', (string) ($schedule['week_start'] ?? 'week')) . '.xlsx';

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0, no-cache, must-revalidate');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}
