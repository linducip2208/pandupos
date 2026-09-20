<?php

namespace App\Http\Controllers;

use App\Models\ProductVariant;
use App\Models\WoConnection;
use App\Models\WoSyncLog;
use App\Services\ModuleRegistry;
use App\Services\WooSyncService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class WooWorkspaceController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', WoConnection::class);

        return view('woo.index', [
            'connections' => WoConnection::query()->withCount(['links', 'logs'])->orderByDesc('id')->get(),
            'logs' => WoSyncLog::query()->orderByDesc('id')->limit(100)->get(),
        ]);
    }

    public function store(Request $request, WooSyncService $sync)
    {
        $this->authorize('create', WoConnection::class);
        $data = $request->validate([
            'name' => 'required|string|max:128', 'store_url' => 'required|url|max:255',
            'consumer_key' => 'required|string', 'consumer_secret' => 'required|string',
        ]);
        // Keys are encrypted at rest; never echoed back or logged.
        $sync->createConnection(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Koneksi WooCommerce disimpan (kredensial terenkripsi).');
    }

    public function sync(Request $request, WooSyncService $sync, ModuleRegistry $modules, int $connection)
    {
        $conn = WoConnection::query()->findOrFail($connection);
        $this->authorize('manage', $conn);
        $data = $request->validate(['job' => 'required|in:products,inventory,orders,customers']);
        // Live sync uses real HTTP credentials; failures flip the connection to error.
        try {
            $done = match ($data['job']) {
                'products' => $this->pushAllProducts($conn, $sync),
                'inventory' => $sync->pushInventory($conn, null, $request->user()->id),
                'orders' => $sync->pullOrders($conn, null, $request->user()->id),
                'customers' => $sync->pullCustomers($conn, null, $request->user()->id),
            };
        } catch (\Throwable $e) {
            return back()->withErrors(['sync' => 'Sinkronisasi gagal: '.$e->getMessage()]);
        }

        return back()->with('status', 'Sinkronisasi '.$data['job'].': '.$done.' diproses.');
    }

    private function pushAllProducts(WoConnection $conn, WooSyncService $sync): int
    {
        $count = 0;
        foreach (ProductVariant::query()->limit(200)->get() as $variant) {
            $sync->pushProduct($conn, $variant->id);
            $count++;
        }

        return $count;
    }
}
