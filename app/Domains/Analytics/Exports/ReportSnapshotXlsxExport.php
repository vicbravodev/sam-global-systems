<?php

namespace App\Domains\Analytics\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportSnapshotXlsxExport
{
    private const HEADERS = [
        'A' => 'Metric',
        'B' => 'Period start',
        'C' => 'Period end',
        'D' => 'Value',
        'E' => 'Unit',
    ];

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function build(array $snapshot, string $reportName, string $teamName): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Report');

        $this->writeMeta($sheet, $snapshot, $reportName, $teamName);
        $this->writeHeaders($sheet);
        $this->writeRows($sheet, (array) ($snapshot['metrics'] ?? []));
        $this->autosize($sheet);

        $writer = new Xlsx($spreadsheet);
        $tmp = tempnam(sys_get_temp_dir(), 'sam-report-').'.xlsx';
        $writer->save($tmp);
        $contents = (string) file_get_contents($tmp);
        @unlink($tmp);
        $spreadsheet->disconnectWorksheets();

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function writeMeta(Worksheet $sheet, array $snapshot, string $reportName, string $teamName): void
    {
        $sheet->setCellValue('A1', $reportName);
        $sheet->mergeCells('A1:E1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('A2', sprintf(
            'Tenant: %s | Type: %s | Generated: %s',
            $teamName,
            (string) ($snapshot['report_type'] ?? ''),
            (string) ($snapshot['generated_at'] ?? ''),
        ));
        $sheet->mergeCells('A2:E2');
        $sheet->getStyle('A2')->getFont()->setItalic(true);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }

    private function writeHeaders(Worksheet $sheet): void
    {
        foreach (self::HEADERS as $column => $label) {
            $sheet->setCellValue($column.'4', $label);
        }

        $headerStyle = $sheet->getStyle('A4:E4');
        $headerStyle->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1F2937');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeRows(Worksheet $sheet, array $rows): void
    {
        $rowIndex = 5;

        foreach ($rows as $row) {
            $sheet->setCellValue('A'.$rowIndex, (string) ($row['code'] ?? ''));
            $sheet->setCellValue('B'.$rowIndex, (string) ($row['period_start'] ?? ''));
            $sheet->setCellValue('C'.$rowIndex, (string) ($row['period_end'] ?? ''));
            $sheet->setCellValue('D'.$rowIndex, $row['value'] ?? null);
            $sheet->setCellValue('E'.$rowIndex, (string) ($row['unit'] ?? ''));
            $sheet->getStyle('D'.$rowIndex)->getNumberFormat()->setFormatCode('#,##0.0000');
            $rowIndex++;
        }
    }

    private function autosize(Worksheet $sheet): void
    {
        foreach (array_keys(self::HEADERS) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }
}
