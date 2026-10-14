<?php

namespace App\Http\Controllers;
use Twilio\Rest\Client as Client_Twilio;
use App\Exports\SalesExport;
use App\Mail\SaleMail;
use App\Models\Client;
use App\Models\Unit;
use App\Models\PaymentSale;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\product_warehouse;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Setting;
use App\Models\PosSetting;
use App\Models\Warehouse;
use App\utils\BatchService;
use App\utils\VoidService;
use App\utils\helpers;
use App\utils\CreditService;
use App\utils\CurrencyService;
use App\utils\OrderTotals;
use App\utils\PricingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;
use App\utils\XenditCheckout;
use App\Models\PaymentWithCreditCard;
use DB;
use PDF;
use App\utils\DocumentSequence;
use App\utils\VatService;
use App\utils\StockLedger;

class SalesController extends BaseController
{

    //------------- GET ALL SALES -----------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Sale::class);
        $role = Auth::user()->roles()->first();
        $view_records = Role::findOrFail($role->id)->inRole('record_view');
        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers();
        // Filter fields With Params to retrieve
        $param = array(
            0 => 'like',
            1 => 'like',
            2 => '=',
            3 => 'like',
            4 => '=',
            5 => '=',
        );
        $columns = array(
            0 => 'Ref',
            1 => 'statut',
            2 => 'client_id',
            3 => 'payment_statut',
            4 => 'warehouse_id',
            5 => 'date',
        );
        $data = array();

        // Check If User Has Permission View  All Records
        $Sales = Sale::with('facture', 'client', 'warehouse')
            ->where('deleted_at', '=', null)
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });
        //Multiple Filter
        $Filtred = $helpers->filter($Sales, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('statut', 'LIKE', "%{$request->search}%")
                        ->orWhere('GrandTotal', $request->search)
                        ->orWhere('payment_statut', 'like', "$request->search")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('client', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('warehouse', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });

        $totalRows = $Filtred->count();
        $Sales = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($Sales as $Sale) {

            $item['id'] = $Sale['id'];
            $item['date'] = $Sale['date'];
            $item['Ref'] = $Sale['Ref'];
            $item['statut'] = $Sale['statut'];
            $item['discount'] = $Sale['discount'];
            $item['shipping'] = $Sale['shipping'];
            $item['warehouse_name'] = $Sale['warehouse']['name'];
            $item['client_id'] = $Sale['client']['id'];
            $item['client_name'] = $Sale['client']['name'];
            $item['client_email'] = $Sale['client']['email'];
            $item['client_tele'] = $Sale['client']['phone'];
            $item['client_code'] = $Sale['client']['code'];
            $item['client_adr'] = $Sale['client']['adresse'];
            $item['GrandTotal'] = number_format($Sale['GrandTotal'], 2, '.', '');
            $item['paid_amount'] = number_format($Sale['paid_amount'], 2, '.', '');
            $item['due'] = number_format($item['GrandTotal'] - $item['paid_amount'], 2, '.', '');
            $item['payment_status'] = $Sale['payment_statut'];
            // A voided sale stays in the list; the reason is what explains the
            // gap it leaves in the run of invoice numbers.
            $item['voided_at'] = $Sale['voided_at'];
            $item['void_reason'] = $Sale['void_reason'];

            $data[] = $item;
        }
        
        $customers = client::where('deleted_at', '=', null)->get(['id', 'name']);
        $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'xendit_ready' => XenditCheckout::configured(),
            'totalRows' => $totalRows,
            'sales' => $data,
            'customers' => $customers,
            'warehouses' => $warehouses,
        ]);
    }

    //------------- STORE NEW SALE-----------\\

    /**
     * A sale sent twice with the same idempotency_key -- a lost reply and a
     * retry -- is recorded once; the repeat answers with the first sale
     * (DATATEST K40). Without a key it runs as it always did.
     */
    public function store(Request $request)
    {
        if ($request->filled('idempotency_key')) {
            return \App\utils\Idempotency::respond(Auth::id(), $request->input('idempotency_key'), function () use ($request) {
                return $this->storeNow($request);
            });
        }

        return $this->storeNow($request);
    }

    public function storeNow(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'create', Sale::class);
        // 30 days back at most, never ahead (DATATEST K55/K37).
        \App\utils\BusinessDate::check($request['date'], 'date');
        // A customer and currency that still exist (DATATEST K38).
        \App\utils\LiveReference::assert('clients', $request['client_id'], 'client_id', 'customer');
        \App\utils\LiveReference::assert('currencies', $request['currency_id'], 'currency_id', 'currency', false);
        // Every line: positive quantity, its own unit family and variant, whole units unless divisible (LineRules, DATATEST K6/K13/K20/K23/K53).
        \App\utils\LineRules::check($request['details'] ?? [], 'sale_unit_id', ['stock' => false]);
        \App\utils\DocumentStatus::assert('sale', $request['statut']);

        $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'date' => 'required|date',
            'statut' => 'required|in:completed,pending,ordered',
            'GrandTotal' => 'required|numeric|min:0',
            'amount' => 'nullable|numeric|min:0',
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|integer|exists:products,id',
            'details.*.sale_unit_id' => 'required|integer|exists:units,id',
            'details.*.quantity' => 'required|numeric|gt:0',
            'details.*.subtotal' => 'required|numeric|min:0',
            'details.*.Unit_price' => 'required|numeric|min:0',
            'details.*.discount' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
            'payment' => 'required|array',
            'payment.status' => 'required|string|max:50',
        ]);

        // Tax is the customer's liability, not a browser field: resolve it from
        // the product's tax group and the customer's exempt flag before any
        // money is computed. A unit price the user typed is left as typed.
        $details = PricingService::applyToDetails($request['details'], $request->client_id);

        // Prices already include 12% VAT: an order-level tax would charge it
        // again, outside every VAT bucket (DATATEST K33).
        if ((float) $request->tax_rate > 0 && VatService::pricesIncludeVat()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'tax_rate' => ['Prices already include VAT, so no order tax can be added.'],
            ]);
        }

        // Money is never taken from the client. Recompute every line and the
        // grand total from the submitted quantities, prices and tax settings.
        $totals = OrderTotals::forOrder($details, [
            'discount' => $request->discount,
            'shipping' => $request->shipping,
            'tax_rate' => $request->tax_rate,
        ], 'Unit_price');

        // Snapshot the rate; a later rate change must not restate this document.
        $currency = CurrencyService::stampFor($request->currency_id);

        // Philippine VAT classification and the statutory SC/PWD discount.
        // The commercial totals are already computed; this classifies them
        // into the buckets a BIR invoice has to print, and works out the
        // discount in the order the law prescribes. It runs before the payment
        // checks so they test what is actually owed (DATATEST K30).
        $vat = VatService::forDocument(
            $details, $totals['lines'], $request->shipping,
            VatService::statutoryFromRequest($request), $request->discount
        );
        $finalTotal = round($vat['summary']['total_due'] + $totals['tax_net'], 2);

        // The amount paid is checked against the total we calculated,
        // not against the total the client claimed.
        if ((int) round((float) $request['amount'] * 100) > (int) round($finalTotal * 100)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'amount' => ['The amount paid cannot be more than the order total of ' . number_format($finalTotal, 2) . '.'],
            ]);
        }

        // A sale on account must stay inside the customer's ceiling. Only a
        // completed sale creates a debt, so a draft is never blocked.
        if ($request->statut === 'completed') {
            CreditService::assertWithinLimit(
                $request->client_id, $finalTotal, (float) $request['amount']
            );
        }

        try {

        $saleId = \DB::transaction(function () use ($request, $totals, $details, $currency, $vat, $finalTotal) {
            // A quotation becomes one sale, once: locked so two conversions at
            // the same moment cannot both pass (DATATEST K39).
            if ($request->filled('quotation_id')) {
                $quote = \DB::table('quotations')->where('id', $request['quotation_id'])->whereNull('deleted_at')
                    ->lockForUpdate()->first();
                if (! $quote) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['quotation_id' => ['That quotation does not exist.']]);
                }
                if ($quote->sale_id && \DB::table('sales')->where('id', $quote->sale_id)->whereNull('deleted_at')->exists()) {
                    $ref = \DB::table('sales')->where('id', $quote->sale_id)->value('Ref');
                    throw \Illuminate\Validation\ValidationException::withMessages(['quotation_id' => [
                        'Quotation ' . $quote->Ref . ' was already turned into sale ' . $ref . '.',
                    ]]);
                }
            }
            // Again under a lock on the customer, so a sale on another screen
            // cannot slip past the limit at the same moment (DATATEST K11).
            if ($request->statut === 'completed') {
                CreditService::assertWithinLimit($request->client_id, $finalTotal, (float) $request['amount']);
            }
            StockLedger::context('sale', 'Sale', null);

            $helpers = new helpers();
            $order = new Sale;

            $order->is_pos = 0;
            $order->date = $request->date;
            $order->Ref = $this->getNumberOrder();
            $order->client_id = $request->client_id;
            $order->GrandTotal = $totals['grand_total'];
            $order->warehouse_id = $request->warehouse_id;
            $order->tax_rate = $request->tax_rate;
            $order->TaxNet = $totals['tax_net'];
            // The discount actually given: under the higher-of rule a promo
            // discount gives way to SC/PWD (DATATEST K74).
            $order->discount = $vat['summary']['order_discount_applied'] ?? $request->discount;
            $order->shipping = $request->shipping;
            $order->statut = $request->statut;
            $order->payment_statut = 'unpaid';
            $order->notes = $request->notes;
            $order->user_id = Auth::user()->id;
            $order->currency_id = $currency['currency_id'];
            $order->exchange_rate = $currency['exchange_rate'];
            $order->base_amount = CurrencyService::toBase(
                $finalTotal, $currency['currency_id'], $currency['exchange_rate']
            );

            // What is owed comes from the VAT computation, not from subtracting the
            // discount off the commercial total: a beneficiary is exempt from the
            // output VAT as well, so that has to come off too. Order-level
            // discount and order-level tax sit outside the line calculation, so
            // they are carried across unchanged -- which also means an ordinary
            // sale still totals exactly what it did before any of this existed.
            // The order discount is now inside the VAT computation, spread across
            // the lines, so the only thing left outside it is the order-level tax.
            $order->GrandTotal = $finalTotal;
            $order->vatable_sales = $vat['summary']['vatable_sales'];
            $order->vat_amount = $vat['summary']['vat_amount'];
            $order->vat_exempt_sales = $vat['summary']['vat_exempt_sales'];
            $order->zero_rated_sales = $vat['summary']['zero_rated_sales'];
            $order->statutory_discount = $vat['summary']['statutory_discount'];
            $order->statutory_type = $vat['summary']['statutory_type'];
            $order->statutory_id_number = $vat['summary']['statutory_id_number'] ?? null;
            $order->statutory_holder_name = $vat['summary']['statutory_holder_name'] ?? null;
            $order->statutory_beneficiaries = $vat['summary']['statutory_beneficiaries'];
            $order->statutory_total_persons = $vat['summary']['statutory_total_persons'];

            $order->save();

            if ($request->filled('quotation_id')) {
                \DB::table('sales')->where('id', $order->id)->update(['quotation_id' => $request['quotation_id']]);
                \DB::table('quotations')->where('id', $request['quotation_id'])->update(['sale_id' => $order->id]);
            }

            // Now that the sale has an id, movements can name it. Until this
            // point the context had nothing to point at.
            StockLedger::context('sale', 'Sale', $order->id);

            $data = $details;
            $orderDetails = [];
            foreach ($data as $key => $value) {
                $unit = Unit::where('id', $value['sale_unit_id'])
                    ->first();
                $orderDetails[] = [
                    'date' => $request->date,
                    'sale_id' => $order->id,
                    'sale_unit_id' =>  $value['sale_unit_id'],
                    'quantity' => $value['quantity'],
                    'price' => $value['Unit_price'],
                    'TaxNet' => $value['tax_percent'],
                    'tax_method' => $value['tax_method'],
                    'discount' => $value['discount'],
                    'discount_method' => $value['discount_Method'],
                    'product_id' => $value['product_id'],
                    'product_variant_id' => $value['product_variant_id'],
                    'total' => $totals['lines'][$key]['subtotal'],
                    // Snapshotted like the price: reclassifying a product later
                    // must not restate an invoice already issued.
                    'vat_class' => $vat['lines'][$key]['vat_class'] ?? 'vatable',
                    'sc_pwd_eligible' => $vat['lines'][$key]['sc_pwd_eligible'] ?? true,
                    'vat_amount' => $vat['lines'][$key]['vat'] ?? 0,
                    'statutory_discount' => $vat['lines'][$key]['statutory_discount'] ?? 0,
                ];


                if ($order->statut == "completed") {
                    // Serial-tracked: the units leaving are named; warranty starts.
                    if (\App\utils\SerialService::isTracked($value['product_id'])) {
                        $serialQty = $unit && $unit->operator == '/'
                            ? $value['quantity'] / $unit->operator_value
                            : $value['quantity'] * ($unit ? $unit->operator_value : 1);
                        \App\utils\SerialService::sell($value['product_id'], $order->warehouse_id,
                            $value['serials'] ?? [], $serialQty, $order->id, $order->client_id);
                    }

                    if (BatchService::isTracked($value['product_id'])) {
                        // Batch-tracked: pick oldest expiry first, skip expired
                        // lots, and let the service keep the warehouse total in
                        // step with the lots it consumed.
                        if (!$unit) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'stock' => ['The selected product, unit, or warehouse stock record no longer exists.'],
                            ]);
                        }

                        $baseQty = $unit->operator == '/'
                            ? $value['quantity'] / $unit->operator_value
                            : $value['quantity'] * $unit->operator_value;

                        BatchService::allocate(
                            $value['product_id'],
                            $value['product_variant_id'],
                            $order->warehouse_id,
                            $baseQty,
                            'sale',
                            'Sale',
                            $order->id
                        );

                    } elseif ($value['product_variant_id'] !== null) {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $order->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->where('product_variant_id', $value['product_variant_id'])
                            ->lockForUpdate()
                            ->first();

                        if (!$unit || !$product_warehouse) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'stock' => ['The selected product, unit, or warehouse stock record no longer exists.'],
                            ]);
                        }

                        if ($unit->operator == '/') {
                                $product_warehouse->qte -= $value['quantity'] / $unit->operator_value;
                            } else {
                                $product_warehouse->qte -= $value['quantity'] * $unit->operator_value;
                            }
                            $product_warehouse->save();

                    } else {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $order->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->lockForUpdate()
                            ->first();

                        if (!$unit || !$product_warehouse) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'stock' => ['The selected product, unit, or warehouse stock record no longer exists.'],
                            ]);
                        }

                        if ($unit->operator == '/') {
                                $product_warehouse->qte -= $value['quantity'] / $unit->operator_value;
                            } else {
                                $product_warehouse->qte -= $value['quantity'] * $unit->operator_value;
                            }
                            $product_warehouse->save();
                    }
                }
            }
            SaleDetail::insert($orderDetails);

            $role = Auth::user()->roles()->first();
            $view_records = Role::findOrFail($role->id)->inRole('record_view');

            if ($request->payment['status'] != 'pending') {
                $sale = Sale::findOrFail($order->id);
                // Check If User Has Permission view All Records
                if (!$view_records) {
                    // Check If User->id === sale->id
                    $this->authorizeForUser($request->user('api'), 'check_record', $sale);
                }


                {

                    $total_paid = round($sale->paid_amount + $request['amount'], 2);
                    $payment_statut = \App\utils\PaymentStatus::of($sale->GrandTotal, $total_paid);

                    // Xendit is not recorded here: the screen saves the sale
                    // unpaid and opens a Xendit checkout for it; the payment is
                    // written when Xendit says the money moved. Every other
                    // method is recorded as taken -- "credit card" is the
                    // shop's own terminal.
                    if($request['amount'] > 0 && ! XenditCheckout::isXendit($request->payment['Reglement'])){
                        {

                            PaymentSale::create([
                                'sale_id' => $order->id,
                                'Ref' => app('App\Http\Controllers\PaymentSalesController')->getNumberOrder(),
                                'date' => Carbon::now(),
                                'Reglement' => $request->payment['Reglement'],
                                'montant' => $request['amount'],
                                'change' => $request['change'] ?? 0,
                                'user_id' => Auth::user()->id,
                            ]);

                            $sale->update([
                                'paid_amount' => $total_paid,
                                'payment_statut' => $payment_statut,
                            ]);
                        }
                    }
                }

            }

            return $order->id;
        }, 10);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Stock and input errors must keep their 422 response.
            throw $e;
        } catch (\Throwable $e) {
            // The transaction has rolled back: no sale, no stock movement.
            \Log::error('Sale could not be completed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'The sale was not completed and no stock was deducted. The payment could not be recorded: ' . $e->getMessage(),
            ], 500);
        }

        // The id lets the screen open a Xendit checkout for this sale.
        return response()->json(['success' => true, 'id' => $saleId]);
    }


    //------------- UPDATE SALE -----------

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Sale::class);
        // 30 days back at most, never ahead (DATATEST K55/K37).
        \App\utils\BusinessDate::checkIfChanged('sales', $id, $request['date'], 'date');
        \App\utils\LiveReference::assert('clients', $request['client_id'], 'client_id', 'customer');
        \App\utils\LiveReference::assert('currencies', $request['currency_id'], 'currency_id', 'currency', false);
        // A voided sale, or one from a counted-down shift, is final (DATATEST K15).
        \App\utils\SaleState::assertChangeable((int) $id, 'edited');
        // Every line: positive quantity, its own unit family and variant, whole units unless divisible (LineRules, DATATEST K6/K13/K20/K23/K53).
        \App\utils\LineRules::check($request['details'] ?? [], 'sale_unit_id', ['stock' => false]);
        \App\utils\DocumentStatus::assert('sale', $request['statut']);

        request()->validate([
            'warehouse_id' => 'required',
            'client_id' => 'required',
            'details' => 'required|array|min:1',
            'details.*.quantity' => 'required|numeric|gt:0',
            'details.*.Unit_price' => 'required|numeric|min:0',
            'details.*.discount' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
        ]);

        // Serial-tracked lines name the units sold; an edit cannot re-pick them.
        \App\utils\SerialService::refuseIfTracked(array_merge(
            SaleDetail::where('sale_id', $id)->pluck('product_id')->all(),
            array_column((array) $request['details'], 'product_id')
        ), 'an edit of a saved sale', 'Void or delete the sale (its units go back on the shelf) and ring it up again, or take a customer return.');

        // Same resolution as store: an edited document must price and tax the
        // way a new one would for this customer.
        $details = PricingService::applyToDetails($request['details'], $request->client_id);

        if ((float) $request->tax_rate > 0 && VatService::pricesIncludeVat()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'tax_rate' => ['Prices already include VAT, so no order tax can be added.'],
            ]);
        }

        // Recompute the money server-side; never trust the client's totals.
        $totals = OrderTotals::forOrder($details, [
            'discount' => $request->discount,
            'shipping' => $request->shipping,
            'tax_rate' => $request->tax_rate,
        ], 'Unit_price');

        $currency = CurrencyService::stampFor($request->currency_id);

        // An edit is taxed the way the sale was: VAT buckets and the SC/PWD
        // discount are worked out again on the new lines. The edit screen does
        // not resend the Senior/PWD ID, so the one on file still applies.
        $saved = Sale::findOrFail($id);
        $statutorySource = $request->has('statutory_type') ? $request->all() : [
            'statutory_type' => $saved->statutory_type,
            'statutory_id_number' => $saved->statutory_id_number,
            'statutory_holder_name' => $saved->statutory_holder_name,
            'statutory_beneficiaries' => $saved->statutory_beneficiaries,
            'statutory_total_persons' => $saved->statutory_total_persons,
        ];
        $vat = VatService::forDocument(
            $details, $totals['lines'], $request->shipping,
            VatService::statutoryFromRequest($statutorySource), $request->discount
        );
        $finalTotal = round($vat['summary']['total_due'] + $totals['tax_net'], 2);

        \DB::transaction(function () use ($request, $id, $totals, $details, $currency, $vat, $finalTotal) {

            $role = Auth::user()->roles()->first();
            $view_records = Role::findOrFail($role->id)->inRole('record_view');
            // Locked: a payment taken while this edit runs must be counted in
            // the check below (DATATEST K11).
            $current_Sale = Sale::lockForUpdate()->findOrFail($id);

            // Owner decision (DATATEST K4/B20): an edit may not leave the sale
            // owing the customer money. Take a return or refund instead.
            if ((int) round($finalTotal * 100) < (int) round((float) $current_Sale->paid_amount * 100)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'details' => ['The customer has already paid ' . number_format($current_Sale->paid_amount, 2)
                        . '; the edited total of ' . number_format($finalTotal, 2) . ' would be less. Take a sale return instead.'],
                ]);
            }

            // Check If User Has Permission view All Records
            if (!$view_records) {
                // Check If User->id === Sale->id
                $this->authorizeForUser($request->user('api'), 'check_record', $current_Sale);
            }
            $old_sale_details = SaleDetail::where('sale_id', $id)->get();
            $new_sale_details = $details;
            $length = sizeof($new_sale_details);

            // Get Ids for new Details
            $new_products_id = [];
            foreach ($new_sale_details as $new_detail) {
                $new_products_id[] = $new_detail['id'];
            }

            // Init Data with old Parametre
            $old_products_id = [];
            foreach ($old_sale_details as $key => $value) {
                $old_products_id[] = $value->id;
                
                //check if detail has sale_unit_id Or Null
                if($value['sale_unit_id'] !== null){
                    $old_unit = Unit::where('id', $value['sale_unit_id'])->first();
                }else{
                    $product_unit_sale_id = Product::with('unitSale')
                    ->where('id', $value['product_id'])
                    ->first();
                    $old_unit = Unit::where('id', $product_unit_sale_id['unitSale']->id)->first();
                }

                if($value['sale_unit_id'] !== null){
                    if ($current_Sale->statut == "completed") {

                        if ($value['product_variant_id'] !== null) {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Sale->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->where('product_variant_id', $value['product_variant_id'])
                                ->first();

                            if ($product_warehouse) {
                                if ($old_unit->operator == '/') {
                                    $product_warehouse->qte += $value['quantity'] / $old_unit->operator_value;
                                } else {
                                    $product_warehouse->qte += $value['quantity'] * $old_unit->operator_value;
                                }
                                $product_warehouse->save();
                            }

                        } else {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Sale->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->first();
                            if ($product_warehouse) {
                                if ($old_unit->operator == '/') {
                                    $product_warehouse->qte += $value['quantity'] / $old_unit->operator_value;
                                } else {
                                    $product_warehouse->qte += $value['quantity'] * $old_unit->operator_value;
                                }
                                $product_warehouse->save();
                            }
                        }
                    }
                    // Delete Detail
                    if (!in_array($old_products_id[$key], $new_products_id)) {
                        $SaleDetail = SaleDetail::findOrFail($value->id);
                        $SaleDetail->delete();
                    }
                }
            }

            // Update Data with New request
            foreach ($new_sale_details as $prd => $prod_detail) {
                
                if($prod_detail['no_unit'] !== 0){
                    $unit_prod = Unit::where('id', $prod_detail['sale_unit_id'])->first();

                    if ($request['statut'] == "completed") {

                        if ($prod_detail['product_variant_id'] !== null) {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $request->warehouse_id)
                                ->where('product_id', $prod_detail['product_id'])
                                ->where('product_variant_id', $prod_detail['product_variant_id'])
                                ->first();

                            if ($product_warehouse) {
                                if ($unit_prod->operator == '/') {
                                    $product_warehouse->qte -= $prod_detail['quantity'] / $unit_prod->operator_value;
                                } else {
                                    $product_warehouse->qte -= $prod_detail['quantity'] * $unit_prod->operator_value;
                                }
                                $product_warehouse->save();
                            }

                        } else {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $request->warehouse_id)
                                ->where('product_id', $prod_detail['product_id'])
                                ->first();

                            if ($product_warehouse) {
                                if ($unit_prod->operator == '/') {
                                    $product_warehouse->qte -= $prod_detail['quantity'] / $unit_prod->operator_value;
                                } else {
                                    $product_warehouse->qte -= $prod_detail['quantity'] * $unit_prod->operator_value;
                                }
                                $product_warehouse->save();
                            }
                        }

                    }

                    $orderDetails['sale_id'] = $id;
                    $orderDetails['price'] = $prod_detail['Unit_price'];
                    $orderDetails['sale_unit_id'] = $prod_detail['sale_unit_id'];
                    $orderDetails['TaxNet'] = $prod_detail['tax_percent'];
                    $orderDetails['tax_method'] = $prod_detail['tax_method'];
                    $orderDetails['discount'] = $prod_detail['discount'];
                    $orderDetails['discount_method'] = $prod_detail['discount_Method'];
                    $orderDetails['quantity'] = $prod_detail['quantity'];
                    $orderDetails['product_id'] = $prod_detail['product_id'];
                    $orderDetails['product_variant_id'] = $prod_detail['product_variant_id'];
                    $orderDetails['total'] = $totals['lines'][$prd]['subtotal'];
                    
                    if (!in_array($prod_detail['id'], $old_products_id)) {
                        $orderDetails['date'] = Carbon::now();
                        $orderDetails['sale_unit_id'] = $unit_prod ? $unit_prod->id : Null;
                        SaleDetail::Create($orderDetails);
                    } else {
                        SaleDetail::where('id', $prod_detail['id'])->update($orderDetails);
                    }
                }
            }

            $grand_total = $finalTotal;
            $payment_statut = \App\utils\PaymentStatus::of($grand_total, $current_Sale->paid_amount);

            $current_Sale->update([
                'date' => $request['date'],
                'client_id' => $request['client_id'],
                'warehouse_id' => $request['warehouse_id'],
                'notes' => $request['notes'],
                'statut' => $request['statut'],
                'tax_rate' => $request['tax_rate'],
                'TaxNet' => $totals['tax_net'],
                'discount' => $vat['summary']['order_discount_applied'] ?? $request['discount'],
                'shipping' => $request['shipping'],
                'GrandTotal' => $grand_total,
                'payment_statut' => $payment_statut,
                'base_amount' => CurrencyService::toBase($grand_total, $currency['currency_id'], $currency['exchange_rate']),
            ]);
            // The BIR buckets follow the edited lines (not mass-assignable).
            \DB::table('sales')->where('id', $id)->update([
                'vatable_sales' => $vat['summary']['vatable_sales'],
                'vat_amount' => $vat['summary']['vat_amount'],
                'vat_exempt_sales' => $vat['summary']['vat_exempt_sales'],
                'zero_rated_sales' => $vat['summary']['zero_rated_sales'],
                'statutory_discount' => $vat['summary']['statutory_discount'],
            ]);

        }, 10);

        return response()->json(['success' => true]);
    }

    //------------- Remove SALE BY ID -----------\\

     public function destroy(Request $request, $id)
     {
         $this->authorizeForUser($request->user('api'), 'delete', Sale::class);
         \App\utils\SaleState::assertChangeable((int) $id, 'deleted');
         \App\utils\SaleState::assertNoReturns((int) $id);

         // Money taken through Xendit has to go back first (deleting the
         // payment refunds it); deleting the sale would just lose track of it.
         if (XenditCheckout::saleHoldsXenditMoney($id)) {
             return response()->json(['message' => 'This sale has a Xendit payment. Delete that payment first so the customer is refunded, then delete the sale.'], 422);
         }
 
         \DB::transaction(function () use ($id, $request) {
             $role = Auth::user()->roles()->first();
             $view_records = Role::findOrFail($role->id)->inRole('record_view');
             // The sale first, then its stock -- the order a void locks them in, so
             // a void and a delete of the same sale cannot deadlock (DATATEST K78).
             $current_Sale = Sale::lockForUpdate()->findOrFail($id);
             // Again under the lock: a void that finished while this delete
             // waited has already put the stock back (DATATEST K78).
             \App\utils\SaleState::assertChangeable($current_Sale, 'deleted');
             \App\utils\SaleState::assertNoReturns((int) $id);
             $old_sale_details = SaleDetail::where('sale_id', $id)->get();
 
             // Check If User Has Permission view All Records
             if (!$view_records) {
                 // Check If User->id === Sale->id
                 $this->authorizeForUser($request->user('api'), 'check_record', $current_Sale);
             }

            // Batch-tracked lines go back into the exact lots they came out of,
            // by replaying this sale's own ledger entries inverted.
            if ($current_Sale->statut == "completed") {
                BatchService::reverse('Sale', $current_Sale->id);
            }
            // Serial-tracked units it sold are back on the shelf.
            \App\utils\SerialService::releaseSale($current_Sale->id);

            foreach ($old_sale_details as $key => $value) {

                // Already returned to its lots above.
                if (BatchService::isTracked($value['product_id'])) {
                    continue;
                }

                //check if detail has sale_unit_id Or Null
                if($value['sale_unit_id'] !== null){
                    $old_unit = Unit::where('id', $value['sale_unit_id'])->first();
                }else{
                    $product_unit_sale_id = Product::with('unitSale')
                    ->where('id', $value['product_id'])
                    ->first();
                    $old_unit = Unit::where('id', $product_unit_sale_id['unitSale']->id)->first();
                }

                if ($current_Sale->statut == "completed") {

                    if ($value['product_variant_id'] !== null) {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $current_Sale->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->where('product_variant_id', $value['product_variant_id'])
                            ->first();

                        if ($product_warehouse) {
                            if ($old_unit->operator == '/') {
                                $product_warehouse->qte += $value['quantity'] / $old_unit->operator_value;
                            } else {
                                $product_warehouse->qte += $value['quantity'] * $old_unit->operator_value;
                            }
                            $product_warehouse->save();
                        }

                    } else {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $current_Sale->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->first();
                        if ($product_warehouse) {
                            if ($old_unit->operator == '/') {
                                $product_warehouse->qte += $value['quantity'] / $old_unit->operator_value;
                            } else {
                                $product_warehouse->qte += $value['quantity'] * $old_unit->operator_value;
                            }
                            $product_warehouse->save();
                        }
                    }
                }
                  
            }

             $current_Sale->details()->delete();
             $current_Sale->update([
                 'deleted_at' => Carbon::now(),
             ]);

             // Take the sale and its payments back out of the journal (DATATEST
             // K27). Before the payments go, so they are not taken back twice.
             \App\utils\LedgerService::reverseDocument('sale', $id, 'Sale ' . $current_Sale->Ref . ' deleted');

             $Payment_Sale_data = PaymentSale::where('sale_id', $id)->get();
             foreach($Payment_Sale_data as $Payment_Sale){
                 if($Payment_Sale->Reglement == 'credit card') {
                     $PaymentWithCreditCard = PaymentWithCreditCard::where('payment_id', $Payment_Sale->id)->first();
                     if($PaymentWithCreditCard){
                         $PaymentWithCreditCard->delete();
                     }
                 }
                 $Payment_Sale->delete();
             }
 
         }, 10);
 
         return response()->json(['success' => true]);
     }

    //-------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', Sale::class);

        foreach ((array) $request->selectedIds as $saleId) {
            \App\utils\SaleState::assertChangeable((int) $saleId, 'deleted');
            \App\utils\SaleState::assertNoReturns((int) $saleId);
            if (XenditCheckout::saleHoldsXenditMoney($saleId)) {
                return response()->json(['message' => 'A selected sale has a Xendit payment. Delete that payment first so the customer is refunded.'], 422);
            }
        }

        \DB::transaction(function () use ($request) {
            $role = Auth::user()->roles()->first();
            $view_records = Role::findOrFail($role->id)->inRole('record_view');
            $selectedIds = $request->selectedIds;
            foreach ($selectedIds as $sale_id) {

                $current_Sale = Sale::findOrFail($sale_id);
                $old_sale_details = SaleDetail::where('sale_id', $sale_id)->get();
                // Check If User Has Permission view All Records
                if (!$view_records) {
                    // Check If User->id === current_Sale->id
                    $this->authorizeForUser($request->user('api'), 'check_record', $current_Sale);
                }
                \App\utils\SerialService::releaseSale($sale_id);
                foreach ($old_sale_details as $key => $value) {

                    //check if detail has sale_unit_id Or Null
                    if($value['sale_unit_id'] !== null){
                        $old_unit = Unit::where('id', $value['sale_unit_id'])->first();
                    }else{
                        $product_unit_sale_id = Product::with('unitSale')
                        ->where('id', $value['product_id'])
                        ->first();
                        $old_unit = Unit::where('id', $product_unit_sale_id['unitSale']->id)->first();
                    }
    
                    if ($current_Sale->statut == "completed") {
    
                        if ($value['product_variant_id'] !== null) {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Sale->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->where('product_variant_id', $value['product_variant_id'])
                                ->first();
    
                            if ($product_warehouse) {
                                if ($old_unit->operator == '/') {
                                    $product_warehouse->qte += $value['quantity'] / $old_unit->operator_value;
                                } else {
                                    $product_warehouse->qte += $value['quantity'] * $old_unit->operator_value;
                                }
                                $product_warehouse->save();
                            }
    
                        } else {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Sale->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->first();
                            if ($product_warehouse) {
                                if ($old_unit->operator == '/') {
                                    $product_warehouse->qte += $value['quantity'] / $old_unit->operator_value;
                                } else {
                                    $product_warehouse->qte += $value['quantity'] * $old_unit->operator_value;
                                }
                                $product_warehouse->save();
                            }
                        }
                    }
                      
                }
                $current_Sale->details()->delete();
                $current_Sale->update([
                    'deleted_at' => Carbon::now(),
                ]);

                \App\utils\LedgerService::reverseDocument('sale', $sale_id, 'Sale deleted');
                $Payment_Sale_data = PaymentSale::where('sale_id', $sale_id)->get();
                foreach($Payment_Sale_data as $Payment_Sale){
                    if($Payment_Sale->Reglement == 'credit card') {
                        $PaymentWithCreditCard = PaymentWithCreditCard::where('payment_id', $Payment_Sale->id)->first();
                        if($PaymentWithCreditCard){
                            $PaymentWithCreditCard->delete();
                        }
                    }
                    $Payment_Sale->delete();
                }
            }

        }, 10);

        return response()->json(['success' => true]);
    }

    //------------- EXPORT  SALE to EXCEL-----------\\

    public function exportExcel(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Sale::class);
        return Excel::download(new SalesExport, 'List_Sales.xlsx');
    }

    //---------------- Get Details Sale-----------------\\

    public function show(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'view', Sale::class);
        $role = Auth::user()->roles()->first();
        $view_records = Role::findOrFail($role->id)->inRole('record_view');
        $sale_data = Sale::with('details.product.unitSale')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $details = array();

        // Check If User Has Permission view All Records
        if (!$view_records) {
            // Check If User->id === sale->id
            $this->authorizeForUser($request->user('api'), 'check_record', $sale_data);
        }

        $sale_details['Ref'] = $sale_data->Ref;
        $sale_details['date'] = $sale_data->date;
        $sale_details['statut'] = $sale_data->statut;
        $sale_details['warehouse'] = $sale_data['warehouse']->name;
        $sale_details['discount'] = $sale_data->discount;
        $sale_details['shipping'] = $sale_data->shipping;
        $sale_details['tax_rate'] = $sale_data->tax_rate;
        $sale_details['TaxNet'] = $sale_data->TaxNet;
        $sale_details['client_name'] = $sale_data['client']->name;
        $sale_details['client_phone'] = $sale_data['client']->phone;
        $sale_details['client_adr'] = $sale_data['client']->adresse;
        $sale_details['client_email'] = $sale_data['client']->email;
        $sale_details['GrandTotal'] = number_format($sale_data->GrandTotal, 2, '.', '');
        $sale_details['paid_amount'] = number_format($sale_data->paid_amount, 2, '.', '');
        $sale_details['due'] = number_format($sale_details['GrandTotal'] - $sale_details['paid_amount'], 2, '.', '');
        $sale_details['payment_status'] = $sale_data->payment_statut;

        foreach ($sale_data['details'] as $detail) {

             //check if detail has sale_unit_id Or Null
             if($detail->sale_unit_id !== null){
                $unit = Unit::where('id', $detail->sale_unit_id)->first();
            }else{
                $product_unit_sale_id = Product::with('unitSale')
                ->where('id', $detail->product_id)
                ->first();
                $unit = Unit::where('id', $product_unit_sale_id['unitSale']->id)->first();
            }

            if ($detail->product_variant_id) {

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $data['code'] = $productsVariants->name . '-' . $detail['product']['code'];

            } else {
                $data['code'] = $detail['product']['code'];
            }

            $data['quantity'] = $detail->quantity;
            $data['total'] = $detail->total;
            $data['name'] = $detail['product']['name'];
            $data['price'] = $detail->price;
            $data['unit_sale'] = $unit->ShortName;

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = $detail->discount;
            } else {
                $data['DiscountNet'] = $detail->price * $detail->discount / 100;
            }

            $tax_price = $detail->TaxNet * (($detail->price - $data['DiscountNet']) / 100);
            $data['Unit_price'] = $detail->price;
            $data['discount'] = $detail->discount;

            if ($detail->tax_method == '1') {
                $data['Net_price'] = $detail->price - $data['DiscountNet'];
                $data['taxe'] = $tax_price;
            } else {
                $data['Net_price'] = ($detail->price - $data['DiscountNet']) / (($detail->TaxNet / 100) + 1);
                $data['taxe'] = $detail->price - $data['Net_price'] - $data['DiscountNet'];
            }

            $details[] = $data;
        }

        $company = Setting::where('deleted_at', '=', null)->first();

        return response()->json([
            'details' => $details,
            'sale' => $sale_details,
            'company' => $company,
        ]);

    }

    //-------------- Print Invoice ---------------\\

    public function Print_Invoice_POS(Request $request, $id)
    {
        \App\utils\DocumentAccess::assert('sale');
        $helpers = new helpers();
        $details = array();

        $sale = Sale::with('details.product.unitSale')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $item['id'] = $sale->id;
        $item['Ref'] = $sale->Ref;
        $item['date'] = $sale->date;
        $item['discount'] = number_format($sale->discount, 2, '.', '');
        $item['shipping'] = number_format($sale->shipping, 2, '.', '');
        $item['taxe'] =     number_format($sale->TaxNet, 2, '.', '');
        $item['tax_rate'] = $sale->tax_rate;
        $item['client_name'] = $sale['client']->name;
        $item['GrandTotal'] = number_format($sale->GrandTotal, 2, '.', '');
        $item['paid_amount'] = number_format($sale->paid_amount, 2, '.', '');

        // The figures a BIR invoice has to summarise, and the statutory discount
        // details that have to appear beside them.
        $item['vatable_sales'] = number_format($sale->vatable_sales, 2, '.', '');
        $item['vat_amount'] = number_format($sale->vat_amount, 2, '.', '');
        $item['vat_exempt_sales'] = number_format($sale->vat_exempt_sales, 2, '.', '');
        $item['zero_rated_sales'] = number_format($sale->zero_rated_sales, 2, '.', '');
        $item['vat_rate'] = VatService::rate();
        $item['statutory_discount'] = number_format($sale->statutory_discount, 2, '.', '');
        $item['statutory_type'] = $sale->statutory_type;
        $item['statutory_label'] = $sale->statutory_type === 'senior'
            ? 'Senior Citizen' : ($sale->statutory_type === 'pwd' ? 'PWD' : null);
        $item['statutory_id_number'] = $sale->statutory_id_number;
        $item['statutory_holder_name'] = $sale->statutory_holder_name;
        $item['statutory_beneficiaries'] = $sale->statutory_beneficiaries;
        $item['statutory_total_persons'] = $sale->statutory_total_persons;
        $item['client_tin'] = $sale['client']->tin ?? null;

        foreach ($sale['details'] as $detail) {

             //check if detail has sale_unit_id Or Null
             if($detail->sale_unit_id !== null){
                $unit = Unit::where('id', $detail->sale_unit_id)->first();
            }else{
                $product_unit_sale_id = Product::with('unitSale')
                ->where('id', $detail->product_id)
                ->first();
                $unit = Unit::where('id', $product_unit_sale_id['unitSale']->id)->first();
            }

            if ($detail->product_variant_id) {

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                    $data['code'] = $productsVariants->name . '-' . $detail['product']['code'];
                    $data['name'] = $productsVariants->name . '-' . $detail['product']['name'];
                    
                } else {
                    $data['code'] = $detail['product']['code'];
                    $data['name'] = $detail['product']['name'];
                }
                
           
            $data['quantity'] = number_format($detail->quantity, 2, '.', '');
            $data['total'] = number_format($detail->total, 2, '.', '');
            $data['unit_sale'] = $unit->ShortName;
            $details[] = $data;
        }

        $payments = PaymentSale::with('sale')
            ->where('sale_id', $id)
            ->orderBy('id', 'DESC')
            ->get();

        $settings = Setting::where('deleted_at', '=', null)->first();
        $pos_settings = PosSetting::where('deleted_at', '=', null)->first();
        $symbol = $helpers->Get_Currency_Code();

        return response()->json([
            'symbol' => $symbol,
            'payments' => $payments,
            'setting' => $settings,
            'pos_settings' => $pos_settings,
            'sale' => $item,
            'details' => $details,
        ]);

    }

    //------------- GET PAYMENTS SALE -----------\\

    public function Payments_Sale(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'view', PaymentSale::class);
        $role = Auth::user()->roles()->first();
        $view_records = Role::findOrFail($role->id)->inRole('record_view');
        $Sale = Sale::findOrFail($id);

        // Check If User Has Permission view All Records
        if (!$view_records) {
            // Check If User->id === Sale->id
            $this->authorizeForUser($request->user('api'), 'check_record', $Sale);
        }

        $payments = PaymentSale::with('sale')
            ->where('sale_id', $id)
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })->orderBy('id', 'DESC')->get();

        $due = $Sale->GrandTotal - $Sale->paid_amount;

        return response()->json(['payments' => $payments, 'due' => $due]);

    }

    //------------- Reference Number Order SALE -----------\\

    public function getNumberOrder()
    {
        // Was: read the last row, split the reference, add one -- which handed
        // two simultaneous callers the same number. DocumentSequence claims it
        // atomically, and a unique index on Ref refuses a duplicate outright.
        return DocumentSequence::next('sales', 'SL_', 0, 1111);
    }

    //------------- SALE PDF -----------\\

    public function Sale_PDF(Request $request, $id)
    {
        \App\utils\DocumentAccess::assert('sale');

        $details = array();
        $helpers = new helpers();
        $sale_data = Sale::with('details.product.unitSale')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $sale['client_name'] = $sale_data['client']->name;
        $sale['client_phone'] = $sale_data['client']->phone;
        $sale['client_adr'] = $sale_data['client']->adresse;
        $sale['client_email'] = $sale_data['client']->email;
        $sale['TaxNet'] = number_format($sale_data->TaxNet, 2, '.', '');
        $sale['discount'] = number_format($sale_data->discount, 2, '.', '');
        $sale['shipping'] = number_format($sale_data->shipping, 2, '.', '');
        $sale['statut'] = $sale_data->statut;
        $sale['Ref'] = $sale_data->Ref;
        $sale['date'] = $sale_data->date;
        $sale['GrandTotal'] = number_format($sale_data->GrandTotal, 2, '.', '');
        $sale['payment_status'] = $sale_data->payment_statut;

        // The BIR summary the invoice template prints. Without these the view
        // renders undefined keys.
        $sale['vatable_sales'] = number_format($sale_data->vatable_sales, 2, '.', '');
        $sale['vat_amount'] = number_format($sale_data->vat_amount, 2, '.', '');
        $sale['vat_exempt_sales'] = number_format($sale_data->vat_exempt_sales, 2, '.', '');
        $sale['zero_rated_sales'] = number_format($sale_data->zero_rated_sales, 2, '.', '');
        $sale['vat_rate'] = VatService::rate();
        $sale['statutory_discount'] = number_format($sale_data->statutory_discount, 2, '.', '');
        $sale['statutory_type'] = $sale_data->statutory_type;
        $sale['statutory_label'] = $sale_data->statutory_type === 'senior'
            ? 'Senior Citizen' : ($sale_data->statutory_type === 'pwd' ? 'PWD' : null);
        $sale['statutory_id_number'] = $sale_data->statutory_id_number;
        $sale['statutory_holder_name'] = $sale_data->statutory_holder_name;
        $sale['statutory_beneficiaries'] = $sale_data->statutory_beneficiaries;
        $sale['statutory_total_persons'] = $sale_data->statutory_total_persons;

        $detail_id = 0;
        foreach ($sale_data['details'] as $detail) {

            //check if detail has sale_unit_id Or Null
            if($detail->sale_unit_id !== null){
                $unit = Unit::where('id', $detail->sale_unit_id)->first();
            }else{
                $product_unit_sale_id = Product::with('unitSale')
                ->where('id', $detail->product_id)
                ->first();
                $unit = Unit::where('id', $product_unit_sale_id['unitSale']->id)->first();
            }

            if ($detail->product_variant_id) {

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $data['code'] = $productsVariants->name . '-' . $detail['product']['code'];
            } else {
                $data['code'] = $detail['product']['code'];
            }

                $data['detail_id'] = $detail_id += 1;
                $data['quantity'] = number_format($detail->quantity, 2, '.', '');
                $data['total'] = number_format($detail->total, 2, '.', '');
                $data['name'] = $detail['product']['name'];
                $data['unitSale'] = $unit->ShortName;
                $data['price'] = number_format($detail->price, 2, '.', '');

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = number_format($detail->discount, 2, '.', '');
            } else {
                $data['DiscountNet'] = number_format($detail->price * $detail->discount / 100, 2, '.', '');
            }

            $tax_price = $detail->TaxNet * (($detail->price - $data['DiscountNet']) / 100);
            $data['Unit_price'] = number_format($detail->price, 2, '.', '');
            $data['discount'] = number_format($detail->discount, 2, '.', '');

            if ($detail->tax_method == '1') {
                $data['Net_price'] = $detail->price - $data['DiscountNet'];
                $data['taxe'] = number_format($tax_price, 2, '.', '');
            } else {
                $data['Net_price'] = ($detail->price - $data['DiscountNet']) / (($detail->TaxNet / 100) + 1);
                $data['taxe'] = number_format($detail->price - $data['Net_price'] - $data['DiscountNet'], 2, '.', '');
            }
            // The VAT the line actually carries, as saved when it was sold:
            // the product's rate printed 0.00 on a vatable line and 28.39 on a
            // VAT-exempt senior line (DATATEST K81). Lines saved before the
            // VAT columns existed keep the figure above.
            $hasVatFigures = ((float) $sale_data->vatable_sales + (float) $sale_data->vat_exempt_sales
                + (float) $sale_data->zero_rated_sales) > 0;
            if ($hasVatFigures) {
                $data['taxe'] = number_format((float) $detail->vat_amount, 2, '.', '');
            }

            $details[] = $data;
        }
        $settings = Setting::where('deleted_at', '=', null)->first();
        $symbol = $helpers->Get_Currency_Code();

        $pdf = \PDF::loadView('pdf.sale_pdf', [
            'symbol' => $symbol,
            'setting' => $settings,
            'sale' => $sale,
            'details' => $details,
        ]);

        return $pdf->download('Sale.pdf');

    }

    //----------------Show Form Create Sale ---------------\\

    public function create(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'create', Sale::class);

        $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        $clients = Client::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'xendit_ready' => XenditCheckout::configured(),
            'clients' => $clients,
            'warehouses' => $warehouses,
        ]);

    }

      //------------- Show Form Edit Sale -----------\\

      public function edit(Request $request, $id)
      {
  
          $this->authorizeForUser($request->user('api'), 'update', Sale::class);
          $role = Auth::user()->roles()->first();
          $view_records = Role::findOrFail($role->id)->inRole('record_view');
          $Sale_data = Sale::with('details.product.unitSale')
              ->where('deleted_at', '=', null)
              ->findOrFail($id);
          $details = array();
          // Check If User Has Permission view All Records
          if (!$view_records) {
              // Check If User->id === sale->id
              $this->authorizeForUser($request->user('api'), 'check_record', $Sale_data);
          }
  
          if ($Sale_data->client_id) {
              if (Client::where('id', $Sale_data->client_id)
                  ->where('deleted_at', '=', null)
                  ->first()) {
                  $sale['client_id'] = $Sale_data->client_id;
              } else {
                  $sale['client_id'] = '';
              }
          } else {
              $sale['client_id'] = '';
          }
  
          if ($Sale_data->warehouse_id) {
              if (Warehouse::where('id', $Sale_data->warehouse_id)
                  ->where('deleted_at', '=', null)
                  ->first()) {
                  $sale['warehouse_id'] = $Sale_data->warehouse_id;
              } else {
                  $sale['warehouse_id'] = '';
              }
          } else {
              $sale['warehouse_id'] = '';
          }
  
          $sale['date'] = $Sale_data->date;
          $sale['tax_rate'] = $Sale_data->tax_rate;
          $sale['TaxNet'] = $Sale_data->TaxNet;
          $sale['discount'] = $Sale_data->discount;
          $sale['shipping'] = $Sale_data->shipping;
          $sale['statut'] = $Sale_data->statut;
          $sale['notes'] = $Sale_data->notes;
  
          $detail_id = 0;
          foreach ($Sale_data['details'] as $detail) {

                //check if detail has sale_unit_id Or Null
                if($detail->sale_unit_id !== null){
                    $unit = Unit::where('id', $detail->sale_unit_id)->first();
                    $data['no_unit'] = 1;
                }else{
                    $product_unit_sale_id = Product::with('unitSale')
                    ->where('id', $detail->product_id)
                    ->first();
                    $unit = Unit::where('id', $product_unit_sale_id['unitSale']->id)->first();
                    $data['no_unit'] = 0;
                }
        
              if ($detail->product_variant_id) {
                  $item_product = product_warehouse::where('product_id', $detail->product_id)
                      ->where('deleted_at', '=', null)
                      ->where('product_variant_id', $detail->product_variant_id)
                      ->where('warehouse_id', $Sale_data->warehouse_id)
                      ->first();
  
                  $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                      ->where('id', $detail->product_variant_id)->first();
  
                  $item_product ? $data['del'] = 0 : $data['del'] = 1;
                  $data['product_variant_id'] = $detail->product_variant_id;
                  $data['code'] = $productsVariants->name . '-' . $detail['product']['code'];
                 
                  if ($unit && $unit->operator == '/') {
                      $data['stock'] = $item_product ? $item_product->qte * $unit->operator_value : 0;
                  } else if ($unit && $unit->operator == '*') {
                      $data['stock'] = $item_product ? $item_product->qte / $unit->operator_value : 0;
                  } else {
                      $data['stock'] = 0;
                  }
  
              } else {
                  $item_product = product_warehouse::where('product_id', $detail->product_id)
                      ->where('deleted_at', '=', null)->where('warehouse_id', $Sale_data->warehouse_id)
                      ->where('product_variant_id', '=', null)->first();
  
                  $item_product ? $data['del'] = 0 : $data['del'] = 1;
                  $data['product_variant_id'] = null;
                  $data['code'] = $detail['product']['code'];

                  if ($unit && $unit->operator == '/') {
                      $data['stock'] = $item_product ? $item_product->qte * $unit->operator_value : 0;
                    } else if ($unit && $unit->operator == '*') {
                      $data['stock'] = $item_product ? $item_product->qte / $unit->operator_value : 0;
                  } else {
                      $data['stock'] = 0;
                  }
  
                }
                
                $data['id'] = $detail->id;
                $data['detail_id'] = $detail_id += 1;
                $data['product_id'] = $detail->product_id;
                $data['total'] = $detail->total;
                $data['name'] = $detail['product']['name'];
                $data['quantity'] = $detail->quantity;
                $data['qte_copy'] = $detail->quantity;
                $data['etat'] = 'current';
                $data['unitSale'] = $unit->ShortName;
                $data['sale_unit_id'] = $unit->id;

                if ($detail->discount_method == '2') {
                    $data['DiscountNet'] = $detail->discount;
                } else {
                    $data['DiscountNet'] = $detail->price * $detail->discount / 100;
                }

                $tax_price = $detail->TaxNet * (($detail->price - $data['DiscountNet']) / 100);
                $data['Unit_price'] = $detail->price;
                
                $data['tax_percent'] = $detail->TaxNet;
                $data['tax_method'] = $detail->tax_method;
                $data['discount'] = $detail->discount;
                $data['discount_Method'] = $detail->discount_method;

                if ($detail->tax_method == '1') {
                    $data['Net_price'] = $detail->price - $data['DiscountNet'];
                    $data['taxe'] = $tax_price;
                    $data['subtotal'] = ($data['Net_price'] * $data['quantity']) + ($tax_price * $data['quantity']);
                } else {
                    $data['Net_price'] = ($detail->price - $data['DiscountNet']) / (($detail->TaxNet / 100) + 1);
                    $data['taxe'] = $detail->price - $data['Net_price'] - $data['DiscountNet'];
                    $data['subtotal'] = ($data['Net_price'] * $data['quantity']) + ($tax_price * $data['quantity']);
                }

               $details[] = $data;
          }
  
          $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
          $clients = Client::where('deleted_at', '=', null)->get(['id', 'name']);
  
          return response()->json([
              'details' => $details,
              'sale' => $sale,
              'clients' => $clients,
              'warehouses' => $warehouses,
          ]);
  
      }


    //------------- SEND SALE TO EMAIL -----------\\

    public function Send_Email(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Sale::class);

        $sale = $request->all();
        $pdf = $this->Sale_PDF($request, $sale['id']);
        $this->Set_config_mail(); // Set_config_mail => BaseController
        $mail = Mail::to($request->to)->send(new SaleMail($sale, $pdf));
        return $mail;
    }

    //------------- Show Form Convert To Sale -----------\\

    public function Elemens_Change_To_Sale(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Quotation::class);
        $role = Auth::user()->roles()->first();
        $view_records = Role::findOrFail($role->id)->inRole('record_view');
        $Quotation = Quotation::with('details.product.unitSale')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);
        $details = array();
        // Check If User Has Permission view All Records
        if (!$view_records) {
            // Check If User->id === Quotation->id
            $this->authorizeForUser($request->user('api'), 'check_record', $Quotation);
        }

        if ($Quotation->client_id) {
            if (Client::where('id', $Quotation->client_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $sale['client_id'] = $Quotation->client_id;
            } else {
                $sale['client_id'] = '';
            }
        } else {
            $sale['client_id'] = '';
        }

        if ($Quotation->warehouse_id) {
            if (Warehouse::where('id', $Quotation->warehouse_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $sale['warehouse_id'] = $Quotation->warehouse_id;
            } else {
                $sale['warehouse_id'] = '';
            }
        } else {
            $sale['warehouse_id'] = '';
        }

        $sale['date'] = $Quotation->date;
        $sale['TaxNet'] = $Quotation->TaxNet;
        $sale['tax_rate'] = $Quotation->tax_rate;
        $sale['discount'] = $Quotation->discount;
        $sale['shipping'] = $Quotation->shipping;
        $sale['statut'] = 'pending';
        $sale['notes'] = $Quotation->notes;

        $detail_id = 0;
        foreach ($Quotation['details'] as $detail) {
           
                //check if detail has sale_unit_id Or Null
                if($detail->sale_unit_id !== null){
                    $unit = Unit::where('id', $detail->sale_unit_id)->first();

                if ($detail->product_variant_id) {
                    $item_product = product_warehouse::where('product_id', $detail->product_id)
                        ->where('product_variant_id', $detail->product_variant_id)
                        ->where('warehouse_id', $Quotation->warehouse_id)
                        ->where('deleted_at', '=', null)
                        ->first();
                    $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                        ->where('id', $detail->product_variant_id)->where('deleted_at', null)->first();

                    $item_product ? $data['del'] = 0 : $data['del'] = 1;
                    $data['product_variant_id'] = $detail->product_variant_id;
                    $data['code'] = $productsVariants->name . '-' . $detail['product']['code'];
                
                    if ($unit && $unit->operator == '/') {
                        $data['stock'] = $item_product ? $item_product->qte / $unit->operator_value : 0;
                    } else if ($unit && $unit->operator == '*') {
                        $data['stock'] = $item_product ? $item_product->qte * $unit->operator_value : 0;
                    } else {
                        $data['stock'] = 0;
                    }

                } else {
                    $item_product = product_warehouse::where('product_id', $detail->product_id)
                        ->where('warehouse_id', $Quotation->warehouse_id)
                        ->where('product_variant_id', '=', null)
                        ->where('deleted_at', '=', null)
                        ->first();

                    $item_product ? $data['del'] = 0 : $data['del'] = 1;
                    $data['product_variant_id'] = null;
                    $data['code'] = $detail['product']['code'];

                    if ($unit && $unit->operator == '/') {
                        $data['stock'] = $item_product ? $item_product->qte * $unit->operator_value : 0;
                    } else if ($unit && $unit->operator == '*') {
                        $data['stock'] = $item_product ? $item_product->qte / $unit->operator_value : 0;
                    } else {
                        $data['stock'] = 0;
                    }
                }
                
                $data['id'] = $id;
                $data['detail_id'] = $detail_id += 1;
                $data['quantity'] = $detail->quantity;
                $data['product_id'] = $detail->product_id;
                $data['total'] = $detail->total;
                $data['name'] = $detail['product']['name'];
                $data['etat'] = 'current';
                $data['qte_copy'] = $detail->quantity;
                $data['unitSale'] = $unit->ShortName;
                $data['sale_unit_id'] = $unit->id;

                if ($detail->discount_method == '2') {
                    $data['DiscountNet'] = $detail->discount;
                } else {
                    $data['DiscountNet'] = $detail->price * $detail->discount / 100;
                }
                $tax_price = $detail->TaxNet * (($detail->price - $data['DiscountNet']) / 100);
                $data['Unit_price'] = $detail->price;
                $data['tax_percent'] = $detail->TaxNet;
                $data['tax_method'] = $detail->tax_method;
                $data['discount'] = $detail->discount;
                $data['discount_Method'] = $detail->discount_method;

                if ($detail->tax_method == '1') {
                    $data['Net_price'] = $detail->price - $data['DiscountNet'];
                    $data['taxe'] = $tax_price;
                    $data['subtotal'] = ($data['Net_price'] * $data['quantity']) + ($tax_price * $data['quantity']);
                } else {
                    $data['Net_price'] = ($detail->price - $data['DiscountNet']) / (($detail->TaxNet / 100) + 1);
                    $data['taxe'] = $detail->price - $data['Net_price'] - $data['DiscountNet'];
                    $data['subtotal'] = ($data['Net_price'] * $data['quantity']) + ($tax_price * $data['quantity']);
                }

                $details[] = $data;
            }
        }

        $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        $clients = Client::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'details' => $details,
            'sale' => $sale,
            'clients' => $clients,
            'warehouses' => $warehouses,
        ]);

    }

    //-------------------Sms Notifications -----------------\\
    public function Send_SMS(Request $request)
    {
        // Same permission as e-mailing it (DATATEST K57).
        $this->authorizeForUser($request->user('api'), 'view', Sale::class);
        $sale = Sale::where('deleted_at', '=', null)->findOrFail($request->id);
        $url = url('/api/Sale_PDF/' . $request->id);
        $receiverNumber = $sale['client']->phone;
        $message = "Dear" .' '.$sale['client']->name." \n We are contacting you in regard to a invoice #".$sale->Ref.' '.$url.' '. "that has been created on your account. \n We look forward to conducting future business with you.";
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


    /**
     * Void a sale.
     *
     * Not a delete: the sale stays, marked, with the reason and who authorised
     * it, so the gap it leaves in the invoice numbers is explained rather than
     * merely present. Stock goes back, the payments come off the drawer and both
     * journal entries are reversed.
     *
     * Authorising a void is the 'delete' permission -- removing a sale from the
     * takings is at least as consequential as deleting one, and this is the gate
     * that stops a cashier quietly erasing their own shortfall.
     */
    public function voidSale(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Sale::class);

        $request->validate([
            'reason' => 'required|string|min:3|max:255',
        ]);

        // VoidService reads the sale directly, past the branch scope, so the
        // sale is looked up through the model first: another branch's sale
        // answers 404 (WarehouseAccessTest).
        Sale::findOrFail($id);

        $result = VoidService::void($id, $request->reason);

        if (isset($result['error'])) {
            return response()->json(
                ['message' => VoidService::refusal($result['error'])],
                $result['error'] === 'not_found' ? 404 : 422
            );
        }

        return response()->json($result);
    }
}
