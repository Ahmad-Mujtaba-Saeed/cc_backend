<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', function () {
    return view('auth.login');
})->name('login');

Route::post('/login', function (Request $request) {
    $credentials = $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required'],
    ]);

    if (Auth::attempt($credentials, $request->boolean('remember'))) {
        $request->session()->regenerate();

        return redirect()->intended('/mixpost');
    }

    return back()->withErrors([
        'email' => 'The provided credentials do not match our records.',
    ])->onlyInput('email');
});

Route::post('/logout', function (Request $request) {
    Auth::logout();

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');

Route::get('/test-route', function () {
    return response()->json([
        'routes' => \Route::getRoutes()->getRoutes()
    ]);
});



Route::get('/migrate', function () {
    \Illuminate\Support\Facades\Artisan::call('migrate');
    return "Migrate Complete!";
});


Route::get('/seed', function () {
    \Illuminate\Support\Facades\Artisan::call('db:seed');
    return "Seed Complete!";
});
Route::get('/optimize-clear', function () {
    \Illuminate\Support\Facades\Artisan::call('optimize:clear');
    return "Optimize Clear Complete!";
});

Route::get('/storage-link', function () {
    if (file_exists(public_path('storage'))) {
        return 'Storage link already exists!';
    }

    \Illuminate\Support\Facades\Artisan::call('storage:link');
    return 'Storage link created successfully!';
});





Route::get('/logs', function () {
    $logFile = storage_path('logs/laravel.log');

    if (!File::exists($logFile)) {
        return response('Log file not found.', 404);
    }

    // Read last 500 lines for performance
    $lines = explode("\n", File::get($logFile));
    $lastLines = array_slice($lines, -500);

    return Response::make(
        nl2br(e(implode("\n", $lastLines))),
        200,
        ['Content-Type' => 'text/html']
    );
});


Route::get('/log-test', function () {
    Log::info('Hello from Laravel');
    return 'Done';
});

Route::get('/test-module', function () {
    return response()->json([
        'module_exists' => class_exists(\Modules\Billing\ModuleServiceProvider::class),
        'billing_routes' => file_exists(base_path('Modules/Billing/Routes/api.php'))
    ]);
});