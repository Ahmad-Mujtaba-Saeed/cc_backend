<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f3f4f6; color: #111827; margin: 0; padding: 0; }
        .container { max-width: 420px; margin: 6rem auto; padding: 2rem; background: white; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,.08); }
        h1 { margin-bottom: 1rem; font-size: 1.75rem; }
        label { display: block; margin-bottom: 0.35rem; font-weight: 600; }
        input { width: 100%; padding: 0.75rem 0.85rem; margin-bottom: 1rem; border: 1px solid #d1d5db; border-radius: 0.5rem; }
        button { width: 100%; padding: 0.85rem; border: none; border-radius: 0.6rem; background: #1f2937; color: white; font-size: 1rem; cursor: pointer; }
        .errors { margin-bottom: 1rem; color: #b91c1c; }
        .links { margin-top: 1rem; font-size: 0.9rem; color: #4b5563; }
        .links a { color: #1d4ed8; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Login</h1>

        @if ($errors->any())
            <div class="errors">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ url('/login') }}">
            @csrf

            <div>
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
            </div>

            <div>
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required>
            </div>

            <div>
                <label>
                    <input type="checkbox" name="remember" value="1"> Remember me
                </label>
            </div>

            <button type="submit">Sign in</button>
        </form>

        <div class="links">
            <p>If you already use the React SPA, login there first and then visit <a href="/mixpost">Mixpost</a>.</p>
        </div>
    </div>
</body>
</html>
