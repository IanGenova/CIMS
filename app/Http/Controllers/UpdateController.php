<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TestDbController;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

class UpdateController extends Controller
{

    public function viewStep1(Request $request)
    {
        $this->authorizeForUser($request->user(), 'update', Setting::class);

        return view('update.viewStep1');
    }

    public function lastStep(Request $request)
    {
        $this->authorizeForUser($request->user(), 'update', Setting::class);

        ini_set('max_execution_time', 600); //600 seconds = 10 minutes

        try {

            Artisan::call('config:cache');
            Artisan::call('config:clear');

            Artisan::call('migrate --force');

        } catch (\Exception $e) {
            // Logged, not shown: the message can carry database hosts and paths.
            report($e);

            return 'Something went wrong. The details are in storage/logs.';
        }
        
        return view('update.finishedUpdate');
    }

    

}
