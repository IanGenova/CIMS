<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Server;
use App\Models\Setting;
use App\Models\PosSetting;
use App\Models\Client;
use App\Models\Warehouse;
use App\utils\XenditCheckout;
use App\utils\XenditException;
use File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\Request;
use Intervention\Image\ImageManagerStatic as Image;

class SettingsController extends Controller
{


    //-------------- Update  Settings ---------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);

        $request->validate([
            'email' => 'nullable|email|max:255',
            'CompanyName' => 'required|string|max:255',
            'CompanyPhone' => 'nullable|string|max:50',
            'CompanyAdress' => 'nullable|string|max:500',
            'logo' => 'nullable',
            'purchase_approval_threshold' => 'nullable|numeric|min:0',
        ]);

        $setting = Setting::findOrFail($id);
        $currentAvatar = $setting->logo ?: 'logo-default.png';
        $filename = $currentAvatar;

        if ($request->hasFile('logo')) {
            $request->validate(['logo' => 'image|mimes:jpeg,jpg,png,webp|max:2048']);
            $image = $request->file('logo');
            $path = public_path('images');
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }

            $filename = uniqid('logo_', true) . '.' . $image->getClientOriginalExtension();
            Image::make($image->getRealPath())
                ->fit(80, 80, function ($constraint) {
                    $constraint->upsize();
                })
                ->save($path . DIRECTORY_SEPARATOR . $filename);

            $oldLogo = $path . DIRECTORY_SEPARATOR . $currentAvatar;
            if ($currentAvatar !== 'logo-default.png' && is_file($oldLogo)) {
                @unlink($oldLogo);
            }
        }
        if ($request['currency'] != 'null') {
            $currency = $request['currency'];
        } else {
            $currency = null;
        }

        if ($request['client'] != 'null') {
            $client = $request['client'];
        } else {
            $client = null;
        }

        if ($request['warehouse'] != 'null') {
            $warehouse = $request['warehouse'];
        } else {
            $warehouse = null;
        }

        if ($request['default_language'] != 'null') {
            $default_language = $request['default_language'];
        } else {
            $default_language = 'en';
        }
        Setting::whereId($id)->update([
            'currency_id' => $currency,
            'client_id' => $client,
            'warehouse_id' => $warehouse,
            'email' => $request['email'],
            'default_language' =>  $default_language,
            'CompanyName' => $request['CompanyName'],
            'CompanyPhone' => $request['CompanyPhone'],
            'CompanyAdress' => $request['CompanyAdress'],
            'footer' => $request['footer'],
            'developed_by' => $request['developed_by'],
            'logo' => $filename,
        ]);

        // Purchases at or above this need a second person's approval; blank
        // turns approval off. Only touched when the screen sends it, so an
        // older client saving the rest of the page cannot wipe it.
        if ($request->exists('purchase_approval_threshold')) {
            $limit = $request['purchase_approval_threshold'];
            Setting::whereId($id)->update([
                'purchase_approval_threshold' => ($limit === null || $limit === '' || $limit === 'null') ? null : round((float) $limit, 2),
            ]);
        }

        return response()->json(['success' => true]);
    }

      //-------------- Update  Payment Gateway ---------------\\

      public function Update_payment_gateway(Request $request)
      {
          $this->authorizeForUser($request->user('api'), 'update', Setting::class);

          // Xendit. The keys go into the settings row, encrypted -- not into
          // .env. The Stripe version rewrote .env and ran config:cache on every
          // save, which froze whatever environment it ran in; run from the test
          // suite it once pointed the live system at the test database.
          $request->validate([
              'secret_key' => ['nullable', 'string', 'max:200', 'regex:/^xnd_(development|production)_[A-Za-z0-9]+$/'],
              'webhook_token' => 'nullable|string|max:200',
          ], [
              'secret_key.regex' => 'That does not look like a Xendit secret key. It starts with xnd_development_ or xnd_production_.',
          ]);

          XenditCheckout::saveKeys($request['secret_key'], $request['webhook_token'], $request->boolean('remove'));

          return response()->json(['success' => true, 'gateway' => XenditCheckout::describe()]);
      }

      //-------------- Test the Payment Gateway ---------------\\

      public function Test_payment_gateway(Request $request)
      {
          $this->authorizeForUser($request->user('api'), 'update', Setting::class);

          try {
              return response()->json(['message' => XenditCheckout::testConnection()]);
          } catch (XenditException $e) {
              return response()->json(['message' => $e->getMessage()], 422);
          }
      }

       //-------------- Update  sms_config ---------------\\

       public function sms_config(Request $request)
       {
           $this->authorizeForUser($request->user('api'), 'update', Setting::class);
 
           
             $this->setEnvironmentValue([
                 'TWILIO_SID' => $request['TWILIO_SID'] !== null?'"' . $request['TWILIO_SID'] . '"':'"' . env('TWILIO_SID') . '"',
                 'TWILIO_TOKEN' => $request['TWILIO_TOKEN'] !== null?'"' . $request['TWILIO_TOKEN'] . '"':'"' . env('TWILIO_TOKEN') . '"',
                 'TWILIO_FROM' => $request['TWILIO_FROM'] !== null?'"' . $request['TWILIO_FROM'] . '"':'"' . env('TWILIO_FROM') . '"',
             ]);
 
             // Clear only. Building the cache here would freeze the config of
             // whatever environment this runs in -- the test suite included.
             Artisan::call('config:clear');

         return response()->json(['success' => true]);
 
       }

    //-------------- Get_sms_config ---------------\\

    public function get_sms_config(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Setting::class);

        $item['TWILIO_SID'] = env('TWILIO_SID');
        $item['TWILIO_FROM'] = env('TWILIO_FROM');
        $item['TWILIO_TOKEN'] = '';
        $item['gateway'] = 'Twilio';

        return response()->json(['sms' => $item], 200);
    }
 

    //-------------- Get Payment Gateway ---------------\\

    public function Get_payment_gateway(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Setting::class);

        // Whether it is set up, and the last four characters -- never the keys.
        return response()->json(['gateway' => XenditCheckout::describe()], 200);
    }

    
  


    //-------------- Create SMTP ---------------\\

    public function CreateSMTP(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Server::class);

        $validated = $request->validate([
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'username' => 'required|string|max:255',
            'password' => 'required|string|max:255',
            'encryption' => 'required|in:ssl,tls',
        ]);

        $server = Server::create($validated);

        return response()->json(['success' => true, 'server' => $server], 201);
    }

    //-------------- Update  SMTP ---------------\\

    public function updateSMTP(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Server::class);

        $request->validate([
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'username' => 'required|string|max:255',
            // Blank keeps the saved password: the screen is never sent it.
            'password' => 'nullable|string|max:255',
            'encryption' => 'required|in:ssl,tls',
        ]);

        $server = Server::findOrFail($id);
        $server->fill($request->only(['host', 'port', 'username', 'encryption']));
        if ($request->filled('password')) {
            $server->password = $request['password'];   // encrypted by the model
        }
        $server->save();

        return response()->json(['success' => true]);

    }


     //-------------- Update Pos settings ---------------\\

     public function update_pos_settings(Request $request, $id)
     {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);
 
        request()->validate([
            'note_customer' => 'required',
        ]);

        PosSetting::whereId($id)->update([
             'note_customer'  => $request['note_customer'],
             'show_note'      => $request['show_note'],
             'show_barcode'   => $request['show_barcode'],
             'show_discount'  => $request['show_discount'],
             'show_customer'  => $request['show_customer'],
             'show_email'     => $request['show_email'],
             'show_phone'     => $request['show_phone'],
             'show_address'   => $request['show_address'],
             // Receipt printing. The paper size is validated against the sizes
             // the stylesheet knows how to lay out, so a typo cannot produce a
             // slip that prints half off the roll.
             'paper_size'     => in_array($request['paper_size'], ['58mm', '80mm', 'a4'], true)
                                    ? $request['paper_size'] : '80mm',
             'auto_print'     => (bool) $request['auto_print'],
             'printer_name'   => $request['printer_name'] ?: null,
             'open_cash_drawer' => (bool) $request['open_cash_drawer'],
             // Pin 2 or pin 5. Anything else and the pulse goes nowhere.
             'drawer_pin'     => ((int) $request['drawer_pin']) === 1 ? 1 : 0,
             'auto_cut'       => (bool) $request['auto_cut'],
             // With this on the till will not sell without an open drawer.
             // A sale rung up outside a session cannot be reconciled to any
             // drawer afterwards, which is the whole reason the session exists.
             'require_till_session' => (bool) $request['require_till_session'],
         ]);
 
         return response()->json(['success' => true]);
 
     }


     //-------------- Get Pos Settings ---------------\\

     public function get_pos_Settings(Request $request)
     {
         $this->authorizeForUser($request->user('api'), 'view', Setting::class);
 
         $PosSetting = PosSetting::where('deleted_at', '=', null)->first();

         return response()->json([
             'pos_settings' => $PosSetting
            ], 200);
    
    }

    //-------------- Get All Settings ---------------\\

    public function getSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Setting::class);

        $settings = Setting::where('deleted_at', '=', null)->first();
        if ($settings) {
            if ($settings->currency_id) {
                if (Currency::where('id', $settings->currency_id)->where('deleted_at', '=', null)->first()) {
                    $item['currency_id'] = $settings->currency_id;
                } else {
                    $item['currency_id'] = '';
                }
            } else {
                $item['currency_id'] = '';
            }

            if ($settings->client_id) {
                if (Client::where('id', $settings->client_id)->where('deleted_at', '=', null)->first()) {
                    $item['client_id'] = $settings->client_id;
                } else {
                    $item['client_id'] = '';
                }
            } else {
                $item['client_id'] = '';
            }

            if ($settings->warehouse_id) {
                if (Warehouse::where('id', $settings->warehouse_id)->where('deleted_at', '=', null)->first()) {
                    $item['warehouse_id'] = $settings->warehouse_id;
                } else {
                    $item['warehouse_id'] = '';
                }
            } else {
                $item['warehouse_id'] = '';
            }

            $item['id'] = $settings->id;
            $item['email'] = $settings->email;
            $item['CompanyName'] = $settings->CompanyName;
            $item['CompanyPhone'] = $settings->CompanyPhone;
            $item['CompanyAdress'] = $settings->CompanyAdress;
            $item['logo'] = $settings->logo;
            $item['footer'] = $settings->footer;
            $item['developed_by'] = $settings->developed_by;
            $item['default_language'] = $settings->default_language;
            $item['purchase_approval_threshold'] = $settings->purchase_approval_threshold !== null
                ? (float) $settings->purchase_approval_threshold : null;

            $Currencies = Currency::where('deleted_at', null)->get(['id', 'name']);
            $clients = client::where('deleted_at', '=', null)->get(['id', 'name']);
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);

            return response()->json([
                'settings' => $item ,
                'currencies' => $Currencies,
                'clients' => $clients , 
                'warehouses' => $warehouses
            ], 200);
        } else {
            return response()->json(['statut' => 'error'], 500);
        }
    }

    //-------------- Get STMP ---------------\\

    public function getSMTP(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Server::class);

        $server = Server::where('deleted_at', '=', null)->first();

        if ($server) {
            // Everything but the password, which stays on the server.
            $out = $server->toArray();
            $out['has_password'] = (string) $server->getRawOriginal('password') !== '';

            return response()->json(['server' => $out], 200);
        } else {
            return response()->json(['statut' => 'error'], 500);
        }
    }


    //-------------- Clear_Cache ---------------\\

    public function Clear_Cache(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);

        Artisan::call('cache:clear');
        Artisan::call('view:clear');
        Artisan::call('route:clear');
        Artisan::call('config:clear');

        return response()->json(['success' => true]);
    }

   
    //-------------- Set Environment Value ---------------\\

    public function setEnvironmentValue(array $values)
    {
        $envFile = app()->environmentFilePath();
        $str = file_get_contents($envFile);
        $str .= "\r\n";
        if (count($values) > 0) {
            foreach ($values as $envKey => $envValue) {
    
                $keyPosition = strpos($str, "$envKey=");
                $endOfLinePosition = strpos($str, "\n", $keyPosition);
                $oldLine = substr($str, $keyPosition, $endOfLinePosition - $keyPosition);
    
                if (is_bool($keyPosition) && $keyPosition === false) {
                    // variable doesnot exist
                    $str .= "$envKey=$envValue";
                    $str .= "\r\n";
                } else {
                    // variable exist                    
                    $str = str_replace($oldLine, "$envKey=$envValue", $str);
                }            
            }
        }
    
        $str = substr($str, 0, -1);
        if (!file_put_contents($envFile, $str)) {
            return false;
        }
    
        app()->loadEnvironmentFrom($envFile);    
    
        return true;
    }

}
