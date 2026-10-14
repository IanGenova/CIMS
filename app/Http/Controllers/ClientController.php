<?php

namespace App\Http\Controllers;

use App\Exports\ClientsExport;
use App\Models\Client;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use DB;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\utils\DocumentSequence;

class ClientController extends BaseController
{

    //------------- Get ALL Customers -------------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Client::class);
        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers();
        // Filter fields With Params to retrieve
        $columns = array(0 => 'name', 1 => 'code', 2 => 'phone', 3 => 'email');
        $param = array(0 => 'like', 1 => 'like', 2 => 'like', 3 => 'like');
        $data = array();

        $clients = Client::where('deleted_at', '=', null);

        //Multiple Filter
        $Filtred = $helpers->filter($clients, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('code', 'LIKE', "%{$request->search}%")
                        ->orWhere('phone', 'LIKE', "%{$request->search}%")
                        ->orWhere('email', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $Filtred->count();
        $clients = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        return response()->json([
            'clients' => $clients,
            'totalRows' => $totalRows,
        ]);
    }

    /** What a customer record must hold (DATATEST K37/K72). */
    private static function rules()
    {
        return [
            'name' => 'required|string|max:191',
            'adresse' => 'required|string|max:191',
            'phone' => 'required|string|max:191',
            'email' => 'nullable|email|max:192',
            'country' => 'required|string|max:191',
            'city' => 'required|string|max:191',
            'credit_limit' => 'nullable|numeric|min:0|max:9999999999999999',
            'opening_balance' => 'nullable|numeric|max:9999999999999999',
        ];
    }

    //------------- Store new Customer -------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Client::class);

        // Lengths the columns can hold (refused, not truncated: K37); the email
        // optional but real (K72), and not another customer's (K58).
        $this->validate($request, self::rules());
        \App\utils\Uniqueness::assertFree('clients', 'email', $request['email'], null, 'email', 'email');

        Client::create([
            'name' => $request['name'],
            'code' => $this->getNumberOrder(),
            'adresse' => $request['adresse'],
            'phone' => $request['phone'],
            'email' => $request['email'],
            'country' => $request['country'],
            'city' => $request['city'],
            'customer_group_id' => $request['customer_group_id'] ?: null,
            'is_tax_exempt' => ! empty($request['is_tax_exempt']),
            'tax_number' => $request['tax_number'],
            'credit_limit' => $request['credit_limit'] ?: 0,
            'opening_balance' => $request['opening_balance'] ?: 0,
        ]);
        return response()->json(['success' => true]);
    }

    //------------ function show -----------\\

    public function show($id){
        //
        
    }

    //------------- Update Customer -------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Client::class);
        $this->validate($request, self::rules());
        \App\utils\Uniqueness::assertFree('clients', 'email', $request['email'], $id, 'email', 'email');

        Client::whereId($id)->update([
            'name' => $request['name'],
            'adresse' => $request['adresse'],
            'phone' => $request['phone'],
            'email' => $request['email'],
            'country' => $request['country'],
            'city' => $request['city'],
            'customer_group_id' => $request['customer_group_id'] ?: null,
            'is_tax_exempt' => ! empty($request['is_tax_exempt']),
            'tax_number' => $request['tax_number'],
            'credit_limit' => $request['credit_limit'] ?: 0,
            'opening_balance' => $request['opening_balance'] ?: 0,
        ]);
        return response()->json(['success' => true]);

    }

    //------------- delete client -------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Client::class);
        if (\App\utils\DeleteGuard::missing('clients', $id)) {
            return \App\utils\DeleteGuard::notFound();
        }
        // Not while they owe money: the debt would leave every report (K36).
        if ($reason = \App\utils\DeleteGuard::client($id)) {
            return response()->json(['message' => $reason], 422);
        }

        Client::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);
        // Written straight to the table, so the observer never saw it (K60).
        \App\Observers\AuditObserver::record(Client::class, $id, 'deleted');
        return response()->json(['success' => true]);
    }

    //-------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Client::class);
        $selectedIds = $request->selectedIds;
        if ($reason = \App\utils\DeleteGuard::firstReason('client', (array) $selectedIds)) {
            return response()->json(['message' => $reason], 422);
        }

        foreach ($selectedIds as $Client_id) {
            Client::whereId($Client_id)->update([
                'deleted_at' => Carbon::now(),
            ]);
            \App\Observers\AuditObserver::record(Client::class, $Client_id, 'deleted');
        }
        return response()->json(['success' => true]);
    }

    //------------- Export  ALL Customers in EXCEL -------------\\

    public function exportExcel(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Client::class);

        return Excel::download(new ClientsExport, 'Clients.xlsx');
    }

    //------------- get Number Order Customer -------------\\

    public function getNumberOrder()
    {
        // Was: read the most recently inserted row and add one to its code --
        // which two concurrent callers did identically, and which walked
        // backwards whenever the last row inserted was not the highest one.
        // The counter is claimed atomically and seeded from the maximum.
        return DocumentSequence::nextCode('clients', 'code', 1);
    }

    //------------- Get Clients Without Paginate -------------\\

    public function Get_Clients_Without_Paginate()
    {
        $clients = Client::where('deleted_at', '=', null)->get(['id', 'name']);
        return response()->json($clients);
    }

    // import clients
    public function import_clients(Request $request)
    {
        // Importing creates customers in bulk: it needs its own permission (DATATEST K21).
        \App\utils\DocumentAccess::need('customers_import');

        $file_upload = $request->file('clients');
        $ext = pathinfo($file_upload->getClientOriginalName(), PATHINFO_EXTENSION);
        if ($ext != 'csv') {
            return response()->json([
                'msg' => 'must be in csv format',
                'status' => false,
            ]);
        } else {
            $data = array();
            $rowcount = 0;
            if (($handle = fopen($file_upload, "r")) !== false) {
                $max_line_length = defined('MAX_LINE_LENGTH') ? MAX_LINE_LENGTH : 10000;
                $header = fgetcsv($handle, $max_line_length);
                $header_colcount = count($header);
                while (($row = fgetcsv($handle, $max_line_length)) !== false) {
                    $row_colcount = count($row);
                    if ($row_colcount == $header_colcount) {
                        $entry = array_combine($header, $row);
                        $data[] = $entry;
                    } else {
                        return null;
                    }
                    $rowcount++;
                }
                fclose($handle);
            } else {
                return null;
            }
           
            $rules = array('email' => 'required|email|unique:clients');
            //-- Create New Client
            foreach ($data as $key => $value) {
                $input['email'] = $value['email'];

                $validator = Validator::make($input, $rules);
                if (!$validator->fails()) {
                    
                    Client::create([
                        'name' => $value['name'] == '' ? null : $value['name'],
                        'code' => $this->getNumberOrder(),
                        'adresse' => $value['adresse'] == '' ? null : $value['adresse'],
                        'phone' => $value['phone'] == '' ? null : $value['phone'],
                        'email' => $value['email'] == '' ? null : $value['email'],
                        'country' => $value['country'] == '' ? null : $value['country'],
                        'city' => $value['city'] == '' ? null : $value['city'],
                    ]);
                }

            }

            return response()->json([
                'status' => true,
            ], 200);
        }

    }

}
