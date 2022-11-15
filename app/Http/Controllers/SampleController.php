<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class SampleController extends Controller
{
    function index()
    {
        return view('login');
    }

    function registration()
    {
        return view('registration');
    }

    function validate_registration(Request $request)
    {
        $request->validate([
            'name'     => 'required',
            'email'    => 'required|email|unique:users',
            'password' => 'required|min:6'
        ]);

        $data = $request->all();

        User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
            'token'    => md5(uniqid())
        ]);

        return redirect('login')->with('success', 'Registration Completed, now you can login');
    }

    function validate_login(Request $request)
    {
        $request->validate([
            'email'    => 'required',
            'password' => 'required'
        ]);

        $credentials = $request->only('email', 'password');

        if(auth()->attempt($credentials))
        {
            $token = md5(uniqid());
            User::where('id', auth()->id())->update([ 'token' => $token ]);

            return redirect('dashboard')->with('success', 'Login success');
        }

        return redirect('login')->with('success', 'Login details are not valid');
    }

    function dashboard()
    {
        if(auth()->check())
        {
            return view('dashboard');
        }

        return redirect('login')->with('success', 'you are not allowed to access');
    }

    function logout()
    {
        session()->flush();
        auth()->logout();

        return Redirect('login');
    }

    public function profile()
    {
        if(auth()->check())
        {
            $data = User::where('id', auth()->id())->get();

            return view('profile', compact('data'));
        }

        return redirect("login")->with('success', 'you are not allowed to access');
    }

    public function profile_validation(Request $request)
    {
        $request->validate([
            'name'       => 'required',
            'email'      => 'required|email',
            'user_image' => 'image|mimes:jpg,png,jpeg|max:2048'
        ]);

        $user_image = $request->hidden_user_image;

        if($request->user_image != '')
        {
            $user_image = time() . '.' . $request->user_image->getClientOriginalExtension();
            $request->user_image->move(public_path('images'), $user_image);
        }

        $user = User::find(auth()->id());
        $user->name = $request->name;
        $user->email = $request->email;

        if($request->password != '')
        {
            $user->password = Hash::make($request->password);
        }

        $user->user_image = $user_image;
        $user->save();

        return redirect('profile')->with('success', 'Profile has been updated');
    }
}
