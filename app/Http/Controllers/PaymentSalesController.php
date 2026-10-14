<?php
namespace App\Http\Controllers;

use App\Exports\Payment_Sale_Export;
use App\Mail\Payment_Sale;
use App\Models\Client;
use App\Models\PaymentSale;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Setting;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\PaymentWithCreditCard;
use Twilio\Rest\Client as Client_Twilio;
use App\utils\XenditCheckout;
use App\utils\XenditException;
use DB;
use PDF;
use App\utils\DocumentSequence;

class PaymentSalesController extends BaseController
{

    //------------- Get All Payments Sales --------------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Reports_payments_Sales', PaymentSale::class);

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
        $columns = array(0 => 'Ref', 1 => 'sale_id', 2 => 'Reglement' , 3 => 'date');
        $data = array();

        // Check If User Has Permission View  All Records
        $Payments = PaymentSale::with('sale.client')
            ->where('deleted_at', '=', null)
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
        // Multiple Filter
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('client_id'), function ($query) use ($request) {
                    return $query->whereHas('sale.client', function ($q) use ($request) {
                        $q->where('id', '=', $request->client_id);
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
                            return $query->whereHas('sale', function ($q) use ($request) {
                                $q->where('Ref', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('sale.client', function ($q) use ($request) {
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
            $item['Ref_Sale'] = $Payment['sale']->Ref;
            $item['client_name'] = $Payment['sale']['client']->name;
            $item['Reglement'] = $Payment->Reglement;
            $item['montant'] = number_format($Payment->montant, 2, '.', '');

            $data[] = $item;
        }

        $clients = Client::where('deleted_at', '=', null)->get(['id', 'name']);
        $sales = Sale::get(['Ref', 'id']);

        return response()->json([
            'totalRows' => $totalRows,
            'payments' => $data,
            'sales' => $sales,
            'clients' => $clients,
        ]);

    }

    //----------- Store new Payment Sale --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', PaymentSale::class);
        // 30 days back at most, never ahead (DATATEST K55/K37).
        \App\utils\BusinessDate::check($request['date'], 'date');
        // Whole centavos, above zero, with a method (DATATEST K48/K49).
        $request->validate(\App\utils\PaymentCap::rules());

        $request->validate([
            'sale_id' => 'required|integer|exists:sales,id',
            'date' => 'required|date',
            'montant' => 'required|numeric|gt:0',
            'change' => 'nullable|numeric|min:0',
        ]);

        // A Xendit payment is written only when Xendit says the money moved
        // (App\utils\XenditCheckout). Typed in by hand it would be takings
        // nobody can match to a settlement.
        if (XenditCheckout::isXendit($request['Reglement'])) {
            return response()->json(['message' => 'Xendit payments are recorded by themselves once the customer pays. Use "Collect with Xendit" instead.'], 422);
        }

        // The sale screen already refuses to take more than the order is worth.
        // Taking payment afterwards went through a different door with no such
        // guard, so a settled invoice could be paid again and again -- takings
        // that no drawer, statement or Z-reading will ever match.
        $sale = Sale::findOrFail($request['sale_id']);
        \App\utils\SaleState::assertChangeable($sale, 'paid');
        $outstanding = round((float) $sale->GrandTotal - (float) $sale->paid_amount, 2);

        if (round((float) $request['montant'], 2) > $outstanding) {
            return response()->json([
                'status' => 422,
                'msg' => 'error',
                'errors' => ['montant' => [
                    $outstanding <= 0
                        ? 'This sale is already paid in full.'
                        : 'Only ' . number_format($outstanding, 2) . ' is still due on this sale.',
                ]],
            ], 422);
        }

        \DB::transaction(function () use ($request) {
            $helpers = new helpers();
            $role = Auth::user()->roles()->first();
            $view_records = Role::findOrFail($role->id)->inRole('record_view');
            $sale = Sale::lockForUpdate()->findOrFail($request['sale_id']);

            // Check If User Has Permission view All Records
            if (!$view_records) {
                // Check If User->id === sale->id
                $this->authorizeForUser($request->user('api'), 'check_record', $sale);
            }

            try {

                $total_paid = $sale->paid_amount + $request['montant'];
                $total_paid = round($total_paid, 2);
                $payment_statut = \App\utils\PaymentCap::status($sale, $total_paid);

                if($request['montant'] > 0){
                    // Every method is recorded as taken. "credit card" is the
                    // shop's own card terminal; card payments through the
                    // gateway go through Xendit (XenditController), not here.
                    {

                        PaymentSale::create([
                            'sale_id' => $request['sale_id'],
                            'Ref' => $this->getNumberOrder(),
                            'date' => $request['date'],
                            'Reglement' => $request['Reglement'],
                            'montant' => $request['montant'],
                            'change' => $request['change'] ?: 0,
                            'notes' => $request['notes'],
                            'user_id' => Auth::user()->id,
                        ]);

                        $sale->update([
                            'paid_amount' => $total_paid,
                            'payment_statut' => $payment_statut,
                        ]);

                    }
                }

            } catch (\Throwable $e) {
                throw $e;
            }

        }, 10);

        return response()->json(['success' => true, 'message' => 'Payment Create successfully'], 200);
    }

    //------------ function show -----------\\

    public function show($id){
    //
        
    }

    //----------- Update Payments Sale --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', PaymentSale::class);
        // 30 days back at most, never ahead (DATATEST K55/K37).
        \App\utils\BusinessDate::checkIfChanged('payment_sales', $id, $request['date'], 'date');
        // Whole centavos, above zero, with a method (DATATEST K48/K49).
        $request->validate(\App\utils\PaymentCap::rules());

        // Money that moved through Xendit cannot be re-typed: its amount and
        // method are what Xendit settled. To undo it, delete it (which refunds).
        $existing = PaymentSale::findOrFail($id);
        \App\utils\SaleState::assertChangeable((int) $existing->sale_id, 'paid');
        if (XenditCheckout::isXendit($existing->Reglement) || XenditCheckout::isXendit($request['Reglement'])) {
            return response()->json(['message' => 'A Xendit payment cannot be edited. Delete it to refund the customer through Xendit.'], 422);
        }

        \DB::transaction(function () use ($id, $request) {
            $helpers = new helpers();
            $role = Auth::user()->roles()->first();
            $view_records = Role::findOrFail($role->id)->inRole('record_view');
            $payment = PaymentSale::findOrFail($id);

            // Check If User Has Permission view All Records
            if (!$view_records) {
                // Check If User->id === payment->id
                $this->authorizeForUser($request->user('api'), 'check_record', $payment);
            }

            $sale = Sale::lockForUpdate()->findOrFail($payment->sale_id);
            $old_total_paid = $sale->paid_amount - $payment->montant;
            $new_total_paid = $old_total_paid + $request['montant'];

            $new_total_paid = round($new_total_paid, 2);

            $payment_statut = \App\utils\PaymentCap::status($sale, $new_total_paid);

            try {
                // Edited as typed: "credit card" is the shop's own terminal.
                // A Xendit payment never reaches here (refused above).
                {
                    $payment->update([
                        'date' => $request['date'],
                        'Reglement' => $request['Reglement'],
                        'montant' => $request['montant'],
                        'change' => $request['change'] ?: 0,
                        'notes' => $request['notes'],
                    ]);

                    $sale->update([
                        'paid_amount' => $new_total_paid,
                        'payment_statut' => $payment_statut,
                    ]);
                }

            } catch (\Throwable $e) {
                throw $e;
            }

        }, 10);

        return response()->json(['success' => true, 'message' => 'Payment Update successfully'], 200);
    }
    //----------- Delete Payment Sales --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', PaymentSale::class);

        $payment = PaymentSale::findOrFail($id);
        $role = Auth::user()->roles()->first();
        if (! Role::findOrFail($role->id)->inRole('record_view')) {
            $this->authorizeForUser($request->user('api'), 'check_record', $payment);
        }
        \App\utils\SaleState::assertChangeable((int) $payment->sale_id, 'changed');

        // Money taken through Xendit goes back through Xendit before the
        // payment disappears. Where Xendit cannot refund (QR Ph, over the
        // counter), the cashier refunds by hand and says so.
        $xendit = XenditCheckout::forPayment($payment->id);
        if ($xendit) {
            if ($request->boolean('refunded_outside')) {
                XenditCheckout::markRefundedOutside($xendit);
            } else {
                try {
                    XenditCheckout::refund($xendit);
                } catch (XenditException $e) {
                    return response()->json([
                        'message' => $e->getMessage() . ' The payment was kept. If you have refunded the customer another way, remove it as refunded outside Xendit.',
                        'code' => 'XENDIT_REFUND_REFUSED',
                    ], 422);
                }
            }
        }

        \DB::transaction(function () use ($id, $request) {
            $payment = PaymentSale::findOrFail($id);

            $sale = Sale::lockForUpdate()->findOrFail($payment->sale_id);
            $total_paid = $sale->paid_amount - $payment->montant;
            $total_paid = round($total_paid, 2);
            $payment_statut = \App\utils\PaymentCap::status($sale, $total_paid);

            // Through the model: audited, and taken back out of the journal (DATATEST K60/K27).

            $payment->delete();

            $sale->update([
                'paid_amount' => $total_paid,
                'payment_statut' => $payment_statut,
            ]);

        }, 10);

        return response()->json(['success' => true, 'message' => 'Payment Delete successfully'], 200);

    }

    //----------- Reference order Payment Sales --------------\\

    public function peekNumberOrder()
    {
        // What the payment dialog shows before anything is committed. It does
        // not claim the number -- opening a form and closing it again should
        // not consume an invoice reference.
        return DocumentSequence::peek('payment_sales', 'INV/SL_', 0, 1111);
    }

    public function getNumberOrder()
    {
        // Was: read the last row, split the reference, add one -- which handed
        // two simultaneous callers the same number. DocumentSequence claims it
        // atomically, and a unique index on Ref refuses a duplicate outright.
        return DocumentSequence::next('payment_sales', 'INV/SL_', 0, 1111);
    }

    //----------- Payment Sale PDF --------------\\

    public function payment_sale(Request $request, $id)
    {
        \App\utils\DocumentAccess::assert('payment_sale');
        $payment = PaymentSale::with('sale', 'sale.client')->findOrFail($id);
        \App\utils\DocumentAccess::assertParent($payment['sale']);

        $payment_data['sale_Ref'] = $payment['sale']->Ref;
        $payment_data['client_name'] = $payment['sale']['client']->name;
        $payment_data['client_phone'] = $payment['sale']['client']->phone;
        $payment_data['client_adr'] = $payment['sale']['client']->adresse;
        $payment_data['client_email'] = $payment['sale']['client']->email;
        $payment_data['montant'] = $payment->montant;
        $payment_data['Ref'] = $payment->Ref;
        $payment_data['date'] = $payment->date;
        $payment_data['Reglement'] = $payment->Reglement;

        $helpers = new helpers();
        $settings = Setting::where('deleted_at', '=', null)->first();
        $symbol = $helpers->Get_Currency_Code();

        $pdf = \PDF::loadView('pdf.payment_sale', [
            'symbol' => $symbol,
            'setting' => $settings,
            'payment' => $payment_data,
        ]);

        return $pdf->download('Payment_Sale.pdf');

    }

    //------------- Send Payment Sale on Email -----------\\

    public function SendEmail(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'view', PaymentSale::class);

        $payment = $request->all();
        $pdf = $this->payment_sale($request, $payment['id']);
        $this->Set_config_mail(); // Set_config_mail => BaseController
        $mail = Mail::to($request->to)->send(new Payment_Sale($payment, $pdf));
        return $mail;
    }

    //----------- Export To Excel Payment Sales  --------------\\

    public function exportExcel(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PaymentSale::class);

        return Excel::download(new Payment_Sale_Export, 'Payment_Sale.xlsx');
    }

     //-------------------Sms Notifications -----------------\\
     public function Send_SMS(Request $request)
     {
         // Same permission as e-mailing it (DATATEST K57).
         $this->authorizeForUser($request->user('api'), 'view', PaymentSale::class);
         $payment = PaymentSale::with('sale', 'sale.client')->findOrFail($request->id);
         $url = url('/api/payment_Sale_PDF/' . $request->id);
         $receiverNumber = $payment['sale']['client']->phone;
         $message = "Dear" .' '.$payment['sale']['client']->name." \n We are contacting you in regard to a Payment #".$payment['sale']->Ref.' '.$url.' '. "that has been created on your account. \n We look forward to conducting future business with you.";
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
