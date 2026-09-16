<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnnouncementController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePlatform($request, 'platform.announcements.manage');
        $announcements = Announcement::orderByDesc('id')->paginate(15);

        return view('platform.announcements.index', compact('announcements'));
    }

    public function store(Request $request)
    {
        $this->authorizePlatform($request, 'platform.announcements.manage');
        $data = $request->validate([
            'subject' => 'required|string|max:255', 'body' => 'required|string',
            'audience' => 'required|string|max:64', 'channel' => 'nullable|in:in-app,email',
        ]);
        $ann = Announcement::create($data + ['channel' => $data['channel'] ?? 'in-app']);

        return back()->with('status', "Announcement #{$ann->id} created. Use Send to queue deliveries.");
    }

    public function send(Request $request, Announcement $announcement)
    {
        $this->authorizePlatform($request, 'platform.announcements.manage');
        $q = Tenant::query();
        match ($announcement->audience) {
            'trial' => $q->where('status', 'trial'),
            'expired' => $q->whereIn('status', ['cancelled', 'archived']),
            'suspended' => $q->where('status', 'suspended'),
            default => $q->when(str_starts_with($announcement->audience, 'plan:'), function ($qq) use ($announcement) {
                $slug = substr($announcement->audience, 5);
                $qq->whereHas('activeSubscription.plan', fn ($p) => $p->where('slug', $slug));
            }),
        };

        $count = 0;
        $q->chunkById(200, function ($tenants) use ($announcement, &$count) {
            foreach ($tenants as $t) {
                DB::table('announcement_deliveries')->updateOrInsert(
                    ['announcement_id' => $announcement->id, 'tenant_id' => $t->id],
                    ['status' => 'queued', 'updated_at' => now(), 'created_at' => now()]
                );
                $count++;
            }
        });
        $announcement->update(['sent_at' => now()]);

        return back()->with('status', "Queued {$count} deliveries.");
    }

    protected function authorizePlatform(Request $request, string $permission): void
    {
        if ($request->user()->is_platform_admin) {
            return;
        }
        abort_unless($request->user()->can($permission), 403);
    }
}
