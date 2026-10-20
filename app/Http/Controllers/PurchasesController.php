<?php

namespace App\Http\Controllers;

use App\utils\BatchService;
use App\utils\OrderTotals;
use App\utils\StockLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\PurchasesExport;
use App\Mail\PurchaseMail;
use App\Models\PaymentPurchase;
use App\Models\Product;
use App\Models\Unit;
use App\Models\ProductVariant;
use App\Models\product_warehouse;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Warehouse;
use App\utils\helpers;
use Carbon\Carbon;
use Twilio\Rest\Client as Client_Twilio;
use DB;
use PDF;
use App\utils\DocumentSequence;

class PurchasesController extends BaseController
{

    //------------- Show ALL Purchases ----------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Purchase::class);
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
            2 => 'provider_id',
            3 => 'payment_statut',
            4 => 'warehouse_id',
            5 => 'date',
        );
        $data = array();
        $total = 0;

        // Check If User Has Permission View  All Records
        $Purchases = Purchase::with('facture', 'provider', 'warehouse')
            ->where('deleted_at', '=', null)
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });

        //Multiple Filter
        $Filtred = $helpers->filter($Purchases, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('statut', 'LIKE', "%{$request->search}%")
                        ->orWhere('GrandTotal', $request->search)
                        ->orWhere('payment_statut', 'like', "$request->search")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('provider', function ($q) use ($request) {
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
        $Purchases = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($Purchases as $Purchase) {

            $item['id'] = $Purchase->id;
            $item['date'] = $Purchase->date;
            $item['Ref'] = $Purchase->Ref;
            $item['warehouse_name'] = $Purchase['warehouse']->name;
            $item['discount'] = $Purchase->discount;
            $item['shipping'] = $Purchase->shipping;
            $item['statut'] = $Purchase->statut;
            $item['provider_id'] = $Purchase['provider']->id;
            $item['provider_name'] = $Purchase['provider']->name;
            $item['provider_email'] = $Purchase['provider']->email;
            $item['provider_tele'] = $Purchase['provider']->phone;
            $item['provider_code'] = $Purchase['provider']->code;
            $item['provider_adr'] = $Purchase['provider']->adresse;
            $item['GrandTotal'] = number_format($Purchase->GrandTotal, 2, '.', '');
            $item['paid_amount'] = number_format($Purchase->paid_amount, 2, '.', '');
            $item['due'] = number_format($item['GrandTotal'] - $item['paid_amount'], 2, '.', '');
            $item['payment_status'] = $Purchase->payment_statut;

            $data[] = $item;
        }

        $suppliers = provider::where('deleted_at', '=', null)->get(['id', 'name']);
        $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'totalRows' => $totalRows,
            'purchases' => $data,
            'suppliers' => $suppliers,
            'warehouses' => $warehouses,
        ]);
    }

    //------ Store new Purchase -------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Purchase::class);
        // 30 days back at most, never ahead (DATATEST K55/K37).
        \App\utils\BusinessDate::check($request['date'], 'date');
        // A supplier and currency that still exist: an unknown supplier was a
        // 500 from the foreign key, a deleted one went through (K38/K68).
        \App\utils\LiveReference::assert('providers', $request['supplier_id'], 'supplier_id', 'supplier');
        \App\utils\LiveReference::assert('currencies', $request['currency_id'], 'currency_id', 'currency', false);
        // Every line: positive quantity, its own unit family and variant, whole units unless divisible (LineRules, DATATEST K6/K13/K20/K23/K53).
        \App\utils\LineRules::check($request['details'] ?? [], 'purchase_unit_id', ['stock' => false]);
        \App\utils\DocumentStatus::assert('purchase', $request['statut']);

        request()->validate([
            'supplier_id' => 'required',
            'warehouse_id' => 'required',
            'details' => 'required|array|min:1',
            'details.*.quantity' => 'required|numeric|gt:0',
            'details.*.Unit_cost' => 'required|numeric|min:0',
            'details.*.discount' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
        ]);

        // Serial-tracked goods arrive through a goods receipt, which names
        // each unit; a purchase saved straight as "received" cannot.
        if ($request['statut'] === 'received') {
            \App\utils\QcService::refuseDirectReceipt(array_column((array) $request['details'], 'product_id'));
            \App\utils\SerialService::refuseIfTracked(array_column((array) $request['details'], 'product_id'),
                'a purchase saved as "received"', 'Save the purchase as "ordered" and receive it with a Goods Receipt, which records each serial number.');
        }

        // Money is never taken from the client: recompute it here.
        $totals = OrderTotals::forOrder($request['details'], [
            'discount' => $request->discount,
            'shipping' => $request->shipping,
            'tax_rate' => $request->tax_rate,
        ], 'Unit_cost');

        // A purchase that needs approval cannot be saved straight into stock:
        // "received" skipped the sign-off and put the goods on the shelf while
        // it was still pending (DATATEST K8).
        if ($request['statut'] === 'received'
            && PurchaseApprovalController::initialStatusFor($totals['grand_total']) === 'pending') {
            return response()->json(['message' => 'This purchase is above the approval limit. Save it as ordered, have it approved, then receive it.'], 422);
        }

        \DB::transaction(function () use ($request, $totals) {
            $order = new Purchase;

            $order->date = $request->date;
            $order->Ref = $this->getNumberOrder();
            $order->provider_id = $request->supplier_id;
            $order->GrandTotal = $totals['grand_total'];
            $order->warehouse_id = $request->warehouse_id;
            $order->tax_rate = $request->tax_rate;
            $order->TaxNet = $totals['tax_net'];
            $order->discount = $request->discount;
            $order->shipping = $request->shipping;
            $order->statut = $request->statut;
            // Hold high-value purchases until someone signs them off.
            $order->approval_status = PurchaseApprovalController::initialStatusFor($totals['grand_total']);
            $order->payment_statut = 'unpaid';
            $order->notes = $request->notes;
            $order->user_id = Auth::user()->id;
            // A purchase in dollars stays in dollars, with the rate of the day
            // and its value in pesos for the reports (DATATEST K35).
            $currency = \App\utils\CurrencyService::stampFor($request->currency_id);
            $order->currency_id = $currency['currency_id'];
            $order->exchange_rate = $currency['exchange_rate'];
            $order->base_amount = \App\utils\CurrencyService::toBase(
                $totals['grand_total'], $currency['currency_id'], $currency['exchange_rate']
            );

            $order->save();

            // "Needs approval" to the approvers, or "on order" to the stockroom.
            \App\utils\ActivityNotifier::purchaseRaised($order->id);

            $data = $request['details'];
            foreach ($data as $key => $value) {
                $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                $orderDetails[] = [
                    'purchase_id' => $order->id,
                    'quantity' => $value['quantity'],
                    'cost' => $value['Unit_cost'],
                    'purchase_unit_id' =>  $value['purchase_unit_id'],
                    'TaxNet' => $value['tax_percent'],
                    'tax_method' => $value['tax_method'],
                    'discount' => $value['discount'],
                    'discount_method' => $value['discount_Method'],
                    'product_id' => $value['product_id'],
                    'product_variant_id' => $value['product_variant_id'],
                    'total' => $totals['lines'][$key]['subtotal'],
                    // Received with the purchase itself: nothing is left for a
                    // goods receipt to bring in a second time (DATATEST K7).
                    'received_qte' => $order->statut == "received" ? $value['quantity'] : 0,
                ];

                if ($order->statut == "received") {
                    // Goods arriving at a known price move the weighted
                    // average; goods arriving with no price stated are valued
                    // at whatever the average already is. Receiving through
                    // this screen set no context at all, so a purchase at a new
                    // price landed stock valued at the old average and never
                    // restated it -- every margin after that was worked out
                    // against a cost the company had stopped paying.
                    //
                    // The reference is the purchase itself, so a movement can
                    // be traced back to the document that caused it.
                    $baseQty = $unit && $unit->operator == '/'
                        ? (float) $value['quantity'] / (float) $unit->operator_value
                        : (float) $value['quantity'] * (float) ($unit->operator_value ?? 1);

                    // The landed cost is what was paid after the line discount
                    // (DATATEST K43: the gross price was used).
                    $netUnit = (float) $totals['lines'][$key]['net_price'];
                    StockLedger::context('purchase', 'Purchase', $order->id, [
                        'unit_cost' => $baseQty > 0
                            ? ($netUnit * (float) $value['quantity']) / $baseQty
                            : $netUnit,
                        'notes' => 'Received on purchase ' . $order->Ref,
                    ]);

                    if ($value['product_variant_id'] !== null) {
                        // Made if it is missing: a product arriving in a
                        // warehouse that has never held it is a gap in the
                        // records, not a reason to drop the goods.
                        $product_warehouse = product_warehouse::ensure(
                            $value['product_id'],
                            $order->warehouse_id,
                            $value['product_variant_id']
                        );

                        if ($unit && $product_warehouse) {
                            if ($unit->operator == '/') {
                                $product_warehouse->qte += $value['quantity'] / $unit->operator_value;
                            } else {
                                $product_warehouse->qte += $value['quantity'] * $unit->operator_value;
                            }
                            $product_warehouse->save();
                        }

                    } else {
                        // Made if it is missing -- see the variant branch.
                        $product_warehouse = product_warehouse::ensure(
                            $value['product_id'],
                            $order->warehouse_id
                        );

                        if ($unit && $product_warehouse) {
                            if ($unit->operator == '/') {
                                $product_warehouse->qte += $value['quantity'] / $unit->operator_value;
                            } else {
                                $product_warehouse->qte += $value['quantity'] * $unit->operator_value;
                            }
                            $product_warehouse->save();
                        }
                    }

                    // Cleared per line: the next line has its own cost, and a
                    // context left set would value it at this one's.
                    StockLedger::clear();
                }
            }
            PurchaseDetail::insert($orderDetails);
        }, 10);

        return response()->json(['success' => true, 'message' => 'Purchase Created !!']);
    }

    //--------- Update Purchase  -------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Purchase::class);
        // 30 days back at most, never ahead (DATATEST K55/K37).
        \App\utils\BusinessDate::checkIfChanged('purchases', $id, $request['date'], 'date');
        \App\utils\LiveReference::assert('providers', $request['supplier_id'], 'supplier_id', 'supplier');
        // Every line: positive quantity, its own unit family and variant, whole units unless divisible (LineRules, DATATEST K6/K13/K20/K23/K53).
        \App\utils\LineRules::check($request['details'] ?? [], 'purchase_unit_id', ['stock' => false]);
        \App\utils\DocumentStatus::assert('purchase', $request['statut']);

        request()->validate([
            'warehouse_id' => 'required',
            'supplier_id' => 'required',
            'details' => 'required|array|min:1',
            'details.*.quantity' => 'required|numeric|gt:0',
            'details.*.Unit_cost' => 'required|numeric|min:0',
            'details.*.discount' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
        ]);

        // Serial-tracked goods arrive through a goods receipt, which names
        // each unit; a purchase saved straight as "received" cannot.
        if ($request['statut'] === 'received') {
            \App\utils\QcService::refuseDirectReceipt(array_column((array) $request['details'], 'product_id'));
            \App\utils\SerialService::refuseIfTracked(array_column((array) $request['details'], 'product_id'),
                'a purchase saved as "received"', 'Save the purchase as "ordered" and receive it with a Goods Receipt, which records each serial number.');
        }

        // Money is never taken from the client: recompute it here.
        $totals = OrderTotals::forOrder($request['details'], [
            'discount' => $request->discount,
            'shipping' => $request->shipping,
            'tax_rate' => $request->tax_rate,
        ], 'Unit_cost');

        // Approval follows the amount (DATATEST K24): any increase on an approved
        // order needs signing off again, and an order raised over the limit goes
        // up for approval. Nothing awaiting or refused approval may be received
        // by an edit (K8).
        $existing = Purchase::findOrFail($id);
        $approval = $existing->approval_status;
        $approvalsOn = PurchaseApprovalController::initialStatusFor(PHP_INT_MAX) === 'pending';
        if ($approvalsOn && $totals['grand_total'] > (float) $existing->GrandTotal + 0.005) {
            if ($approval === 'approved') {
                $approval = 'pending';
            } elseif ($approval === 'not_required' || $approval === null) {
                $approval = PurchaseApprovalController::initialStatusFor($totals['grand_total']);
            }
        }
        if ($request['statut'] === 'received' && in_array($approval, ['pending', 'rejected'], true)
            && $existing->statut !== 'received') {
            return response()->json(['message' => $approval === 'rejected'
                ? 'This purchase was rejected and cannot be received.'
                : 'This purchase needs approval before it can be received.'], 422);
        }

        \DB::transaction(function () use ($request, $id, $totals, $approval) {
            $role = Auth::user()->roles()->first();
            $view_records = Role::findOrFail($role->id)->inRole('record_view');
            $current_Purchase = Purchase::findOrFail($id);

            // Check If User Has Permission view All Records
            if (!$view_records) {
                // Check If User->id === Purchase->id
                $this->authorizeForUser($request->user('api'), 'check_record', $current_Purchase);
            }

            $old_purchase_details = PurchaseDetail::where('purchase_id', $id)->get();
            $new_purchase_details = $request['details'];
            $length = sizeof($new_purchase_details);

            // Get Ids for new Details
            $new_products_id = [];
            foreach ($new_purchase_details as $new_detail) {
                $new_products_id[] = $new_detail['id'];
            }

            // Init Data with old Parametre
            $old_products_id = [];
            foreach ($old_purchase_details as $key => $value) {
                $old_products_id[] = $value->id;
               
                //check if detail has purchase_unit_id Or Null
                if($value['purchase_unit_id'] !== null){
                    $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                }else{
                    $product_unit_purchase_id = Product::with('unitPurchase')
                    ->where('id', $value['product_id'])
                    ->first();
                    $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                }

                if($value['purchase_unit_id'] !== null){
                    if ($current_Purchase->statut == "received") {

                        if ($value['product_variant_id'] !== null) {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Purchase->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->where('product_variant_id', $value['product_variant_id'])
                                ->first();

                            if ($unit && $product_warehouse) {
                                if ($unit->operator == '/') {
                                    $product_warehouse->qte -= $value['quantity'] / $unit->operator_value;
                                } else {
                                    $product_warehouse->qte -= $value['quantity'] * $unit->operator_value;
                                }

                                $product_warehouse->save();
                            }

                        } else {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Purchase->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->first();

                            if ($unit && $product_warehouse) {
                                if ($unit->operator == '/') {
                                    $product_warehouse->qte -= $value['quantity'] / $unit->operator_value;
                                } else {
                                    $product_warehouse->qte -= $value['quantity'] * $unit->operator_value;
                                }

                                $product_warehouse->save();
                            }
                        }
                    }

                    // Delete Detail
                    if (!in_array($old_products_id[$key], $new_products_id)) {
                        $PurchaseDetail = PurchaseDetail::findOrFail($value->id);
                        $PurchaseDetail->delete();
                    }
                }

            }

            // Update Data with New request
            foreach ($new_purchase_details as $key => $prod_detail) {
                
                if($prod_detail['no_unit'] !== 0){
                    $unit_prod = Unit::where('id', $prod_detail['purchase_unit_id'])->first();

                    if ($request['statut'] == "received") {
                        // Received by an edit moves the average cost like a receipt
                        // at store() does (DATATEST K1): the ledger needs the unit cost.
                        $baseQty = $unit_prod ? \App\utils\LineRules::toBase((float) $prod_detail['quantity'], $unit_prod) : (float) $prod_detail['quantity'];
                        StockLedger::context('purchase', 'Purchase', (int) $id, [
                            'unit_cost' => $baseQty > 0 ? ((float) $totals['lines'][$key]['net_price'] * (float) $prod_detail['quantity']) / $baseQty : (float) $totals['lines'][$key]['net_price'],
                            'notes' => 'Received on purchase edit',
                        ]);

                        if ($prod_detail['product_variant_id'] !== null) {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $request->warehouse_id)
                                ->where('product_id', $prod_detail['product_id'])
                                ->where('product_variant_id', $prod_detail['product_variant_id'])
                                ->first();

                            if ($unit_prod && $product_warehouse) {
                                if ($unit_prod->operator == '/') {
                                    $product_warehouse->qte += $prod_detail['quantity'] / $unit_prod->operator_value;
                                } else {
                                    $product_warehouse->qte += $prod_detail['quantity'] * $unit_prod->operator_value;
                                }

                                $product_warehouse->save();
                            }

                        } else {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $request->warehouse_id)
                                ->where('product_id', $prod_detail['product_id'])
                                ->first();

                            if ($unit_prod && $product_warehouse) {
                                if ($unit_prod->operator == '/') {
                                    $product_warehouse->qte += $prod_detail['quantity'] / $unit_prod->operator_value;
                                } else {
                                    $product_warehouse->qte += $prod_detail['quantity'] * $unit_prod->operator_value;
                                }

                                $product_warehouse->save();
                            }
                        }

                        StockLedger::clear();
                    }

                    $orderDetails['purchase_id'] = $id;
                    $orderDetails['cost'] = $prod_detail['Unit_cost'];
                    $orderDetails['purchase_unit_id'] = $prod_detail['purchase_unit_id'];
                    $orderDetails['TaxNet'] = $prod_detail['tax_percent'];
                    $orderDetails['tax_method'] = $prod_detail['tax_method'];
                    $orderDetails['discount'] = $prod_detail['discount'];
                    $orderDetails['discount_method'] = $prod_detail['discount_Method'];
                    $orderDetails['quantity'] = $prod_detail['quantity'];
                    $orderDetails['product_id'] = $prod_detail['product_id'];
                    $orderDetails['product_variant_id'] = $prod_detail['product_variant_id'];
                    $orderDetails['total'] = $totals['lines'][$key]['subtotal'];
                    // Received by this save: a goods receipt cannot bring it in again (K7).
                    if ($request['statut'] == "received") {
                        $orderDetails['received_qte'] = $prod_detail['quantity'];
                    }

                    if (!in_array($prod_detail['id'], $old_products_id)) {
                        PurchaseDetail::Create($orderDetails);
                    } else {
                        PurchaseDetail::where('id', $prod_detail['id'])->update($orderDetails);
                    }
                }
            }

            $grand_total = $totals['grand_total'];
            $due = round($grand_total - $current_Purchase->paid_amount, 2);
            if ($due <= 0.0) {
                $payment_statut = 'paid';
            } else if ($due != $grand_total) {
                $payment_statut = 'partial';
            } else {
                $payment_statut = 'unpaid';
            }

            $current_Purchase->update([
                'date' => $request['date'],
                'provider_id' => $request['supplier_id'],
                'warehouse_id' => $request['warehouse_id'],
                'notes' => $request['notes'],
                'tax_rate' => $request['tax_rate'],
                'TaxNet' => $totals['tax_net'],
                'discount' => $request['discount'],
                'shipping' => $request['shipping'],
                'statut' => $request['statut'],
                'GrandTotal' => $grand_total,
                'payment_statut' => $payment_statut,
                'approval_status' => $approval,
            ]);

            if ($approval === 'pending' && $current_Purchase->getOriginal('approval_status') !== 'pending') {
                \App\utils\ActivityNotifier::purchaseRaised($current_Purchase->id);
            }

        }, 10);

        return response()->json(['success' => true, 'message' => 'Purchase Updated !!']);

    }

    //------ Close a purchase short of its ordered quantity -------------\\

    /**
     * The balance is not coming, so stop waiting for it.
     *
     * A purchase's status is worked out from ordered against received, which
     * means a line short by a rejection, a discontinued product, or a supplier
     * who stops answering keeps the order open indefinitely. It stays in the
     * goods-awaiting queue, and anything reading that queue as stock on its way
     * counts goods nobody will ever deliver.
     *
     * This records a decision rather than a delivery. Nothing is received, no
     * stock moves, and the line still reads 60 of 100 because that is what
     * happened. What changes is that somebody has said, on the record and with
     * a reason, that the remaining 40 are not arriving.
     */
    public function closeShort(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Purchase::class);

        $request->validate([
            // The reason is the whole point of the record. An order that simply
            // disappeared from the queue would be worse than one that never
            // left it, because nobody could later ask why.
            'reason' => 'required|string|min:3|max:255',
        ]);

        $purchase = Purchase::where('deleted_at', '=', null)->findOrFail($id);

        if ($purchase->short_closed_at !== null) {
            return response()->json([
                'message' => 'This order was already closed short on '
                    . $purchase->short_closed_at . '.',
            ], 422);
        }

        $outstanding = PurchaseDetail::where('purchase_id', $purchase->id)
            ->whereRaw('quantity > received_qte')
            ->count();

        if ($outstanding === 0) {
            return response()->json([
                'message' => 'Everything on this order has been received, so there is nothing to close.',
            ], 422);
        }

        $purchase->update([
            'statut' => 'received',
            'short_closed_at' => Carbon::now(),
            'short_closed_by' => Auth::user()->id,
            'short_close_reason' => $request->reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order closed with ' . $outstanding . ' line(s) short.',
            'outstanding_lines' => $outstanding,
        ]);
    }

    //------ Delete Purchase -------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Purchase::class);

        \DB::transaction(function () use ($id, $request) {
            $role = Auth::user()->roles()->first();
            $view_records = Role::findOrFail($role->id)->inRole('record_view');
            $current_Purchase = Purchase::findOrFail($id);
            $old_purchase_details = PurchaseDetail::where('purchase_id', $id)->get();

            // Check If User Has Permission view All Records
            if (!$view_records) {
                // Check If User->id === current_Purchase->id
                $this->authorizeForUser($request->user('api'), 'check_record', $current_Purchase);
            }

            // What arrived goes back out -- no more, no less (DATATEST K7): a
            // short-closed order took out every unit ordered, and a delete left its
            // goods receipts live. Arrivals are the lines' received quantities;
            // purchases saved "received" before receipts were recorded hold
            // nothing there, so for those the ordered quantity is what arrived.
            $liveReceipts = \DB::table('goods_receipts')->where('purchase_id', $id)
                ->whereNull('deleted_at')->where('status', '!=', 'cancelled')->pluck('id');
            $legacyReceived = $current_Purchase->statut === 'received' && $liveReceipts->isEmpty()
                && (float) $old_purchase_details->sum('received_qte') === 0.0;

            foreach ($liveReceipts as $receiptId) {
                \App\utils\SerialService::undoReceipt('GoodsReceipt', $receiptId);
                BatchService::reverse('GoodsReceipt', $receiptId);
            }

            foreach ($old_purchase_details as $key => $value) {

                //check if detail has purchase_unit_id Or Null
                if($value['purchase_unit_id'] !== null){
                    $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                }else{
                    $product_unit_purchase_id = Product::with('unitPurchase')
                    ->where('id', $value['product_id'])
                    ->first();
                    $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                }

                $arrived = $legacyReceived ? (float) $value['quantity'] : (float) $value['received_qte'];
                if ($arrived <= 0 || ! $unit || BatchService::isTracked($value['product_id'])) {
                    continue;   // nothing came in, or its lots were reversed above
                }
                $base = \App\utils\LineRules::toBase($arrived, $unit);

                // The average comes back down by what these goods added (K45).
                $cost = \DB::table('stock_movements')->where('product_id', $value['product_id'])->where('quantity', '>', 0)
                    ->where(function ($q) use ($id, $value) {
                        $q->where(function ($w) use ($id) { $w->where('reference_type', 'Purchase')->where('reference_id', $id); })
                          ->orWhere(function ($w) use ($value) { $w->where('reference_type', 'PurchaseDetail')->where('reference_id', $value->id); });
                    })
                    ->selectRaw('SUM(quantity * unit_cost) / NULLIF(SUM(quantity), 0) AS c')->value('c');
                if ($cost !== null) {
                    StockLedger::unwindReceipt($value['product_id'], $base, (float) $cost);
                }

                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                    ->where('warehouse_id', $current_Purchase->warehouse_id)
                    ->where('product_id', $value['product_id'])
                    ->where('product_variant_id', $value['product_variant_id'])
                    ->first();
                if ($product_warehouse) {
                    // Below zero means the goods were sold on: refused (never negative).
                    $product_warehouse->qte -= $base;
                    $product_warehouse->save();
                }
            }

            \DB::table('goods_receipts')->whereIn('id', $liveReceipts)
                ->update(['status' => 'cancelled', 'deleted_at' => Carbon::now()]);

            $current_Purchase->details()->delete();
            $current_Purchase->update([
                'deleted_at' => Carbon::now(),
            ]);

            // Take the purchase and its payments back out of the journal (DATATEST K27).
            \App\utils\LedgerService::reverseDocument('purchase', $id, 'Purchase deleted');
            PaymentPurchase::where('purchase_id', $id)->update([
                'deleted_at' => Carbon::now(),
            ]);

        }, 10);
        return response()->json(['success' => true, 'message' => 'Purchase Deleted !!']);
    }

    //-------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Purchase::class);

        // One way to delete a purchase: the same as deleting it on its own, so
        // only what arrived goes back out, its receipts are undone and the
        // average cost is unwound (DATATEST K7/K45). It all stands or falls together.
        \DB::transaction(function () use ($request) {
            foreach ((array) $request->selectedIds as $purchaseId) {
                StockLedger::fallback('purchase', 'Purchase', (int) $purchaseId);
                $this->destroy($request, $purchaseId);
            }
        }, 10);
        StockLedger::fallback('purchase');

        return response()->json(['success' => true, 'message' => 'Purchase Deleted !!']);
    }

    //------------------------- Send Email

    public function Send_Email(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'view', Purchase::class);

        $purchase = $request->all();
        $pdf = $this->Purchase_pdf($request, $purchase['id']);

        $this->Set_config_mail(); // Set_config_mail => BaseController
        $send_mail = Mail::to($request->to)->send(new PurchaseMail($purchase, $pdf));
        return $send_mail;
    }

    //------ Export Purchase EXCEL -------------\\

    public function exportExcel(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'view', Purchase::class);

        return Excel::download(new PurchasesExport, 'Purchases.xlsx');
    }

    //---------------- Get Details Purchase -----------------\\

    public function show(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'view', Purchase::class);
        $role = Auth::user()->roles()->first();
        $view_records = Role::findOrFail($role->id)->inRole('record_view');
        $purchase = Purchase::with('details.product.unitPurchase')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $details = array();

        // Check If User Has Permission view All Records
        if (!$view_records) {
            // Check If User->id === purchase->id
            $this->authorizeForUser($request->user('api'), 'check_record', $purchase);
        }

        $purchase_data['Ref'] = $purchase->Ref;
        $purchase_data['date'] = $purchase->date;
        $purchase_data['statut'] = $purchase->statut;
        $purchase_data['discount'] = $purchase->discount;
        $purchase_data['shipping'] = $purchase->shipping;
        $purchase_data['tax_rate'] = $purchase->tax_rate;
        $purchase_data['TaxNet'] = $purchase->TaxNet;
        $purchase_data['supplier_name'] = $purchase['provider']->name;
        $purchase_data['supplier_email'] = $purchase['provider']->email;
        $purchase_data['supplier_phone'] = $purchase['provider']->phone;
        $purchase_data['supplier_adr'] = $purchase['provider']->adresse;
        $purchase_data['warehouse'] = $purchase['warehouse']->name;
        $purchase_data['GrandTotal'] = number_format($purchase->GrandTotal, 2, '.', '');
        $purchase_data['paid_amount'] = number_format($purchase->paid_amount, 2, '.', '');
        $purchase_data['due'] = number_format($purchase_data['GrandTotal'] - $purchase_data['paid_amount'], 2, '.', '');
        $purchase_data['payment_status'] = $purchase->payment_statut;

        foreach ($purchase['details'] as $detail) {

             //-------check if detail has purchase_unit_id Or Null
             if($detail->purchase_unit_id !== null){
                $unit = Unit::where('id', $detail->purchase_unit_id)->first();
            }else{
                $product_unit_purchase_id = Product::with('unitPurchase')
                ->where('id', $detail->product_id)
                ->first();
                $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
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
            $data['cost'] = $detail->cost;
            $data['unit_purchase'] = $unit->ShortName;

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = $detail->discount;
            } else {
                $data['DiscountNet'] = $detail->cost * $detail->discount / 100;
            }

            $tax_cost = $detail->TaxNet * (($detail->cost - $data['DiscountNet']) / 100);
            $data['Unit_cost'] = $detail->cost;
            $data['discount'] = $detail->discount;

            if ($detail->tax_method == '1') {

                $data['Net_cost'] = $detail->cost - $data['DiscountNet'];
                $data['taxe'] = $tax_cost;
            } else {
                $data['Net_cost'] = ($detail->cost - $data['DiscountNet']) / (($detail->TaxNet / 100) + 1);
                $data['taxe'] = $detail->cost - $data['Net_cost'] - $data['DiscountNet'];
            }

            $details[] = $data;
        }

        $company = Setting::where('deleted_at', '=', null)->first();

        return response()->json([
            'details' => $details,
            'purchase' => $purchase_data,
            'company' => $company,
        ]);

    }

    //--------------- Get Payments of Purchase ----------------\\

    public function Get_Payments(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'view', PaymentPurchase::class);
        $role = Auth::user()->roles()->first();
        $view_records = Role::findOrFail($role->id)->inRole('record_view');
        $purchase = Purchase::findOrFail($id);

        // Check If User Has Permission view All Records
        if (!$view_records) {
            // Check If User->id === purchase->id
            $this->authorizeForUser($request->user('api'), 'check_record', $purchase);
        }

        $payments = PaymentPurchase::with('purchase')
            ->where('purchase_id', $id)
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })->orderBy('id', 'DESC')->get();

        $due = $purchase->GrandTotal - $purchase->paid_amount;

        return response()->json(['payments' => $payments, 'due' => $due]);
    }

    //--------------- Reference Number of Purchase ----------------\\

    public function getNumberOrder()
    {
        // Was: read the last row, split the reference, add one -- which handed
        // two simultaneous callers the same number. DocumentSequence claims it
        // atomically, and a unique index on Ref refuses a duplicate outright.
        return DocumentSequence::next('purchases', 'PR_', 0, 1111);
    }

    //-------------- purchase PDF -----------\\

    public function Purchase_pdf(Request $request, $id)
    {
        \App\utils\DocumentAccess::assert('purchase');
        $details = array();
        $helpers = new helpers();
        $Purchase_data = Purchase::with('details.product.unitPurchase')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $purchase['supplier_name'] = $Purchase_data['provider']->name;
        $purchase['supplier_phone'] = $Purchase_data['provider']->phone;
        $purchase['supplier_adr'] = $Purchase_data['provider']->adresse;
        $purchase['supplier_email'] = $Purchase_data['provider']->email;
        $purchase['TaxNet'] = number_format($Purchase_data->TaxNet, 2, '.', '');
        $purchase['discount'] = number_format($Purchase_data->discount, 2, '.', '');
        $purchase['shipping'] = number_format($Purchase_data->shipping, 2, '.', '');
        $purchase['statut'] = $Purchase_data->statut;
        $purchase['Ref'] = $Purchase_data->Ref;
        $purchase['date'] = $Purchase_data->date;
        $purchase['GrandTotal'] = number_format($Purchase_data->GrandTotal, 2, '.', '');
        $purchase['payment_status'] = $Purchase_data->payment_statut;

        $detail_id = 0;
        foreach ($Purchase_data['details'] as $detail) {

            //-------check if detail has purchase_unit_id Or Null
            if($detail->purchase_unit_id !== null){
                $unit = Unit::where('id', $detail->purchase_unit_id)->first();
            }else{
                $product_unit_purchase_id = Product::with('unitPurchase')
                ->where('id', $detail->product_id)
                ->first();
                $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
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
                $data['unit_purchase'] = $unit->ShortName;
                $data['cost'] = number_format($detail->cost, 2, '.', '');

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = number_format($detail->discount, 2, '.', '');
            } else {
                $data['DiscountNet'] = number_format($detail->cost * $detail->discount / 100, 2, '.', '');
            }

            $tax_cost = $detail->TaxNet * (($detail->cost - $data['DiscountNet']) / 100);
            $data['Unit_cost'] = number_format($detail->cost, 2, '.', '');
            $data['discount'] = number_format($detail->discount, 2, '.', '');

            if ($detail->tax_method == '1') {

                $data['Net_cost'] = $detail->cost - $data['DiscountNet'];
                $data['taxe'] = number_format($tax_cost, 2, '.', '');
            } else {
                $data['Net_cost'] = ($detail->cost - $data['DiscountNet']) / (($detail->TaxNet / 100) + 1);
                $data['taxe'] = number_format($detail->cost - $data['Net_cost'] - $data['DiscountNet'], 2, '.', '');
            }

            $details[] = $data;
        }

        $settings = Setting::where('deleted_at', '=', null)->first();
        $symbol = $helpers->Get_Currency_Code();

        $pdf = \PDF::loadView('pdf.purchase_pdf', [
            'symbol' => $symbol,
            'setting' => $settings,
            'purchase' => $purchase,
            'details' => $details,
        ]);
        return $pdf->download('Purchase.pdf');

    }

    //---------------- Show Form Create Purchase ---------------\\

    public function create(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'create', Purchase::class);

        $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        $suppliers = Provider::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'warehouses' => $warehouses,
            'suppliers' => $suppliers,
        ]);
    }

    //-------------Show Form Edit Purchase-----------\\

    public function edit(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Purchase::class);
        $role = Auth::user()->roles()->first();
        $view_records = Role::findOrFail($role->id)->inRole('record_view');
        $Purchase_data = Purchase::with('details.product.unitPurchase')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);
        $details = array();
        // Check If User Has Permission view All Records
        if (!$view_records) {
            // Check If User->id === Purchase->id
            $this->authorizeForUser($request->user('api'), 'check_record', $Purchase_data);
        }

        if ($Purchase_data->provider_id) {
            if (Provider::where('id', $Purchase_data->provider_id)->where('deleted_at', '=', null)->first()) {
                $purchase['supplier_id'] = $Purchase_data->provider_id;
            } else {
                $purchase['supplier_id'] = '';
            }
        } else {
            $purchase['supplier_id'] = '';
        }

        if ($Purchase_data->warehouse_id) {
            if (Warehouse::where('id', $Purchase_data->warehouse_id)->where('deleted_at', '=', null)->first()) {
                $purchase['warehouse_id'] = $Purchase_data->warehouse_id;
            } else {
                $purchase['warehouse_id'] = '';
            }
        } else {
            $purchase['warehouse_id'] = '';
        }

        $purchase['date'] = $Purchase_data->date;
        $purchase['tax_rate'] = $Purchase_data->tax_rate;
        $purchase['TaxNet'] = $Purchase_data->TaxNet;
        $purchase['discount'] = $Purchase_data->discount;
        $purchase['shipping'] = $Purchase_data->shipping;
        $purchase['statut'] = $Purchase_data->statut;
        $purchase['notes'] = $Purchase_data->notes;

        $detail_id = 0;
        foreach ($Purchase_data['details'] as $detail) {

            //-------check if detail has purchase_unit_id Or Null
            if($detail->purchase_unit_id !== null){
                $unit = Unit::where('id', $detail->purchase_unit_id)->first();
                $data['no_unit'] = 1;
            }else{
                $product_unit_purchase_id = Product::with('unitPurchase')
                ->where('id', $detail->product_id)
                ->first();
                $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                $data['no_unit'] = 0;
            }

            if ($detail->product_variant_id) {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('deleted_at', '=', null)
                    ->where('product_variant_id', $detail->product_variant_id)
                    ->where('warehouse_id', $Purchase_data->warehouse_id)
                    ->first();

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $item_product ? $data['del'] = 0 : $data['del'] = 1;
                $data['code'] = $productsVariants->name . '-' . $detail['product']['code'];
                $data['product_variant_id'] = $detail->product_variant_id;                

                if ($unit && $unit->operator == '/') {
                    $data['stock'] = $item_product ? $item_product->qte * $unit->operator_value : 0;
                } else if ($unit && $unit->operator == '*') {
                    $data['stock'] = $item_product ? $item_product->qte / $unit->operator_value : 0;
                } else {
                    $data['stock'] = 0;
                }

            } else {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('deleted_at', '=', null)->where('product_variant_id', '=', null)
                    ->where('warehouse_id', $Purchase_data->warehouse_id)->first();

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
            $data['name'] = $detail['product']['name'];
            $data['detail_id'] = $detail_id += 1;
            $data['quantity'] = $detail->quantity;
            $data['product_id'] = $detail->product_id;
            $data['unitPurchase'] = $unit->ShortName;
            $data['purchase_unit_id'] = $unit->id;

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = $detail->discount;
            } else {
                $data['DiscountNet'] = $detail->cost * $detail->discount / 100;
            }

            $tax_cost = $detail->TaxNet * (($detail->cost - $data['DiscountNet']) / 100);
            $data['Unit_cost'] = $detail->cost;
            $data['tax_percent'] = $detail->TaxNet;
            $data['tax_method'] = $detail->tax_method;
            $data['discount'] = $detail->discount;
            $data['discount_Method'] = $detail->discount_method;

            if ($detail->tax_method == '1') {
                $data['Net_cost'] = $detail->cost - $data['DiscountNet'];
                $data['taxe'] = $tax_cost;
                $data['subtotal'] = ($data['Net_cost'] * $data['quantity']) + ($tax_cost * $data['quantity']);
            } else {
                $data['Net_cost'] = ($detail->cost - $data['DiscountNet']) / (($detail->TaxNet / 100) + 1);
                $data['taxe'] = $detail->cost - $data['Net_cost'] - $data['DiscountNet'];
                $data['subtotal'] = ($data['Net_cost'] * $data['quantity']) + ($tax_cost * $data['quantity']);
            }

            $details[] = $data;
        }

        $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        $suppliers = Provider::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'details' => $details,
            'purchase' => $purchase,
            'suppliers' => $suppliers,
            'warehouses' => $warehouses,
        ]);
    }

    //------------------ Send SMS ----------------------------------\\

    public function Send_SMS(Request $request)
    {
        // Same permission as e-mailing it (DATATEST K57).
        $this->authorizeForUser($request->user('api'), 'view', Purchase::class);
        $Purchase = Purchase::where('deleted_at', '=', null)->findOrFail($request->id);
        $url = url('/api/Purchase_PDF/' . $request->id);
        $receiverNumber = $Purchase['provider']->phone;
        $message = "Dear" .' '.$Purchase['provider']->name." \n We are contacting you in regard to a purchase order #".$Purchase->Ref.' '.$url.' '. "that has been created on your account. \n We look forward to conducting future business with you.";
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
