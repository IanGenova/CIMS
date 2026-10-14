<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SetupController extends Controller
{
    public function changeEnv(array $data = [])
    {
        if (!$data) {
            return false;
        }

        $path = base_path('.env');
        $contents = file_exists($path) ? file_get_contents($path) : '';
        $lines = preg_split('/\R/', $contents);
        $seen = [];

        foreach ($lines as $index => $line) {
            if (strpos($line, '=') === false) {
                continue;
            }

            [$key] = explode('=', $line, 2);
            if (array_key_exists($key, $data)) {
                $lines[$index] = $key . '=' . ($data[$key] ?? '');
                $seen[$key] = true;
            }
        }

        foreach ($data as $key => $value) {
            if (!isset($seen[$key])) {
                $lines[] = $key . '=' . ($value ?? '');
            }
        }

        return file_put_contents($path, rtrim(implode(PHP_EOL, $lines)) . PHP_EOL) !== false;
    }

    public function viewStep1()
    {
        $data = [
            'APP_NAME' => session('env.APP_NAME') ? trim(session('env.APP_NAME'), '"') : trim(config('app.name'), '"'),
            'APP_ENV' => session('env.APP_ENV', config('app.env')),
            'APP_DEBUG' => session('env.APP_DEBUG', config('app.debug')),
            'APP_KEY' => session('env.APP_KEY', config('app.key')),
        ];

        return view('setup.step1', compact('data'));
    }

    public function viewCheck()
    {
        return view('setup.check');
    }

    public function viewStep2()
    {
        $db = config('database.connections.' . config('database.default'), []);
        $data = [
            'DB_CONNECTION' => session('env.DB_CONNECTION', config('database.default')),
            'DB_HOST' => session('env.DB_HOST', $db['host'] ?? ''),
            'DB_PORT' => session('env.DB_PORT', $db['port'] ?? ''),
            'DB_DATABASE' => session('env.DB_DATABASE', $db['database'] ?? ''),
            'DB_USERNAME' => session('env.DB_USERNAME', $db['username'] ?? ''),
            'DB_PASSWORD' => trim((string) session('env.DB_PASSWORD', $db['password'] ?? ''), '"'),
        ];

        return view('setup.step2', compact('data'));
    }

    public function viewStep3()
    {
        $connection = session('env.DB_CONNECTION', config('database.default'));
        $db = config('database.connections.' . $connection, []);

        $data = [
            'APP_NAME' => trim((string) session('env.APP_NAME'), '"') === trim((string) config('app.name'), '"') ? 'old' : trim((string) session('env.APP_NAME'), '"'),
            'APP_ENV' => session('env.APP_ENV') === config('app.env') ? 'old' : session('env.APP_ENV'),
            'APP_DEBUG' => session('env.APP_DEBUG') == config('app.debug') ? 'old' : session('env.APP_DEBUG'),
            'APP_KEY' => session('env.APP_KEY') === config('app.key') ? 'old' : session('env.APP_KEY'),
            'DB_CONNECTION' => $connection === config('database.default') ? 'old' : $connection,
            'DB_HOST' => session('env.DB_HOST') === ($db['host'] ?? '') ? 'old' : session('env.DB_HOST'),
            'DB_PORT' => session('env.DB_PORT') == ($db['port'] ?? '') ? 'old' : session('env.DB_PORT'),
            'DB_DATABASE' => session('env.DB_DATABASE') === ($db['database'] ?? '') ? 'old' : session('env.DB_DATABASE'),
            'DB_USERNAME' => session('env.DB_USERNAME') === ($db['username'] ?? '') ? 'old' : session('env.DB_USERNAME'),
            'DB_PASSWORD' => trim((string) session('env.DB_PASSWORD'), '"') === trim((string) ($db['password'] ?? ''), '"') ? 'old' : trim((string) session('env.DB_PASSWORD'), '"'),
        ];

        return view('setup.step3', compact('data'));
    }

    public function lastStep(Request $request)
    {
        ini_set('max_execution_time', '600');

        try {
            $this->changeEnv([
                'APP_NAME' => session('env.APP_NAME'),
                'APP_ENV' => session('env.APP_ENV'),
                'APP_KEY' => session('env.APP_KEY'),
                'APP_DEBUG' => session('env.APP_DEBUG'),
                'APP_URL' => session('env.APP_URL', url('/')),
                'LOG_CHANNEL' => session('env.LOG_CHANNEL', 'stack'),
                'DB_CONNECTION' => session('env.DB_CONNECTION'),
                'DB_HOST' => session('env.DB_HOST'),
                'DB_PORT' => session('env.DB_PORT'),
                'DB_DATABASE' => session('env.DB_DATABASE'),
                'DB_USERNAME' => session('env.DB_USERNAME'),
                'DB_PASSWORD' => session('env.DB_PASSWORD'),
            ]);

            Artisan::call('config:clear');
            Artisan::call('migrate:fresh', ['--force' => true, '--seed' => true]);
            Artisan::call('passport:install', ['--force' => true]);
            Artisan::call('storage:link');
            Artisan::call('config:cache');

            Storage::disk('public')->put('installed', 'Contents');
        } catch (\Throwable $e) {
            Log::error('CIMS installer failed', ['exception' => $e]);

            return back()->withErrors([
                'setup' => 'Installation failed. Check storage/logs/laravel.log for details.',
            ]);
        }

        return view('setup.finishedSetup');
    }

    public function getNewAppKey()
    {
        Artisan::call('key:generate', ['--show' => true]);
        return trim(Artisan::output());
    }

    public function setupStep1(Request $request)
    {
        $validated = $request->validate([
            'app_name' => 'required|string|max:100',
            'app_env' => 'required|in:local,production,staging,testing',
            'app_debug' => 'required|in:true,false,0,1',
            'app_key' => 'required|string',
        ]);

        $request->session()->put('env.APP_NAME', '"' . str_replace('"', '', $validated['app_name']) . '"');
        $request->session()->put('env.APP_ENV', $validated['app_env']);
        $request->session()->put('env.APP_DEBUG', $validated['app_debug']);
        $request->session()->put('env.APP_KEY', $validated['app_key']);
        $request->session()->put('env.APP_URL', url('/'));
        $request->session()->put('env.LOG_CHANNEL', 'stack');

        return $this->viewStep2();
    }

    public function setupStep2(Request $request)
    {
        $validated = $request->validate([
            'db_connection' => 'required|in:mysql',
            'db_host' => 'required|string',
            'db_port' => 'required|integer|min:1|max:65535',
            'db_database' => 'required|string|max:64',
            'db_username' => 'required|string|max:128',
            'db_password' => 'nullable|string',
        ]);

        foreach (['connection', 'host', 'port', 'database', 'username'] as $field) {
            $request->session()->put('env.DB_' . strtoupper($field), $validated['db_' . $field]);
        }
        $request->session()->put('env.DB_PASSWORD', '"' . str_replace('"', '\"', (string) ($validated['db_password'] ?? '')) . '"');

        return $this->viewStep3();
    }
}
