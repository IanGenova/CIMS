<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\PaymentSale;
use App\Models\Product;
use App\Models\Setting;
use App\Models\PosSetting;
use App\Models\ProductVariant;
use App\Models\product_warehouse;
use App\Models\PaymentWithCreditCard;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\SaleDetail;
use App\Models\Warehouse;
use App\utils\BatchService;
use App\utils\helpers;
use App\utils\CurrencyService;
use App\utils\OrderTotals;
use App\utils\PricingService;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\utils\VatService;
use App\utils\StockLedger;
use App\utils\TillService;

class PosController extends BaseController
{

    //------------ Create New  POS --------------\\

    /**
     * A sale sent twice with the same idempotency_key -- a lost reply and a
     * retry -- is recorded once; the repeat answers with the first sale
     * (DATATEST K40). Without a key it runs as it always did.
     */
    public function CreatePOS(Request $request)
    {
        if ($request->filled('idempotency_key')) {
            return \App\utils\Idempotency::respond(Auth::id(), $request->input('idempotency_key'), function () use ($request) {
                return $this->createPosNow($request);
            });
        }

        return $this->createPosNow($request);
    }

    public function createPosNow(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);
        // Checked here as well as in the middleware: an offline sale is
        // replayed straight into this method and never passes it (K14/A42).
        if ($request->filled('warehouse_id')) {
            \App\utils\WarehouseAccess::assertAllows($request['warehouse_id']);
        }
        // A customer and currency that still exist (DATATEST K38).
        \App\utils\LiveReference::assert('clients', $request['client_id'], 'client_id', 'customer');
        \App\utils\LiveReference::assert('currencies', $request['currency_id'], 'currency_id', 'currency', false);
        // Every line: positive quantity, its own unit family and variant, whole units unless divisible (LineRules, DATATEST K6/K13/K20/K23/K53).
        \App\utils\LineRules::check($request['details'] ?? [], 'sale_unit_id', ['stock' => false]);

        $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'GrandTotal' => 'required|numeric|min:0',
            'amount' => 'required|numeric|min:0',
            'payment.Reglement' => 'required|string|max:50',
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
            // Split tender. Optional: a till that sends one payment keeps
            // working exactly as before.
            'payments' => 'nullable|array|min:1',
            'payments.*.Reglement' => 'required_with:payments|string|max:50',
            'payments.*.amount' => 'required_with:payments|numeric|min:0',
            'payments.*.change' => 'nullable|numeric|min:0',
            'payments.*.notes' => 'nullable|string|max:255',
        ]);

        // Money is never taken from the client. Recompute every line and the
        // grand total from the submitted quantities, prices and tax settings.
        // Tax follows the customer's liability (tax group, exempt flag),
        // not whatever the browser posted.
        $details = PricingService::applyToDetails($request['details'], $request->client_id);
        $currency = CurrencyService::stampFor($request->currency_id);

        // Prices already include 12% VAT: an order-level tax would charge it
        // again, outside every VAT bucket (DATATEST K33).
        if ((float) $request->tax_rate > 0 && VatService::pricesIncludeVat()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'tax_rate' => ['Prices already include VAT, so no order tax can be added.'],
            ]);
        }

        $totals = OrderTotals::forOrder($details, [
            'discount' => $request->discount,
            'shipping' => $request->shipping,
            'tax_rate' => $request->tax_rate,
        ], 'Unit_price');

        // The VAT split and the SC/PWD discount come first: the amount owed is
        // what is left after them, and the tender is checked against that
        // (DATATEST K30: it was checked against the total before the discount).
        $vat = VatService::forDocument(
            $details, $totals['lines'], $request->shipping,
            VatService::statutoryFromRequest($request), $request->discount
        );
        $finalTotal = round($vat['summary']['total_due'] + $totals['tax_net'], 2);

        // A sale belongs to the drawer it was rung up on. When the shop has
        // asked for till sessions, there is no selling without one -- that is
        // the whole point of the setting, and a sale with no session cannot be
        // reconciled to any drawer afterwards.
        $tillSession = TillService::currentFor(
            Auth::user()->id, $request->warehouse_id, $request->terminal
        );
        if (! $tillSession && TillService::isRequired()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'till' => ['Open the till before selling. No drawer is open for you at this warehouse.'],
            ]);
        }

        // Split tender: several methods against one sale. The amount applied
        // is the sum of the parts, and it is that sum -- not any one part --
        // which has to be checked against the total.
        $splitTender = is_array($request->payments) && count($request->payments) > 0;
        $amountApplied = (float) $request['amount'];
        if ($splitTender) {
            $amountApplied = 0.0;
            foreach ($request->payments as $i => $part) {
                // Each part is whole centavos: 100.004 + 165 stored rows that
                // never added up to the amount paid (DATATEST K48).
                $partAmount = (float) ($part['amount'] ?? 0);
                if ($partAmount > 0 && ! \App\utils\PaymentStatus::isCentavos($partAmount)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "payments.$i.amount" => ['Each payment must be in whole centavos.'],
                    ]);
                }
                $amountApplied += $partAmount;
            }
            $amountApplied = round($amountApplied, 2);
        }

        // The amount tendered is checked against the total we calculated,
        // not against the total the client claimed.
        if (round($amountApplied * 100) > round($finalTotal * 100)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'amount' => ['The amount paid cannot be more than the order total of ' . number_format($finalTotal, 2) . '.'],
            ]);
        }

        // What is left unpaid goes on the customer's account, which has a limit
        // here as much as in the back office (DATATEST K17).
        \App\utils\CreditService::assertWithinLimit($request->client_id, $finalTotal, $amountApplied);

        try {

        $item = \DB::transaction(function () use ($request, $totals, $details, $currency, $tillSession, $splitTender, $amountApplied, $vat, $finalTotal) {
            // A basket this cashier recalled is still set aside for them; it
            // goes back on the shelf here, in this sale's transaction (K77).
            PosHoldController::releaseRecalledBy(Auth::id(), $request['warehouse_id']);
            // Again under a lock on the customer: the check above is only an
            // early answer, this one holds against a sale on another till (K11).
            \App\utils\CreditService::assertWithinLimit($request->client_id, $finalTotal, $amountApplied);
            StockLedger::context('pos', 'Sale', null);

            $helpers = new helpers();
            $role = Auth::user()->roles()->first();
            $view_records = Role::findOrFail($role->id)->inRole('record_view');
            $order = new Sale;

            // Philippine VAT classification and the statutory SC/PWD discount
            // ($vat, worked out above before the tender was checked).
            $order->is_pos = 1;
            $order->date = Carbon::now();
            $order->Ref = app('App\Http\Controllers\SalesController')->getNumberOrder();
            $order->client_id = $request->client_id;
            $order->warehouse_id = $request->warehouse_id;
            $order->till_session_id = $tillSession ? $tillSession->id : null;
            $order->tax_rate = $request->tax_rate;
            $order->TaxNet = $totals["tax_net"];
            // The promo that stood (zero when the SC/PWD discount was higher, K74).
            $order->discount = $vat['summary']['order_discount_applied'];
            $order->shipping = $request->shipping;
            $order->GrandTotal = $finalTotal;
            $order->statut = 'completed';
            $order->paid_amount = 0;
            $order->payment_statut = 'unpaid';      // never blank (K41); set again once paid
            $order->user_id = Auth::user()->id;
            $order->currency_id = $currency['currency_id'];
            $order->exchange_rate = $currency['exchange_rate'];
            // What is owed, in the base currency -- after the SC/PWD discount (K30).
            $order->base_amount = CurrencyService::toBase(
                $finalTotal, $currency['currency_id'], $currency['exchange_rate']
            );

            // See SalesController: the beneficiary is exempt from the VAT too, so
            // the amount owed comes from the VAT computation rather than from the
            // commercial total less the discount.
            // The order discount is now inside the VAT computation, spread across
            // the lines, so the only thing left outside it is the order-level tax.
            $order->GrandTotal = round($vat['summary']['total_due'] + $totals['tax_net'], 2);
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

            // Now that the sale has an id, movements can name it. Until this
            // point the context had nothing to point at.
            StockLedger::context('pos', 'Sale', $order->id);

            $data = $details;
            $orderDetails = [];
            foreach ($data as $key => $value) {

                $unit = Unit::where('id', $value['sale_unit_id'])
                    ->first();
                $orderDetails[] = [
                    'date' => Carbon::now(),
                    'sale_id' => $order->id,
                    'sale_unit_id' =>  $value['sale_unit_id'],
                    'quantity' => $value['quantity'],
                    'product_id' => $value['product_id'],
                    'product_variant_id' => $value['product_variant_id'],
                    'total' => $totals["lines"][$key]["subtotal"],
                    'price' => $value['Unit_price'],
                    'TaxNet' => $value['tax_percent'],
                    'tax_method' => $value['tax_method'],
                    'discount' => $value['discount'],
                    'discount_method' => $value['discount_Method'],
                    'vat_class' => $vat['lines'][$key]['vat_class'] ?? 'vatable',
                    'sc_pwd_eligible' => $vat['lines'][$key]['sc_pwd_eligible'] ?? true,
                    'vat_amount' => $vat['lines'][$key]['vat'] ?? 0,
                    'statutory_discount' => $vat['lines'][$key]['statutory_discount'] ?? 0,
                ];

                // Serial-tracked: the units sold are named, and their warranty starts.
                if (\App\utils\SerialService::isTracked($value['product_id'])) {
                    $serialQty = $unit && $unit->operator == '/'
                        ? $value['quantity'] / $unit->operator_value
                        : $value['quantity'] * ($unit ? $unit->operator_value : 1);
                    \App\utils\SerialService::sell($value['product_id'], $order->warehouse_id,
                        $value['serials'] ?? [], $serialQty, $order->id, $order->client_id);
                }

                if (BatchService::isTracked($value['product_id'])) {
                    // Batch-tracked: oldest expiry first, expired lots refused.
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
                        'pos',
                        'Sale',
                        $order->id
                    );

                } elseif ($value['product_variant_id'] !== null) {
                    $product_warehouse = product_warehouse::where('warehouse_id', $order->warehouse_id)
                        ->where('product_id', $value['product_id'])->where('product_variant_id', $value['product_variant_id'])
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
                    $product_warehouse = product_warehouse::where('warehouse_id', $order->warehouse_id)
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

            SaleDetail::insert($orderDetails);

            $sale = Sale::findOrFail($order->id);
            // Check If User Has Permission view All Records
            if (!$view_records) {
                // Check If User->id === sale->id
                $this->authorizeForUser($request->user('api'), 'check_record', $sale);
            }

            {

                $total_paid = round($sale->paid_amount + $amountApplied, 2);
                $payment_statut = \App\utils\PaymentStatus::of($sale->GrandTotal, $total_paid);

                // Split tender writes one payment row per method. The till
                // reading already groups the drawer by method, so a sale paid
                // half in cash and half by card lands correctly in both the
                // takings and the cash accountability without further work.
                if ($splitTender) {
                    foreach ($request->payments as $part) {
                        if ((float) ($part['amount'] ?? 0) <= 0) {
                            continue;
                        }
                        PaymentSale::create([
                            'sale_id' => $order->id,
                            'Ref' => app('App\Http\Controllers\PaymentSalesController')->getNumberOrder(),
                            'date' => Carbon::now(),
                            'Reglement' => $part['Reglement'],
                            'montant' => $part['amount'],
                            'change' => $part['change'] ?? 0,
                            'notes' => ($part['notes'] ?? null),
                            'user_id' => Auth::user()->id,
                        ]);
                    }

                    $sale->update([
                        'paid_amount' => $total_paid,
                        'payment_statut' => $payment_statut,
                    ]);
                } elseif($request['amount'] > 0){
                    // Recorded as taken. "credit card" is the shop's own card
                    // terminal; GCash and Maya go through Xendit via the wallet
                    // buttons (WalletPaymentService), which settle before this.
                    {

                        PaymentSale::create([
                            'sale_id' => $order->id,
                            'Ref' => app('App\Http\Controllers\PaymentSalesController')->getNumberOrder(),
                            'date' => Carbon::now(),
                            'Reglement' => $request->payment['Reglement'],
                            'montant' => $request['amount'],
                            'change' => $request['change'] ?? 0,   // not sent: no change given (K68)
                            'notes' => ($request->payment['notes'] ?? null),
                            'user_id' => Auth::user()->id,
                        ]);

                        $sale->update([
                            'paid_amount' => $total_paid,
                            'payment_statut' => $payment_statut,
                        ]);
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
            \Log::error('POS sale could not be completed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'The sale was not completed and no stock was deducted. The payment could not be recorded: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json(['success' => true, 'id' => $item]);

    }

    //------------ Calculate order total (legacy API compatibility) --------------\\

    public function CalculGrandTotal(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $validated = $request->validate([
            'details' => 'required|array|min:1',
            'details.*.subtotal' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
        ]);

        $subtotal = collect($validated['details'])->sum(function ($detail) {
            return (float) $detail['subtotal'];
        });
        $discount = min((float) ($validated['discount'] ?? 0), $subtotal);
        $taxNet = ($subtotal - $discount) * ((float) ($validated['tax_rate'] ?? 0) / 100);
        $grandTotal = $subtotal - $discount + $taxNet + (float) ($validated['shipping'] ?? 0);

        return response()->json([
            'subtotal' => round($subtotal, 2),
            'TaxNet' => round($taxNet, 2),
            'GrandTotal' => round($grandTotal, 2),
        ]);
    }

    public function getArticlesByCategory(Request $request, $id)
    {
        $request->merge(['category_id' => $id]);
        return $this->GetProductsByParametre($request);
    }

    //------------ Get Products--------------\\

    public function GetProductsByParametre(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);
        // How many items do you want to display.
        // Keep in step with product_perPage in pos.vue -- the client draws the
        // pager from its own number, so a mismatch silently hides products.
        $perPage = 18;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $data = array();

        $product_warehouse_data = product_warehouse::where('warehouse_id', $request->warehouse_id)
            ->with('product', 'product.unitSale')
            ->where('deleted_at', '=', null)
            ->where(function ($query) use ($request) {
                if ($request->stock == '1') {
                    // A service has no stock and is never out of it.
                    return $query->where('qte', '>', 0)
                        ->orWhereHas('product', function ($p) { $p->where('is_service', true); });
                }

            })
        // Filter
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('category_id'), function ($query) use ($request) {
                    return $query->whereHas('product', function ($q) use ($request) {
                        $q->where('category_id', '=', $request->category_id);
                    });
                });
            })
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('brand_id'), function ($query) use ($request) {
                    return $query->whereHas('product', function ($q) use ($request) {
                        $q->where('brand_id', '=', $request->brand_id);
                    });
                });
            });

        $totalRows = $product_warehouse_data->count();

        $product_warehouse_data = $product_warehouse_data
            ->offset($offSet)
            // Was hardcoded to 8 while $perPage sat unused directly above it --
            // the offset moved by one number and the limit by another, so any
            // change to the page size silently dropped rows.
            ->limit($perPage)
            ->get();

        foreach ($product_warehouse_data as $product_warehouse) {
            if ($product_warehouse->product_variant_id) {
                $productsVariants = ProductVariant::where('product_id', $product_warehouse->product_id)
                    ->where('id', $product_warehouse->product_variant_id)
                    ->where('deleted_at', null)
                    ->first();

                $item['product_variant_id'] = $product_warehouse->product_variant_id;
                $item['Variant'] = $productsVariants->name;
                $item['code'] = $productsVariants->name . '-' . $product_warehouse['product']->code;

            } else if ($product_warehouse->product_variant_id === null) {
                $item['product_variant_id'] = null;
                $item['Variant'] = null;
                $item['code'] = $product_warehouse['product']->code;
            }
            $item['id'] = $product_warehouse->product_id;
            $item['barcode'] = $product_warehouse['product']->code;
            $item['name'] = $product_warehouse['product']->name;
            $firstimage = explode(',', $product_warehouse['product']->image);
            $item['image'] = $firstimage[0];

            if ($product_warehouse['product']['unitSale']->operator == '/') {
                $item['qte_sale'] = $product_warehouse->qte * $product_warehouse['product']['unitSale']->operator_value;
                $price = $product_warehouse['product']->price / $product_warehouse['product']['unitSale']->operator_value;

            } else {
                $item['qte_sale'] = $product_warehouse->qte / $product_warehouse['product']['unitSale']->operator_value;
                $price = $product_warehouse['product']->price * $product_warehouse['product']['unitSale']->operator_value;

            }
            $item['unitSale'] = $product_warehouse['product']['unitSale']->ShortName;
            $item['qte'] = $product_warehouse->qte;
            $item['is_service'] = (bool) $product_warehouse['product']->is_service;
            $item['has_serials'] = (bool) $product_warehouse['product']->has_serials;
            if ($item['is_service']) {
                $item['qte'] = $item['qte_sale'] = \App\utils\ProductKind::SERVICE_STOCK;
            }

            if ($product_warehouse['product']->TaxNet !== 0.0) {

                //Exclusive
                if ($product_warehouse['product']->tax_method == '1') {
                    $tax_price = $price * $product_warehouse['product']->TaxNet / 100;

                    $item['Net_price'] = $price + $tax_price;

                    // Inxclusive
                } else {
                    $item['Net_price'] = $price;
                }
            } else {
                $item['Net_price'] = $price;
            }

            $data[] = $item;
        }

        return response()->json([
            'products' => $data,
            'totalRows' => $totalRows,
        ]);
    }

    //--------------------- Get Element POS ------------------------\\

    public function GetELementPos(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        $clients = Client::where('deleted_at', '=', null)->get(['id', 'name']);
        $settings = Setting::where('deleted_at', '=', null)->first();
        // $pos_settings = PosSetting::where('deleted_at', '=', null)->first();
        if ($settings->warehouse_id) {
            if (Warehouse::where('id', $settings->warehouse_id)->where('deleted_at', '=', null)->first()) {
                $defaultWarehouse = $settings->warehouse_id;
            } else {
                $defaultWarehouse = '';
            }
        } else {
            $defaultWarehouse = '';
        }

        if ($settings->client_id) {
            if (Client::where('id', $settings->client_id)->where('deleted_at', '=', null)->first()) {
                $defaultClient = $settings->client_id;
            } else {
                $defaultClient = '';
            }
        } else {
            $defaultClient = '';
        }
        $categories = Category::where('deleted_at', '=', null)->get(['id', 'name']);
        $brands = Brand::where('deleted_at', '=', null)->get();

        return response()->json([
            'brands' => $brands,
            // 'pos_settings' => $pos_settings,
            'defaultWarehouse' => $defaultWarehouse,
            'defaultClient' => $defaultClient,
            'clients' => $clients,
            'warehouses' => $warehouses,
            'categories' => $categories,
        ]);
    }

}
