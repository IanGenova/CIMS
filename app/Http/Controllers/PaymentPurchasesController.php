<?php
namespace App\Http\Controllers;

use App\Exports\Payment_Purchase_Export;
use App\Mail\Payment_Purchase;
use App\Models\PaymentPurchase;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Setting;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;
use Twilio\Rest\Client as Client_Twilio;
use DB;
use PDF;
use App\utils\DocumentSequence;

class PaymentPurchasesController extends BaseController
{

    //------------- Get All Payments Purchases --------------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Reports_payments_Purchases', PaymentPurchase::class);

        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers();
        $role = Auth::user()->roles()->first();
        $view_records = Role::findOrFail($role->id)->inRole('record_view');
        // Filter fields With Params to retriever
        $param = array(0 => 'like', 1 => '=', 2 => 'like' , 3 => '=');
        $columns = array(0 => 'Ref', 1 => 'purchase_id', 2 => 'Reglement', 3 =>'date');
        $data = array();

        // Check If User Has Permission View  All Records
        $Payments = PaymentPurchase::with('purchase.provider')
            ->where('deleted_at', '=', null)
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
        // Multiple Filter
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('provider_id'), function ($query) use ($request) {
                    return $query->whereHas('purchase.provider', function ($q) use ($request) {
                        $q->where('id', '=', $request->provider_id);
                    });
                });
            });
        $Filtred = $helpers->filter($Payments, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('date', 'LIKE', "%{$request->search}%")
                        ->orWhere('Reglement', 'LIKE', "%{$request->search}%")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('purchase', function ($q) use ($request) {
                                $q->where('Ref', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('purchase.provider', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });

        $totalRows = $Filtred->count();
        $Payments = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($Payments as $Payment) {

            $item['date'] = $Payment->date;
            $item['Ref'] = $Payment->Ref;
            $item['Ref_Purchase'] = $Payment['purchase']->Ref;
            $item['provider_name'] = $Payment['purchase']['provider']->name;
            $item['Reglement'] = $Payment->Reglement;
            $item['montant'] = number_format($Payment->montant, 2, '.', '');

            $data[] = $item;
        }

        $suppliers = provider::where('deleted_at', '=', null)->get(['id', 'name']);
        $purchases = Purchase::get(['Ref', 'id']);

        return response()->json([
            'totalRows' => $totalRows,
            'payments' => $data,
            'purchases' => $purchases,
            'suppliers' => $suppliers,
        ]);

    }

    //----------- Store New Payment Purchase --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', PaymentPurchase::class);
        // 30 days back at most, never ahead (DATATEST K55/K37).
        \App\utils\BusinessDate::check($request['date'], 'date');
        // Whole centavos, above zero, with a method (DATATEST K48/K49).
        $request->validate(\App\utils\PaymentCap::rules());

        $request->validate([
            'purchase_id' => 'required|integer|exists:purchases,id',
            'date' => 'required|date',
            'montant' => 'required|numeric|gt:0',
            // The drawer's change is a real figure, and the column will not
            // take null. Defaulting it here means an older client that does
            // not send it gets a payment rather than a database error.
            'change' => 'nullable|numeric|min:0',
        ]);

        // Paying more than is owed is a keying mistake, not a credit note. Left
        // unchecked it overstates cash out and leaves a payable that reads as
        // negative, which no supplier statement will ever agree with.
        $purchase = Purchase::findOrFail($request['purchase_id']);
        $outstanding = round((float) $purchase->GrandTotal - (float) $purchase->paid_amount, 2);

        if (round((float) $request['montant'], 2) > $outstanding) {
            return response()->json([
                'status' => 422,
                'msg' => 'error',
                'errors' => ['montant' => [
                    'Only ' . number_format($outstanding, 2) . ' is still owed on this purchase.',
                ]],
            ], 422);
        }

        if($request['montant'] > 0){
            \DB::transaction(function () use ($request) {
                $role = Auth::user()->roles()->first();
                $view_records = Role::findOrFail($role->id)->inRole('record_view');
                $purchase = Purchase::lockForUpdate()->findOrFail($request['purchase_id']);

                // Check If User Has Permission view All Records
                if (!$view_records) {
                    // Check If User->id === purchase->id
                    $this->authorizeForUser($request->user('api'), 'check_record', $purchase);
                }

                $total_paid = $purchase->paid_amount + $request['montant'];
                $total_paid = round($total_paid, 2);
                $payment_statut = \App\utils\PaymentCap::status($purchase, $total_paid);

                PaymentPurchase::create([
                    'purchase_id' => $request['purchase_id'],
                    'Ref' => $this->getNumberOrder(),
                    'date' => $request['date'],
                    'Reglement' => $request['Reglement'],
                    'montant' => $request['montant'],
                    'change' => $request['change'] ?: 0,
                    'notes' => $request['notes'],
                    'user_id' => Auth::user()->id,
                ]);

                $purchase->update([
                    'paid_amount' => $total_paid,
                    'payment_statut' => $payment_statut,
                ]);

            }, 10);
        }

        return response()->json(['success' => true, 'message' => 'Payment Create successfully'], 200);
    }

    //------------ function show -----------\\

    public function show($id){
        //
        
        }

    //----------- Update Payment Purchases --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', PaymentPurchase::class);
        // 30 days back at most, never ahead (DATATEST K55/K37).
        \App\utils\BusinessDate::checkIfChanged('payment_purchases', $id, $request['date'], 'date');
        // Whole centavos, above zero, with a method (DATATEST K48/K49).
        $request->validate(\App\utils\PaymentCap::rules());
        
        \DB::transaction(function () use ($id, $request) {
            $role = Auth::user()->roles()->first();
            $view_records = Role::findOrFail($role->id)->inRole('record_view');
            $payment = PaymentPurchase::findOrFail($id);
    
            // Check If User Has Permission view All Records
            if (!$view_records) {
                // Check If User->id === payment->id
                $this->authorizeForUser($request->user('api'), 'check_record', $payment);
            }

            $purchase = Purchase::lockForUpdate()->findOrFail($payment->purchase_id);
            $old_total_paid = $purchase->paid_amount - $payment->montant;
            $new_total_paid = $old_total_paid + $request['montant'];

            $new_total_paid = round($new_total_paid, 2);

            $payment_statut = \App\utils\PaymentCap::status($purchase, $new_total_paid);

            $payment->update([
                'date' => $request['date'],
                'Reglement' => $request['Reglement'],
                'montant' => $request['montant'],
                'change' => $request['change'] ?: 0,
                'notes' => $request['notes'],
            ]);

            $purchase->paid_amount = $new_total_paid;
            $purchase->payment_statut = $payment_statut;
            $purchase->save();

        }, 10);

        return response()->json(['success' => true, 'message' => 'Payment Update successfully'], 200);
    }

    //----------- Delete Payment Purchase --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', PaymentPurchase::class);
        
        \DB::transaction(function () use ($id, $request) {
            $role = Auth::user()->roles()->first();
            $view_records = Role::findOrFail($role->id)->inRole('record_view');
            $payment = PaymentPurchase::findOrFail($id);
    
            // Check If User Has Permission view All Records
            if (!$view_records) {
                // Check If User->id === payment->id
                $this->authorizeForUser($request->user('api'), 'check_record', $payment);
            }

            $purchase = Purchase::lockForUpdate()->findOrFail($payment->purchase_id);
            $total_paid = $purchase->paid_amount - $payment->montant;
            $total_paid = round($total_paid, 2);
            $payment_statut = \App\utils\PaymentCap::status($purchase, $total_paid);

            // Through the model: audited, and taken back out of the journal (DATATEST K60/K27).

            $payment->delete();

            $purchase->update([
                'paid_amount' => $total_paid,
                'payment_statut' => $payment_statut,
            ]);

        }, 10);

        return response()->json(['success' => true, 'message' => 'Payment Delete successfully'], 200);

    }

    //------------- Send Payment Purchase Return on Email -----------\\

    public function SendEmail(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'view', PaymentPurchase::class);

        $payment = $request->all();
        $pdf = $this->Payment_purchase_pdf($request, $payment['id']);
        $this->Set_config_mail(); // Set_config_mail => BaseController
        $mail = Mail::to($request->to)->send(new Payment_Purchase($payment, $pdf));
        return $mail;
    }

    //----------- Reference order Payment Purchases --------------\\

    public function peekNumberOrder()
    {
        // What the payment dialog shows before anything is committed. It does
        // not claim the number -- opening a form and closing it again should
        // not consume an invoice reference.
        return DocumentSequence::peek('payment_purchases', 'INV/PR_', 0, 1111);
    }

    public function getNumberOrder()
    {
        // Was: read the last row, split the reference, add one -- which handed
        // two simultaneous callers the same number. DocumentSequence claims it
        // atomically, and a unique index on Ref refuses a duplicate outright.
        return DocumentSequence::next('payment_purchases', 'INV/PR_', 0, 1111);
    }

    //----------- Payment Purchase PDF --------------\\

    public function Payment_purchase_pdf(Request $request, $id)
    {
        \App\utils\DocumentAccess::assert('payment_purchase');
        $payment = PaymentPurchase::with('purchase', 'purchase.provider')->findOrFail($id);
        \App\utils\DocumentAccess::assertParent($payment['purchase']);

        $payment_data['purchase_Ref'] = $payment['purchase']->Ref;
        $payment_data['supplier_name'] = $payment['purchase']['provider']->name;
        $payment_data['supplier_phone'] = $payment['purchase']['provider']->phone;
        $payment_data['supplier_adr'] = $payment['purchase']['provider']->adresse;
        $payment_data['supplier_email'] = $payment['purchase']['provider']->email;
        $payment_data['montant'] = $payment->montant;
        $payment_data['Ref'] = $payment->Ref;
        $payment_data['date'] = $payment->date;
        $payment_data['Reglement'] = $payment->Reglement;

        $helpers = new helpers();
        $settings = Setting::where('deleted_at', '=', null)->first();
        $symbol = $helpers->Get_Currency_Code();

        $pdf = \PDF::loadView('pdf.payments_purchase', [
            'symbol' => $symbol,
            'setting' => $settings,
            'payment' => $payment_data,
        ]);

        return $pdf->download('Payment_Purchase.pdf');

    }

    //----------- Export To Excel Payment Purchases  --------------\\

    public function exportExcel(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PaymentPurchase::class);

        return Excel::download(new Payment_Purchase_Export, 'Payment_Purchase.xlsx');
    }

     //-------------------Sms Notifications -----------------\\
     public function Send_SMS(Request $request)
     {
         // Same permission as e-mailing it (DATATEST K57).
         $this->authorizeForUser($request->user('api'), 'view', PaymentPurchase::class);
         $payment = PaymentPurchase::with('purchase', 'purchase.provider')->findOrFail($request->id);

         $url = url('/api/Payment_Purchase_PDF/' . $request->id);
         $receiverNumber = $payment['purchase']['provider']->phone;
         $message = "Dear" .' '.$payment['purchase']['provider']->name." \n We are contacting you in regard to a Payment #".$payment['purchase']->Ref.' '.$url.' '. "that has been created on your account. \n We look forward to conducting future business with you.";
         try {
   
             $account_sid = env("TWILIO_SID");
             $auth_token = env("TWILIO_TOKEN");
             $twilio_number = env("TWILIO_FROM");
   
             $client = new Client_Twilio($account_sid, $auth_token);
             $client->messages->create($receiverNumber, [
                 'from' => $twilio_number, 
                 'body' => $message]);
     
         } catch (\Throwable $e) {
             return response()->json(['message' => $e->getMessage()], 500);
         }
     }


}
