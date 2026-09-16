<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Read-optimized aggregates. Heavy exports should be queued by callers. */
final class ReportService
{
    public function salesSummary(int $tenantId, string $from, string $to): array
    {
        $row = DB::table('sales_invoices')
            ->where('tenant_id', $tenantId)->where('status', 'final')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) as invoices, COALESCE(SUM(total),0) as revenue')
            ->first();

        return ['invoices' => (int) $row->invoices, 'revenue' => (float) $row->revenue];
    }

    public function salesByProduct(int $tenantId, string $from, string $to): array
    {
        return DB::table('sales_lines as sl')
            ->join('sales_invoices as si', 'si.id', '=', 'sl.sales_invoice_id')
            ->where('si.tenant_id', $tenantId)->where('si.status', 'final')
            ->whereBetween('si.created_at', [$from, $to])
            ->groupBy('sl.product_variant_id')
            ->selectRaw('sl.product_variant_id, SUM(sl.quantity) as qty, SUM(sl.quantity * sl.unit_price) as revenue')
            ->get()->all();
    }

    public function stockOnHand(int $tenantId): array
    {
        return DB::table('stock_movements')
            ->where('tenant_id', $tenantId)
            ->groupBy('warehouse_id', 'product_variant_id')
            ->selectRaw("warehouse_id, product_variant_id, SUM(CASE WHEN movement_type='in' THEN quantity ELSE -quantity END) as on_hand")
            ->get()->all();
    }

    public function lowStock(int $tenantId): array
    {
        return DB::table('products as p')
            ->where('p.tenant_id', $tenantId)
            ->selectRaw('p.id, p.name, p.alert_quantity')->get()->all();
    }

    public function purchaseSummary(int $tenantId, string $from, string $to): array
    {
        $row = DB::table('purchases')
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) as docs, COALESCE(SUM(total),0) as total')
            ->first();

        return ['docs' => (int) $row->docs, 'total' => (float) $row->total];
    }
}
