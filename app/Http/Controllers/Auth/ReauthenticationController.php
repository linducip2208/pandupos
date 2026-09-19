<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class ReauthenticationController extends Controller
{
    public function show(Request $request): View
    {
        if ($request->session()->has('impersonating')) {
            abort(403, 'Re-authentication is disabled while impersonating.');
        }

        return view('auth.confirm-sensitive');
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        if ($request->session()->has('impersonating')) {
            abort(403, 'Re-authentication is disabled while impersonating.');
        }

        $data = $request->validate(['password' => 'required|string']);
        $user = $request->user();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['password' => 'Password yang dimasukkan salah.']);
        }

        $request->session()->put('sensitive_auth_at', time());

        // The secret itself is never persisted: only the confirmation event.
        $audit->log(null, $user->id, 'auth.sensitive_reconfirmed', null, null, null, ['confirmed_via' => 'password']);

        return redirect()->intended('/platform/dashboard')->with('status', 'Identitas terkonfirmasi. Lanjutkan tindakan sensitif.');
    }
}
