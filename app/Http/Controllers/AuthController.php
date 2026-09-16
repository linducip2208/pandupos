<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login()
    {
        return view('auth.login');
    }

    public function attempt(Request $request)
    {
        $cred = $request->validate(['email' => 'required|email', 'password' => 'required|string']);

        if (! Auth::attempt($cred, true)) {
            return back()->withErrors(['email' => 'Email/password salah.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
