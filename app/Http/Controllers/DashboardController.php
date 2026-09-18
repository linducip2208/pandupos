<?php

namespace App\Http\Controllers;

use App\Services\EntitlementService;
use App\Services\ReportService;
use App\Services\UsageLimitService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request, ReportService $reports, UsageLimitService $usage, EntitlementService $entitlements)
    {
        $tenant = TenantContext::get() ?? $request->user()->currentTenant;
        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        $role = $request->user()->getRoleNames()->first() ?? ($request->user()->is_platform_admin ? 'platform-admin' : 'tenant-user');
        $canViewFinancial = $request->user()->is_platform_admin || $request->user()->can('reports.view');
        $recentSales = $tenant && $canViewFinancial ? DB::table('sales_invoices')->where('tenant_id', $tenant->id)->latest()->limit(8)->get() : collect();
        $recentPurchases = $tenant && $canViewFinancial ? DB::table('purchases')->where('tenant_id', $tenant->id)->latest()->limit(8)->get() : collect();
        $todayPayments = $tenant && $canViewFinancial ? (float) DB::table('sale_payments')->where('tenant_id', $tenant->id)->whereDate('created_at', today())->sum('amount') : 0;
        $pendingApprovals = $tenant ? DB::table('approval_requests')->where('tenant_id', $tenant->id)->where('status', 'pending')->count() : 0;
        $dailySales = $tenant && $canViewFinancial ? DB::table('sales_invoices')->where('tenant_id', $tenant->id)->where('status', 'final')->where('created_at', '>=', now()->subDays(6)->startOfDay())->get()->groupBy(fn ($row) => substr($row->created_at, 0, 10))->map(fn ($items) => (float) $items->sum('total')) : collect();
        $roleMetrics = $tenant ? $this->roleMetrics($tenant->id, $role, $todayPayments, $pendingApprovals, $reports, $canViewFinancial) : [];

        return view('dashboard', [
            'tenant' => $tenant,
            'sales' => $tenant && $canViewFinancial ? $reports->salesSummary($tenant->id, $from, $to) : [],
            'usage' => $tenant ? $usage->snapshot($tenant->id) : [],
            'subscription' => $tenant ? $tenant->activeSubscription : null,
            'plan' => $tenant?->activeSubscription?->plan,
            'role' => $role,
            'canViewFinancial' => $canViewFinancial,
            'recentSales' => $recentSales,
            'recentPurchases' => $recentPurchases,
            'todayPayments' => $todayPayments,
            'pendingApprovals' => $pendingApprovals,
            'lowStock' => $tenant ? collect($reports->lowStock($tenant->id))->take(8) : collect(),
            'chart' => ['labels' => $dailySales->keys()->values(), 'values' => $dailySales->values()],
            'roleMetrics' => $roleMetrics,
        ]);
    }

    private function roleMetrics(int $tenantId, string $role, float $todayPayments, int $pendingApprovals, ReportService $reports, bool $canViewFinancial): array
    {
        $salesToday = DB::table('sales_invoices')->where('tenant_id', $tenantId)->whereDate('created_at', today());
        $salesCount = (clone $salesToday)->count();
        $salesTotal = $canViewFinancial ? (float) (clone $salesToday)->where('status', 'final')->sum('total') : 0;

        return match ($role) {
            'cashier' => $canViewFinancial ? [['Transaksi hari ini', $salesCount], ['Pembayaran diterima', 'Rp '.number_format($todayPayments, 0, ',', '.')], ['Rata-rata tiket', 'Rp '.number_format($salesCount ? $salesTotal / $salesCount : 0, 0, ',', '.')]] : [['Transaksi hari ini', $salesCount]],
            'warehouse' => [['Produk perlu perhatian', count($reports->lowStock($tenantId))], ['Mutasi hari ini', DB::table('stock_movements')->where('tenant_id', $tenantId)->whereDate('occurred_at', today())->count()], ['Gudang aktif', DB::table('warehouses')->where('tenant_id', $tenantId)->where('is_active', true)->count()]],
            'purchasing' => [['PO terbuka', DB::table('purchases')->where('tenant_id', $tenantId)->whereIn('status', ['draft', 'ordered', 'partial'])->count()], ['Pemasok aktif', DB::table('contacts')->where('tenant_id', $tenantId)->whereIn('type', ['supplier', 'both'])->count()]],
            'sales' => $canViewFinancial ? [['Invoice hari ini', $salesCount], ['Omzet hari ini', 'Rp '.number_format($salesTotal, 0, ',', '.')], ['Belum lunas', DB::table('sales_invoices')->where('tenant_id', $tenantId)->whereIn('payment_status', ['unpaid', 'partial'])->count()]] : [['Invoice hari ini', $salesCount]],
            default => [['Approval tertunda', $pendingApprovals], ['Produk perlu perhatian', count($reports->lowStock($tenantId))], ['Cabang aktif', DB::table('branches')->where('tenant_id', $tenantId)->where('is_active', true)->count()]],
        };
    }
}
