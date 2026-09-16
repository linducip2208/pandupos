<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportPageController extends Controller
{
    public function show(Request $request, string $type, ReportService $reports)
    {
        $data = $this->data($request, $type, $reports);

        return view('reports.show', $data);
    }

    public function csv(Request $request, string $type, ReportService $reports)
    {
        $data = $this->data($request, $type, $reports);

        return response()->streamDownload(function () use ($data) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $data['columns']);
            foreach ($data['rows'] as $row) {
                fputcsv($stream, $row);
            }
            fclose($stream);
        }, 'laporan-'.$type.'-'.$data['from'].'-'.$data['to'].'.csv', ['Content-Type' => 'text/csv']);
    }

    public function pdf(Request $request, string $type, ReportService $reports)
    {
        $data = $this->data($request, $type, $reports);
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('reports.pdf', $data)->render());
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="laporan-'.$type.'-'.$data['from'].'-'.$data['to'].'.pdf"',
        ]);
    }

    public function xlsx(Request $request, string $type, ReportService $reports)
    {
        $data = $this->data($request, $type, $reports);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Laporan');

        $lastColumn = Coordinate::stringFromColumnIndex(max(count($data['columns']), 2));
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->setCellValue('A1', $data['title']);
        $sheet->setCellValue('A2', 'Tenant');
        $sheet->setCellValue('B2', $data['tenant']->name);
        $sheet->setCellValue('A3', 'Periode');
        $sheet->setCellValue('B3', $data['from'].' s.d. '.$data['to']);
        $sheet->setCellValue('A4', 'Kelompok');
        $sheet->setCellValue('B4', match ($data['group']) {
            'weekly' => 'Mingguan',
            'monthly' => 'Bulanan',
            default => 'Harian',
        });
        $sheet->setCellValue('A6', 'Ringkasan');
        $sheet->setCellValue('B6', 'Nilai');

        $summaryRow = 7;
        foreach ($data['summary'] as $label => $value) {
            $sheet->setCellValue("A{$summaryRow}", $label);
            $sheet->setCellValue("B{$summaryRow}", is_numeric($value) ? (float) $value : $value);
            $summaryRow++;
        }

        $headerRow = $summaryRow + 1;
        foreach ($data['columns'] as $index => $column) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).$headerRow, $column);
        }

        $detailRow = $headerRow + 1;
        foreach ($data['rows'] as $row) {
            foreach ($row as $index => $value) {
                $sheet->setCellValue(
                    Coordinate::stringFromColumnIndex($index + 1).$detailRow,
                    is_numeric($value) ? (float) $value : $value,
                );
            }
            $detailRow++;
        }

        $darkGreen = '12372A';
        $lime = 'D9F99D';
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 16],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $darkGreen]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);
        foreach (['A6:B6', "A{$headerRow}:{$lastColumn}{$headerRow}"] as $range) {
            $sheet->getStyle($range)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => $darkGreen]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $lime]],
                'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $darkGreen]]],
            ]);
        }
        $sheet->getStyle('B7:B'.($summaryRow - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        if ($detailRow > $headerRow + 1) {
            $sheet->getStyle("A{$headerRow}:{$lastColumn}".($detailRow - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_HAIR);
            $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}".($detailRow - 1));
        }
        foreach (range(1, max(count($data['columns']), 2)) as $columnIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
        }
        $sheet->freezePane('A'.($headerRow + 1));
        $sheet->setSelectedCell('A1');
        $spreadsheet->getProperties()
            ->setCreator(config('app.name'))
            ->setTitle($data['title'])
            ->setDescription('Laporan PanduPOS yang dapat diolah lebih lanjut.');

        $temporaryFile = tempnam(sys_get_temp_dir(), 'pandupos-xlsx-');
        abort_if($temporaryFile === false, 500, 'Gagal menyiapkan berkas laporan.');
        (new Xlsx($spreadsheet))->save($temporaryFile);
        $spreadsheet->disconnectWorksheets();

        return response()->download(
            $temporaryFile,
            'laporan-'.$type.'-'.$data['from'].'-'.$data['to'].'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
    }

    private function data(Request $request, string $type, ReportService $reports): array
    {
        abort_unless(in_array($type, ['bisnis', 'keuangan', 'operasional'], true), 404);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'group' => ['nullable', Rule::in(['daily', 'weekly', 'monthly'])],
        ]);
        $tenant = TenantContext::get() ?? $request->user()->currentTenant;
        abort_unless($tenant, 404);
        $from = Carbon::parse($filters['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now())->endOfDay();
        abort_if($from->diffInDays($to) > 731, 422, 'Rentang laporan maksimal dua tahun.');
        $group = $filters['group'] ?? 'daily';

        return match ($type) {
            'bisnis' => $this->business($tenant->id, $from, $to, $group, $reports),
            'keuangan' => $this->finance($tenant->id, $from, $to, $group, $reports),
            'operasional' => $this->operations($tenant->id, $from, $to, $group, $reports),
        } + compact('type', 'tenant', 'group') + ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    private function business(int $tenantId, Carbon $from, Carbon $to, string $group, ReportService $reports): array
    {
        $invoices = DB::table('sales_invoices')->where('tenant_id', $tenantId)->whereBetween('created_at', [$from, $to])->orderByDesc('created_at')->get();
        $summary = $reports->salesSummary($tenantId, $from->toDateTimeString(), $to->toDateTimeString());
        $paid = (float) $invoices->where('payment_status', 'paid')->sum('total');
        $average = $summary['invoices'] ? $summary['revenue'] / $summary['invoices'] : 0;

        return [
            'title' => 'Laporan Bisnis Utama',
            'summary' => ['Omzet' => $summary['revenue'], 'Invoice' => $summary['invoices'], 'Rata-rata transaksi' => $average, 'Lunas' => $paid],
            'chart' => $this->timeline($invoices, $group, 'total'),
            'columns' => ['Invoice', 'Tanggal', 'Status dokumen', 'Status bayar', 'Total'],
            'rows' => $invoices->map(fn ($row) => [$row->invoice_no ?: '#'.$row->id, $row->created_at, $row->status, $row->payment_status, (float) $row->total]),
        ];
    }

    private function finance(int $tenantId, Carbon $from, Carbon $to, string $group, ReportService $reports): array
    {
        $profit = $reports->profit($tenantId, $from->toDateTimeString(), $to->toDateTimeString());
        $purchases = DB::table('purchases')->where('tenant_id', $tenantId)->whereBetween('created_at', [$from, $to])->orderByDesc('created_at')->get();
        $purchaseTotal = (float) $purchases->sum('total');
        $margin = $profit['revenue'] > 0 ? ($profit['gross_profit'] / $profit['revenue']) * 100 : 0;

        return [
            'title' => 'Laporan Keuangan',
            'summary' => ['Pendapatan' => $profit['revenue'], 'COGS' => $profit['cogs'], 'Laba kotor' => $profit['gross_profit'], 'Margin kotor (%)' => $margin, 'Nilai pembelian' => $purchaseTotal],
            'chart' => $this->timeline($purchases, $group, 'total'),
            'columns' => ['Referensi', 'Tanggal', 'Status', 'Nilai pembelian'],
            'rows' => $purchases->map(fn ($row) => [$row->ref_no ?: '#'.$row->id, $row->created_at, $row->status, (float) $row->total]),
        ];
    }

    private function operations(int $tenantId, Carbon $from, Carbon $to, string $group, ReportService $reports): array
    {
        $movements = DB::table('stock_movements')->where('tenant_id', $tenantId)->whereBetween('occurred_at', [$from, $to])->orderByDesc('occurred_at')->limit(500)->get();
        $incoming = (float) $movements->where('movement_type', 'in')->sum('quantity');
        $outgoing = (float) $movements->where('movement_type', 'out')->sum('quantity');

        return [
            'title' => 'Laporan Operasional',
            'summary' => ['Stok masuk' => $incoming, 'Stok keluar' => $outgoing, 'Mutasi' => $movements->count(), 'Valuasi stok' => $reports->stockValuation($tenantId)],
            'chart' => $this->timeline($movements, $group, 'quantity', 'occurred_at'),
            'columns' => ['Tanggal', 'Gudang', 'Varian', 'Tipe', 'Jumlah', 'Biaya unit'],
            'rows' => $movements->map(fn ($row) => [$row->occurred_at, $row->warehouse_id, $row->product_variant_id, $row->movement_type, (float) $row->quantity, (float) $row->unit_cost]),
        ];
    }

    private function timeline(Collection $rows, string $group, string $value, string $dateField = 'created_at'): array
    {
        $format = match ($group) {
            'weekly' => 'o-\\WW',
            'monthly' => 'Y-m',
            default => 'Y-m-d',
        };
        $series = $rows->groupBy(fn ($row) => Carbon::parse($row->{$dateField})->format($format))->map(fn ($items) => round((float) $items->sum($value), 2))->sortKeys();

        return ['labels' => $series->keys()->values(), 'values' => $series->values()];
    }
}
