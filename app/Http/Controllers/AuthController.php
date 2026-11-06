<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => 'The email and password do not match an account.']);
        }
        $request->session()->regenerate();

        $connected = DB::table('mailbox_connections')->where('user_id', $request->user()->id)->exists();
        $target = $connected ? '/' : '/#ConnectGmail';

        return redirect($request->header('X-Inertia') ? '/' : $target);
    }

    public function register(Request $request)
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:200', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);
        $user = User::create($input);
        Auth::login($user);
        $request->session()->regenerate();

        $request->session()->flash('notice', 'Your workspace is ready. Connect Gmail to import your emails.');

        return redirect($request->header('X-Inertia') ? '/' : '/#ConnectGmail');
    }
}
