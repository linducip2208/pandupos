<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Read-optimized aggregates. Heavy exports should be queued by callers. */
final class ReportService
{
    public function salesSummary(int $tenantId, string $from, string $to, ?int $branchId = null, ?int $warehouseId = null): array
    {
        $row = DB::table('sales_invoices')
            ->where('tenant_id', $tenantId)->where('status', 'final')
            ->whereBetween('created_at', [$from, $to])
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
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

    /**
     * Trustworthy profit: revenue from final invoices, COGS from stock out movements
     * (weighted-average unit_cost), never selling-price-only. Gross = revenue - COGS.
     */
    public function profit(int $tenantId, string $from, string $to, ?int $warehouseId = null): array
    {
        $revenue = (float) DB::table('sales_invoices')
            ->where('tenant_id', $tenantId)->where('status', 'final')
            ->whereBetween('created_at', [$from, $to])
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))->sum('total');

        $cogs = (float) DB::table('stock_movements as sm')
            ->join('sales_invoices as si', function ($j) {
                $j->on('si.id', '=', 'sm.reference_id')->where('sm.reference_type', '=', 'sale');
            })
            ->where('sm.tenant_id', $tenantId)->where('sm.movement_type', 'out')
            ->where('si.status', 'final')
            ->whereBetween('si.created_at', [$from, $to])
            ->when($warehouseId !== null, fn ($query) => $query->where('sm.warehouse_id', $warehouseId))
            ->selectRaw('COALESCE(SUM(sm.quantity * sm.unit_cost),0) as c')->value('c');

        return ['revenue' => $revenue, 'cogs' => $cogs, 'gross_profit' => round($revenue - $cogs, 2)];
    }

    public function stockValuation(int $tenantId, ?int $warehouseId = null): float
    {
        return app(StockService::class)->valuation($tenantId, $warehouseId);
    }
}
