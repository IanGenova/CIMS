<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\utils\ProfitCalculator;
use App\Models\Expense;
use App\Models\PaymentPurchase;
use App\Models\PaymentPurchaseReturns;
use App\Models\PaymentSale;
use App\Models\PaymentSaleReturns;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\product_warehouse;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\PurchaseReturn;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SaleReturn;
use App\Models\User;
use App\Models\Warehouse;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use DB;

class ReportController extends BaseController
{

    //----------------- Sales Chart js -----------------------\\

    public function SalesChart()
    {
        $role = Auth::user()->roles()->first();
        $view_records = Role::findOrFail($role->id)->inRole('record_view');

        // Build an array of the dates we want to show, oldest first
        $dates = collect();
        foreach (range(-6, 0) as $i) {
            $date = Carbon::now()->addDays($i)->format('Y-m-d');
            $dates->put($date, 0);
        }

        $date_range = \Carbon\Carbon::today()->subDays(6);
        // Get the sales counts
        $sales = Sale::where('date', '>=', $date_range)
            ->where('deleted_at', '=', null)
            ->where('statut', '!=', 'voided')
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->groupBy(DB::raw("DATE_FORMAT(date,'%Y-%m-%d')"))
            ->orderBy('date', 'asc')
            ->get([
                DB::raw(DB::raw("DATE_FORMAT(date,'%Y-%m-%d') as date")),
                DB::raw('SUM(GrandTotal) AS count'),
            ])
            ->pluck('count', 'date');

        // Merge the two collections;
        $dates = $dates->merge($sales);

        $data = [];
        $days = [];
        foreach ($dates as $key => $value) {
            $data[] = $value;
            $days[] = $key;
        }

        return response()->json(['data' => $data, 'days' => $days]);

    }

    //----------------- Purchases Chart -----------------------\\

    public function PurchasesChart()
    {

        $role = Auth::user()->roles()->first();
        $view_records = Role::findOrFail($role->id)->inRole('record_view');

        // Build an array of the dates we want to show, oldest first
        $dates = collect();
        foreach (range(-6, 0) as $i) {
            $date = Carbon::now()->addDays($i)->format('Y-m-d');
            $dates->put($date, 0);
        }

        $date_range = \Carbon\Carbon::today()->subDays(6);

        // Get the purchases counts
        $purchases = Purchase::where('date', '>=', $date_range)
            ->where('deleted_at', '=', null)
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->groupBy(DB::raw("DATE_FORMAT(date,'%Y-%m-%d')"))
            ->orderBy('date', 'asc')
            ->get([
                DB::raw(DB::raw("DATE_FORMAT(date,'%Y-%m-%d') as date")),
                DB::raw('SUM(GrandTotal) AS count'),
            ])
            ->pluck('count', 'date');

        // Merge the two collections;
        $dates = $dates->merge($purchases);

        $data = [];
        $days = [];
        foreach ($dates as $key => $value) {
            $data[] = $value;
            $days[] = $key;
        }

        return response()->json(['data' => $data, 'days' => $days]);

    }

    //-------------------- Get Top 5 Customers -------------\\

    public function TopCustomers()
    {
        $role = Auth::user()->roles()->first();
        $view_records = Role::findOrFail($role->id)->inRole('record_view');

        $data = Sale::whereBetween('date', [
            Carbon::now()->startOfMonth(),
            Carbon::now()->endOfMonth(),
        ])->where('sales.deleted_at', '=', null)
            ->where('sales.statut', '!=', 'voided')
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('sales.user_id', '=', Auth::user()->id);
                }
            })

            ->join('clients', 'sales.client_id', '=', 'clients.id')
            ->select(DB::raw('clients.name'), DB::raw("count(*) as value"))
            ->groupBy('clients.name')
            ->orderBy('value', 'desc')
            ->take(5)
            ->get();

        return response()->json($data);
    }

    //----------------- report dashboard with_echart -----------------------\\

    public function report_with_echart()
    {
        $dataSales = $this->SalesChart();
        $datapurchases = $this->PurchasesChart();
        $Payment_chart = $this->Payment_chart();
        $TopCustomers = $this->TopCustomers();
        $Top_Products_Year = $this->Top_Products_Year();
        $report_dashboard = $this->report_dashboard();

        return response()->json([
            'sales' => $dataSales,
            'purchases' => $datapurchases,
            'payments' => $Payment_chart,
            'customers' => $TopCustomers,
            'product_report' => $Top_Products_Year,
            'report_dashboard' => $report_dashboard,
        ]);

    }

    //----------------- Payment Chart js -----------------------\\

    public function Payment_chart()
    {

        $role = Auth::user()->roles()->first();
        $view_records = Role::findOrFail($role->id)->inRole('record_view');

        // Build an array of the dates we want to show, oldest first
        $dates = collect();
        foreach (range(-6, 0) as $i) {
            $date = Carbon::now()->addDays($i)->format('Y-m-d');
            $dates->put($date, 0);
        }

        $date_range = \Carbon\Carbon::today()->subDays(6);
        // Get the sales counts
        $Payment_Sale = PaymentSale::where('date', '>=', $date_range)
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->groupBy(DB::raw("DATE_FORMAT(date,'%Y-%m-%d')"))
            ->orderBy('date', 'asc')
            ->get([
                DB::raw(DB::raw("DATE_FORMAT(date,'%Y-%m-%d') as date")),
                DB::raw('SUM(montant) AS count'),
            ])
            ->pluck('count', 'date');

        $Payment_Sale_Returns = PaymentSaleReturns::where('date', '>=', $date_range)
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->groupBy(DB::raw("DATE_FORMAT(date,'%Y-%m-%d')"))
            ->orderBy('date', 'asc')
            ->get([
                DB::raw(DB::raw("DATE_FORMAT(date,'%Y-%m-%d') as date")),
                DB::raw('SUM(montant) AS count'),
            ])
            ->pluck('count', 'date');

        $Payment_Purchases = PaymentPurchase::where('date', '>=', $date_range)
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->groupBy(DB::raw("DATE_FORMAT(date,'%Y-%m-%d')"))
            ->orderBy('date', 'asc')
            ->get([
                DB::raw(DB::raw("DATE_FORMAT(date,'%Y-%m-%d') as date")),
                DB::raw('SUM(montant) AS count'),
            ])
            ->pluck('count', 'date');

        $Payment_Purchase_Returns = PaymentPurchaseReturns::where('date', '>=', $date_range)
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->groupBy(DB::raw("DATE_FORMAT(date,'%Y-%m-%d')"))
            ->orderBy('date', 'asc')
            ->get([
                DB::raw(DB::raw("DATE_FORMAT(date,'%Y-%m-%d') as date")),
                DB::raw('SUM(montant) AS count'),
            ])
            ->pluck('count', 'date');

        $Payment_Expense = Expense::where('date', '>=', $date_range)
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->groupBy(DB::raw("DATE_FORMAT(date,'%Y-%m-%d')"))
            ->orderBy('date', 'asc')
            ->get([
                DB::raw(DB::raw("DATE_FORMAT(date,'%Y-%m-%d') as date")),
                DB::raw('SUM(amount) AS count'),
            ])
            ->pluck('count', 'date');

        $paymen_recieved = $this->array_merge_numeric_values($Payment_Sale, $Payment_Purchase_Returns);
        $payment_sent = $this->array_merge_numeric_values($Payment_Purchases, $Payment_Sale_Returns, $Payment_Expense);

        $dates_recieved = $dates->merge($paymen_recieved);
        $dates_sent = $dates->merge($payment_sent);

        $data_recieved = [];
        $data_sent = [];
        $days = [];
        foreach ($dates_recieved as $key => $value) {
            $data_recieved[] = $value;
            $days[] = $key;
        }

        foreach ($dates_sent as $key => $value) {
            $data_sent[] = $value;
        }

        return response()->json([
            'payment_sent' => $data_sent,
            'payment_received' => $data_recieved,
            'days' => $days,
        ]);

    }

    //----------------- array merge -----------------------\\

    public function array_merge_numeric_values()
    {
        $arrays = func_get_args();
        $merged = array();
        foreach ($arrays as $array) {
            foreach ($array as $key => $value) {
                if (!is_numeric($value)) {
                    continue;
                }
                if (!isset($merged[$key])) {
                    $merged[$key] = $value;
                } else {
                    $merged[$key] += $value;
                }
            }
        }
        return $merged;
    }

    //----------- Get Last 5 Sales --------------\\

    public function Get_last_Sales()
    {

        $Role = Auth::user()->roles()->first();
        $ShowRecord = Role::findOrFail($Role->id)->inRole('record_view');

        $Sales = Sale::with('details', 'client', 'facture')->where('deleted_at', '=', null)
            ->where(function ($query) use ($ShowRecord) {
                if (!$ShowRecord) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->orderBy('id', 'desc')
            ->take(5)
            ->get();

        // Declared before the loop, not inside it. A shop that has not sold
        // anything yet -- which every installation is on its first day -- has
        // nothing to iterate, and returning an undeclared variable made the
        // dashboard answer with a 500 instead of an empty list.
        $data = [];

        foreach ($Sales as $Sale) {

            $item['Ref'] = $Sale['Ref'];
            $item['statut'] = $Sale['statut'];
            $item['client_name'] = $Sale['client']['name'];
            $item['GrandTotal'] = $Sale['GrandTotal'];
            $item['paid_amount'] = $Sale['paid_amount'];
            $item['due'] = $Sale['GrandTotal'] - $Sale['paid_amount'];
            $item['payment_status'] = $Sale['payment_statut'];

            $data[] = $item;
        }

        return response()->json($data);
    }

    //-------------------- Get Top 5 Products This YEAR -------------\\

    public function Top_Products_Year()
    {

        $role = Auth::user()->roles()->first();
        $view_records = Role::findOrFail($role->id)->inRole('record_view');

        $products = SaleDetail::join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->join('products', 'sale_details.product_id', '=', 'products.id')
            ->whereBetween('sale_details.date', [
                Carbon::now()->startOfYear(),
                Carbon::now()->endOfYear(),
            ])
            ->where('sales.statut', '!=', 'voided')
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('sales.user_id', '=', Auth::user()->id);
                }
            })
            ->select(
                DB::raw('products.name as name'),
                DB::raw('sum(sale_details.total) as value'),
            )
            ->groupBy('products.name')
            ->orderBy('value', 'desc')
            ->take(5)
            ->get();

        // return $products;

        return response()->json($products);
    }

    //-------------------- Top products this month -------------\\

    public function TopProducts_Month()
    {
        $role = Auth::user()->roles()->first();
        $viewRecords = Role::findOrFail($role->id)->inRole('record_view');

        $products = SaleDetail::join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->join('products', 'sale_details.product_id', '=', 'products.id')
            ->whereBetween('sale_details.date', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])
            ->where('sales.deleted_at', null)
            ->where('sales.statut', 'completed')
            ->where(function ($query) use ($viewRecords) {
                if (!$viewRecords) {
                    $query->where('sales.user_id', Auth::id());
                }
            })
            ->select('products.name', DB::raw('SUM(sale_details.total) as value'))
            ->groupBy('products.name')
            ->orderByDesc('value')
            ->take(5)
            ->get();

        return response()->json($products);
    }

    public function Products_Alert(Request $request)
    {
        return app(ProductsController::class)->Products_Alert($request);
    }

    public function SalesPurchasesChart()
    {
        $sales = $this->SalesChart()->getData(true);
        $purchases = $this->PurchasesChart()->getData(true);

        return response()->json([
            'days' => $sales['days'],
            'sales' => $sales['data'],
            'purchases' => $purchases['data'],
        ]);
    }

    public function report_today(Request $request)
    {
        $dashboard = $this->report_dashboard()->getData(true);
        return response()->json($dashboard['report'] ?? []);
    }

    //-------------------- General Report dashboard -------------\\

    public function report_dashboard()
    {

        $Role = Auth::user()->roles()->first();
        $view_records = Role::findOrFail($Role->id)->inRole('record_view');

        // top selling product this month
        // Completed sales only, and every line counted in the product's own
        // sale unit: a box of ten bags showed as "1 bag" (DATATEST K82).
        $factor = function ($u) {
            return "(CASE WHEN {$u}.id IS NULL THEN 1 WHEN {$u}.operator = '/' THEN 1 / {$u}.operator_value ELSE {$u}.operator_value END)";
        };
        $products = SaleDetail::join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->join('products', 'sale_details.product_id', '=', 'products.id')
            ->join('units', 'products.unit_sale_id', '=', 'units.id')
            ->leftJoin('units as line_unit', 'line_unit.id', '=', 'sale_details.sale_unit_id')
            ->whereBetween('sale_details.date', [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth(),
            ])
            ->whereNull('sales.deleted_at')
            ->where('sales.statut', 'completed')
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('sales.user_id', '=', Auth::user()->id);
                }
            })
            ->select(
                DB::raw('products.name as name'),
                DB::raw('units.ShortName as unit_product'),
                DB::raw('count(*) as count'),
                DB::raw('sum(total) as total'),
                DB::raw('sum(sale_details.quantity * ' . $factor('line_unit') . ') / MAX(' . $factor('units') . ') as quantity'),
            )
            ->groupBy('products.name', 'units.ShortName')
            ->orderBy('count', 'desc')
            ->take(5)
            ->get();

        // Stock Alerts
        // Only for someone who handles stock, and only their own warehouses: a
        // user with no permissions, and a Branch cashier, read Main's shelves
        // here (DATATEST K92/K14).
        $seesStock = \App\utils\DocumentAccess::hasAny(['Reports_quantity_alerts', 'Warehouse_report', 'products_view',
            'Pos_view', 'Sales_add', 'Purchases_add', 'transfer_add', 'adjustment_add', 'stock_count_view']);
        $allowed = \App\utils\WarehouseAccess::allowedIds();
        $product_warehouse_data = ! $seesStock ? collect() : product_warehouse::with('warehouse', 'product' ,'productVariant')
        ->join('products', 'product_warehouse.product_id', '=', 'products.id')
        ->whereRaw('qte <= stock_alert')
        ->where('product_warehouse.deleted_at', null)
        ->when($allowed !== null, function ($q) use ($allowed) {
            $q->whereIn('product_warehouse.warehouse_id', $allowed);
        })
        ->take('5')->get();

        $stock_alert = [];
        if ($product_warehouse_data->isNotEmpty()) {

            foreach ($product_warehouse_data as $product_warehouse) {
                if ($product_warehouse->qte <= $product_warehouse['product']->stock_alert) {
                    if ($product_warehouse->product_variant_id !== null) {
                        $item['code'] = $product_warehouse['productVariant']->name . '-' . $product_warehouse['product']->code;
                    } else {
                        $item['code'] = $product_warehouse['product']->code;
                    }
                    $item['quantity'] = $product_warehouse->qte;
                    $item['name'] = $product_warehouse['product']->name;
                    $item['warehouse'] = $product_warehouse['warehouse']->name;
                    $item['stock_alert'] = $product_warehouse['product']->stock_alert;
                    $stock_alert[] = $item;
                }
            }

        }
       
        // Today's revenue and gross profit: the same calculation as Profit &
        // Loss (ProfitCalculator), so the two screens always agree.
        $today = \Carbon\Carbon::today()->toDateString();
        $calc = ProfitCalculator::forPeriod($today, $today, $view_records ? null : Auth::user()->id);

        $data['Amount_EXP'] = Expense::where('deleted_at', '=', null)
        ->where('date', \Carbon\Carbon::today()->startOFDay())
        ->where(function ($query) use ($view_records) {
            if (!$view_records) {
                return $query->where('user_id', '=', Auth::user()->id);
            }
        })
        ->get(DB::raw('SUM(amount)  As sum'))
        ->first()->sum;


        // Completed sales only, in pesos: an order or a pending sale has not
        // been sold yet (DATATEST K82/K35).
        $data['today_sales'] = Sale::where('deleted_at', '=', null)
        ->where('statut', 'completed')
        ->where('date', \Carbon\Carbon::today())
        ->where(function ($query) use ($view_records) {
            if (!$view_records) {
                return $query->where('user_id', '=', Auth::user()->id);
            }
        })
        ->get(DB::raw('SUM(COALESCE(base_amount, GrandTotal))  As sum'))
        ->first()->sum;

        $data['return_sales'] = SaleReturn::where('deleted_at', '=', null)
        ->where('date', \Carbon\Carbon::today())
        ->where(function ($query) use ($view_records) {
            if (!$view_records) {
                return $query->where('user_id', '=', Auth::user()->id);
            }
        })
        ->get(DB::raw('SUM(GrandTotal)  As sum'))
        ->first()->sum; 

        $data['revenue'] = $calc['revenue'];

        $data['today_purchases'] = Purchase::where('deleted_at', '=', null)
        ->where('date', \Carbon\Carbon::today())
        ->where(function ($query) use ($view_records) {
            if (!$view_records) {
                return $query->where('user_id', '=', Auth::user()->id);
            }
        })
        ->get(DB::raw('SUM(GrandTotal)  As sum'))
        ->first()->sum;

        $data['purchases_return'] = PurchaseReturn::where('deleted_at', '=', null)
        ->where('date', \Carbon\Carbon::today())
        ->where(function ($query) use ($view_records) {
            if (!$view_records) {
                return $query->where('user_id', '=', Auth::user()->id);
            }
        })
        ->get(DB::raw('SUM(GrandTotal)  As sum'))
        ->first()->sum;

        // A supplier return is not income, so it is no longer added (ReportProfitTest).
        // Profit only for those allowed the Profit & Loss report (DATATEST K56).
        $data['profit'] = \App\utils\DocumentAccess::hasAny(['Reports_profit']) ? $calc['gross_profit'] : null;
        // - $data['Amount_EXP'];

        $last_sales = [];

        //last sales
        $Sales = Sale::with('details', 'client', 'facture')->where('deleted_at', '=', null)
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->orderBy('id', 'desc')
            ->take(5)
            ->get();

        foreach ($Sales as $Sale) {

            $item_sale['Ref'] = $Sale['Ref'];
            $item_sale['statut'] = $Sale['statut'];
            $item_sale['client_name'] = $Sale['client']['name'];
            $item_sale['GrandTotal'] = $Sale['GrandTotal'];
            $item_sale['paid_amount'] = $Sale['paid_amount'];
            $item_sale['due'] = $Sale['GrandTotal'] - $Sale['paid_amount'];
            $item_sale['payment_status'] = $Sale['payment_statut'];

            $last_sales[] = $item_sale;
        }

        return response()->json([
            'products' => $products,
            'stock_alert' => $stock_alert,
            'report' => $data,
            'last_sales' => $last_sales,
        ]);

    }

    //----------------- Customers Report -----------------------\\

    public function Client_Report(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'Reports_customers', Client::class);

        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $data = array();

        $clients = Client::where('deleted_at', '=', null)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('code', 'LIKE', "%{$request->search}%")
                        ->orWhere('phone', 'LIKE', "%{$request->search}%");
                });
            });

        $totalRows = $clients->count();
        $clients = $clients->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($clients as $client) {
            // A voided sale is neither a sale nor a debt; see ReportVoidedSalesTest.
            $item['total_sales'] = DB::table('sales')
                ->where('deleted_at', '=', null)
                ->where('statut', '!=', 'voided')
                ->where('client_id', $client->id)
                ->count();

            $item['total_amount'] = DB::table('sales')
                ->where('deleted_at', '=', null)
                ->where('statut', '!=', 'voided')
                ->where('client_id', $client->id)
                ->sum('GrandTotal');

            $item['total_paid'] = DB::table('sales')
                ->leftjoin('payment_sales', 'sales.id', '=', 'payment_sales.sale_id')
                ->where('sales.deleted_at', '=', null)
                ->where('sales.statut', '!=', 'voided')
                ->where('payment_sales.deleted_at', '=', null)
                ->where('sales.client_id', $client->id)
                ->sum('payment_sales.montant');

            $item['due'] = $item['total_amount'] - $item['total_paid'];
            $item['name'] = $client->name;
            $item['phone'] = $client->phone;
            $item['code'] = $client->code;
            $item['id'] = $client->id;
            $data[] = $item;
        }

        return response()->json([
            'report' => $data,
            'totalRows' => $totalRows,
        ]);

    }

    //----------------- Customers Report By ID-----------------------\\

    public function Client_Report_detail(request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'Reports_customers', Client::class);

        $client = Client::where('deleted_at', '=', null)->findOrFail($id);

        // A voided sale is neither a sale nor a debt; see ReportVoidedSalesTest.
        $data['total_sales'] = DB::table('sales')->where('deleted_at', '=', null)->where('statut', '!=', 'voided')
            ->where('client_id', $id)->count();

        $data['total_amount'] = DB::table('sales')->where('deleted_at', '=', null)->where('statut', '!=', 'voided')
            ->where('client_id', $id)
            ->sum('GrandTotal');

        $data['total_paid'] = DB::table('sales')
            ->where('sales.deleted_at', '=', null)
            ->where('sales.statut', '!=', 'voided')
            ->join('payment_sales', 'sales.id', '=', 'payment_sales.sale_id')
            ->where('payment_sales.deleted_at', '=', null)
            ->where('sales.client_id', $client->id)->sum('payment_sales.montant');

        // What the customer owes is the credit statement's balance: completed
        // sales less payments and less returns not refunded. Working it out here
        // again ignored returns, so the two screens disagreed (DATATEST K31).
        $balance = \App\utils\CreditService::balanceFor($client->id);
        $data['returned'] = $balance['returned'];
        $data['due'] = $balance['balance'];

        return response()->json(['report' => $data]);
    }

    //----------------- Provider Report By ID-----------------------\\

    public function Provider_Report_detail(request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'Reports_suppliers', Provider::class);

        $provider = Provider::where('deleted_at', '=', null)->findOrFail($id);

        // Received goods less returns and payments (DATATEST K32).
        $data = \App\utils\PayablesService::forSupplier($id);

        return response()->json(['report' => $data]);

    }

    //-------------------- Get Sales By Clients -------------\\

    public function Sales_Client(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'Reports_customers', Client::class);
        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;

        $Role = Auth::user()->roles()->first();
        $ShowRecord = Role::findOrFail($Role->id)->inRole('record_view');

        $sales = Sale::where('deleted_at', '=', null)->with('client')
            ->where(function ($query) use ($ShowRecord) {
                if (!$ShowRecord) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->where('client_id', $request->id);

        $totalRows = $sales->count();
        $sales = $sales->offset($offSet)
            ->limit($perPage)
            ->orderBy('id', 'desc')
            ->get();

        $data = [];
        foreach ($sales as $sale) {
            $item['date'] = $sale->date;
            $item['Ref'] = $sale->Ref;
            $item['statut'] = $sale->statut;
            $item['GrandTotal'] = $sale->GrandTotal;
            $item['paid_amount'] = $sale->paid_amount;
            $item['due'] = $sale->GrandTotal - $sale->paid_amount;
            $item['payment_status'] = $sale->payment_statut;

            $data[] = $item;
        }
        return response()->json([
            'totalRows' => $totalRows,
            'sales' => $data,
        ]);

    }

    //-------------------- Get Payments By Clients -------------\\

    public function Payments_Client(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'Reports_customers', Client::class);
        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;

        $Role = Auth::user()->roles()->first();
        $ShowRecord = Role::findOrFail($Role->id)->inRole('record_view');

        $payments = DB::table('payment_sales')
            ->where(function ($query) use ($ShowRecord) {
                if (!$ShowRecord) {
                    return $query->where('payment_sales.user_id', '=', Auth::user()->id);
                }
            })
            ->where('payment_sales.deleted_at', '=', null)
            ->join('sales', 'payment_sales.sale_id', '=', 'sales.id')
            ->where('sales.client_id', $request->id)
            ->select(
                'payment_sales.date', 'payment_sales.Ref AS Ref', 'sales.Ref AS Sale_Ref',
                'payment_sales.Reglement', 'payment_sales.montant'
            );

        $totalRows = $payments->count();
        $payments = $payments->offset($offSet)
            ->limit($perPage)
            ->orderBy('payment_sales.id', 'desc')
            ->get();

        return response()->json([
            'payments' => $payments,
            'totalRows' => $totalRows,
        ]);

    }

    //-------------------- Get Quotations By Clients -------------\\

    public function Quotations_Client(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'Reports_customers', Client::class);
        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;

        $Role = Auth::user()->roles()->first();
        $ShowRecord = Role::findOrFail($Role->id)->inRole('record_view');

        $Quotations = Quotation::where('deleted_at', '=', null)
            ->with('client')
            ->where('client_id', $request->id)
            ->where(function ($query) use ($ShowRecord) {
                if (!$ShowRecord) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });
        $totalRows = $Quotations->count();
        $Quotations = $Quotations->offset($offSet)
            ->limit($perPage)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'quotations' => $Quotations,
            'totalRows' => $totalRows,
        ]);
    }

    //-------------------- Get Returns By Client -------------\\

    public function Returns_Client(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'Reports_customers', Client::class);
        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $data = array();

        //  Check If User Has Permission Show All Records
        $Role = Auth::user()->roles()->first();
        $ShowRecord = Role::findOrFail($Role->id)->inRole('record_view');

        $SaleReturn = SaleReturn::where('deleted_at', '=', null)->with('client')
            ->where('client_id', $request->id)
            ->where(function ($query) use ($ShowRecord) {
                if (!$ShowRecord) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });

        $totalRows = $SaleReturn->count();
        $SaleReturn = $SaleReturn->offset($offSet)
            ->limit($perPage)
            ->orderBy('id', 'desc')
            ->get();

        foreach ($SaleReturn as $Sale_Return) {
            $item['Ref'] = $Sale_Return->Ref;
            $item['statut'] = $Sale_Return->statut;
            $item['client_name'] = $Sale_Return['client']->name;
            $item['GrandTotal'] = $Sale_Return->GrandTotal;
            $item['paid_amount'] = $Sale_Return->paid_amount;
            $item['due'] = $Sale_Return->GrandTotal - $Sale_Return->paid_amount;
            $item['payment_status'] = $Sale_Return->payment_statut;

            $data[] = $item;
        }

        return response()->json([
            'totalRows' => $totalRows,
            'returns_customer' => $data,
        ]);
    }

    //------------- Show Report Purchases ----------\\

    public function Report_Purchases(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'ReportPurchases', Purchase::class);
        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers();
        // Filter fields With Params to retrieve
        $param = array(0 => 'like', 1 => 'like', 2 => '=', 3 => 'like' , 4 => '=');
        $columns = array(0 => 'Ref', 1 => 'statut', 2 => 'provider_id', 3 => 'payment_statut' , 4 => 'date');
        $data = array();
        $total = 0;

        $Purchases = Purchase::select('purchases.*')
            ->with('facture', 'provider', 'warehouse')
            ->join('providers', 'purchases.provider_id', '=', 'providers.id')
            ->where('purchases.deleted_at', '=', null);

        //  Check If User Has Permission Show All Records
        $Purchases = $helpers->Show_Records($Purchases);
        //Multiple Filter
        $Filtred = $helpers->filter($Purchases, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('purchases.Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('purchases.statut', 'LIKE', "%{$request->search}%")
                        ->orWhere('purchases.GrandTotal', $request->search)
                        ->orWhere('purchases.payment_statut', 'like', "$request->search")
                        ->orWhere('providers.name', 'LIKE', "%{$request->search}%");
                });
            });

        $totalRows = $Filtred->count();
        $Purchases = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy('purchases.' . $order, $dir)
            ->get();

        foreach ($Purchases as $Purchase) {

            $item['id'] = $Purchase->id;
            $item['date'] = $Purchase->date;
            $item['Ref'] = $Purchase->Ref;
            $item['warehouse_name'] = $Purchase['warehouse']->name;
            $item['discount'] = $Purchase->discount;
            $item['shipping'] = $Purchase->shipping;
            $item['statut'] = $Purchase->statut;
            $item['provider_name'] = $Purchase['provider']->name;
            $item['provider_email'] = $Purchase['provider']->email;
            $item['provider_tele'] = $Purchase['provider']->phone;
            $item['provider_code'] = $Purchase['provider']->code;
            $item['provider_adr'] = $Purchase['provider']->adresse;
            $item['GrandTotal'] = $Purchase['GrandTotal'];
            $item['paid_amount'] = $Purchase['paid_amount'];
            $item['due'] = $Purchase['GrandTotal'] - $Purchase['paid_amount'];
            $item['payment_status'] = $Purchase['payment_statut'];

            $data[] = $item;
        }

        $suppliers = provider::where('deleted_at', '=', null)->get(['id', 'name']);
        return response()->json([
            'totalRows' => $totalRows,
            'purchases' => $data,
            'suppliers' => $suppliers,
        ]);
    }

    //------------- Show Report SALES -----------\\

    public function Report_Sales(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Reports_sales', Sale::class);
        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers();
        // Filter fields With Params to retrieve
        $param = array(0 => 'like', 1 => 'like', 2 => '=', 3 => 'like' , 4 => '=');
        $columns = array(0 => 'Ref', 1 => 'statut', 2 => 'client_id', 3 => 'payment_statut' , 4 => 'date');
        $data = array();

        $Sales = Sale::select('sales.*')
            ->with('facture', 'client', 'warehouse')
            ->join('clients', 'sales.client_id', '=', 'clients.id')
            ->where('sales.deleted_at', '=', null);

        //  Check If User Has Permission Show All Records
        $Sales = $helpers->Show_Records($Sales);
        //Multiple Filter
        $Filtred = $helpers->filter($Sales, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('sales.Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('sales.statut', 'LIKE', "%{$request->search}%")
                        ->orWhere('sales.GrandTotal', $request->search)
                        ->orWhere('sales.payment_statut', 'like', "$request->search")
                        ->orWhere('clients.name', 'LIKE', "%{$request->search}%");
                });
            });

        $totalRows = $Filtred->count();
        $Sales = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy('sales.' . $order, $dir)
            ->get();

        foreach ($Sales as $Sale) {

            $item['id'] = $Sale['id'];
            $item['date'] = $Sale['date'];
            $item['Ref'] = $Sale['Ref'];
            $item['statut'] = $Sale['statut'];
            $item['discount'] = $Sale['discount'];
            $item['shipping'] = $Sale['shipping'];
            $item['warehouse_name'] = $Sale['warehouse']['name'];
            $item['client_name'] = $Sale['client']['name'];
            $item['client_email'] = $Sale['client']['email'];
            $item['client_tele'] = $Sale['client']['phone'];
            $item['client_code'] = $Sale['client']['code'];
            $item['client_adr'] = $Sale['client']['adresse'];
            $item['GrandTotal'] = $Sale['GrandTotal'];
            $item['paid_amount'] = $Sale['paid_amount'];
            $item['due'] = $Sale['GrandTotal'] - $Sale['paid_amount'];
            $item['payment_status'] = $Sale['payment_statut'];

            $data[] = $item;
        }

        $customers = client::where('deleted_at', '=', null)->get(['id', 'name']);
        return response()->json(['totalRows' => $totalRows, 'sales' => $data, 'customers' => $customers]);
    }

    //----------------- Providers Report -----------------------\\

    public function Providers_Report(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'Reports_suppliers', Provider::class);
        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $data = array();

        $providers = Provider::where('deleted_at', '=', null)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('code', 'LIKE', "%{$request->search}%")
                        ->orWhere('phone', 'LIKE', "%{$request->search}%");
                });
            });

        $totalRows = $providers->count();
        $providers = $providers->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($providers as $provider) {
            // Received goods less returns and payments (DATATEST K32).
            $owed = \App\utils\PayablesService::forSupplier($provider->id);
            $item['total_purchase'] = $owed['total_purchase'];
            $item['total_amount'] = $owed['total_amount'];
            $item['total_paid'] = $owed['total_paid'];
            $item['due'] = $owed['due'];
            $item['name'] = $provider->name;
            $item['phone'] = $provider->phone;
            $item['code'] = $provider->code;
            $item['id'] = $provider->id;
            $data[] = $item;
        }

        return response()->json([
            'report' => $data,
            'totalRows' => $totalRows,
        ]);

    }

    //-------------------- Get Purchases By Provider -------------\\

    public function Purchases_Provider(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'Reports_suppliers', Provider::class);
        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $data = array();

        $Role = Auth::user()->roles()->first();
        $ShowRecord = Role::findOrFail($Role->id)->inRole('record_view');

        $purchases = Purchase::where('deleted_at', '=', null)
            ->with('provider')
            ->where('provider_id', $request->id)
            ->where(function ($query) use ($ShowRecord) {
                if (!$ShowRecord) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });

        $totalRows = $purchases->count();
        $purchases = $purchases->offset($offSet)
            ->limit($perPage)
            ->orderBy('id', 'desc')
            ->get();

        foreach ($purchases as $purchase) {
            $item['Ref'] = $purchase->Ref;
            $item['statut'] = $purchase->statut;
            $item['GrandTotal'] = $purchase->GrandTotal;
            $item['paid_amount'] = $purchase->paid_amount;
            $item['due'] = $purchase->GrandTotal - $purchase->paid_amount;
            $item['payment_status'] = $purchase->payment_statut;

            $data[] = $item;
        }

        return response()->json([
            'totalRows' => $totalRows,
            'purchases' => $data,
        ]);

    }

    //-------------------- Get Payments By Provider -------------\\

    public function Payments_Provider(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'Reports_suppliers', Provider::class);

        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $data = array();

        $Role = Auth::user()->roles()->first();
        $ShowRecord = Role::findOrFail($Role->id)->inRole('record_view');

        $payments = DB::table('payment_purchases')
            ->where(function ($query) use ($ShowRecord) {
                if (!$ShowRecord) {
                    return $query->where('payment_purchases.user_id', '=', Auth::user()->id);
                }
            })
            ->where('payment_purchases.deleted_at', '=', null)
            ->join('purchases', 'payment_purchases.purchase_id', '=', 'purchases.id')
            ->where('purchases.provider_id', $request->id)
            ->select(
                'payment_purchases.date', 'payment_purchases.Ref AS Ref', 'purchases.Ref AS purchase_Ref',
                'payment_purchases.Reglement', 'payment_purchases.montant'
            );

        $totalRows = $payments->count();
        $payments = $payments->offset($offSet)
            ->limit($perPage)
            ->orderBy('payment_purchases.id', 'desc')
            ->get();

        return response()->json([
            'payments' => $payments,
            'totalRows' => $totalRows,
        ]);
    }

    //-------------------- Get Returns By Providers -------------\\

    public function Returns_Provider(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'Reports_suppliers', Provider::class);

        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $data = array();

        $Role = Auth::user()->roles()->first();
        $ShowRecord = Role::findOrFail($Role->id)->inRole('record_view');

        $PurchaseReturn = PurchaseReturn::where('deleted_at', '=', null)
            ->with('provider')
            ->where('provider_id', $request->id)
            ->where(function ($query) use ($ShowRecord) {
                if (!$ShowRecord) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });

        $totalRows = $PurchaseReturn->count();
        $PurchaseReturn = $PurchaseReturn->offset($offSet)
            ->limit($perPage)
            ->orderBy('id', 'desc')
            ->get();

        foreach ($PurchaseReturn as $Purchase_Return) {
            $item['Ref'] = $Purchase_Return->Ref;
            $item['statut'] = $Purchase_Return->statut;
            $item['provider_name'] = $Purchase_Return['provider']->name;
            $item['GrandTotal'] = $Purchase_Return->GrandTotal;
            $item['paid_amount'] = $Purchase_Return->paid_amount;
            $item['due'] = $Purchase_Return->GrandTotal - $Purchase_Return->paid_amount;
            $item['payment_status'] = $Purchase_Return->payment_statut;

            $data[] = $item;
        }

        return response()->json([
            'totalRows' => $totalRows,
            'returns_supplier' => $data,
        ]);

    }

    //-------------------- Top 5 Suppliers -------------\\

    public function ToProviders(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'Reports_suppliers', Provider::class);

        $results = DB::table('purchases')->where('purchases.deleted_at', '=', null)
            ->join('providers', 'purchases.provider_id', '=', 'providers.id')
            ->select(DB::raw('providers.name'), DB::raw('count(*) as count'))
            ->groupBy('providers.name')
            ->orderBy('count', 'desc')
            ->take(5)
            ->get();

        $data = [];
        $providers = [];
        foreach ($results as $result) {
            $providers[] = $result->name;
            $data[] = $result->count;
        }
        $data[] = 0;
        return response()->json(['providers' => $providers, 'data' => $data]);
    }

    //----------------- Warehouse Report By ID-----------------------\\

    public function Warehouse_Report(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'WarehouseStock', Product::class);

        $data['sales'] = Sale::where('deleted_at', '=', null)
            ->where('statut', '!=', 'voided')
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('warehouse_id'), function ($query) use ($request) {
                    return $query->where('warehouse_id', $request->warehouse_id);
                });
            })->count();

        $data['purchases'] = Purchase::where('deleted_at', '=', null)
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('warehouse_id'), function ($query) use ($request) {
                    return $query->where('warehouse_id', $request->warehouse_id);
                });
            })->count();

        $data['ReturnPurchase'] = PurchaseReturn::where('deleted_at', '=', null)
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('warehouse_id'), function ($query) use ($request) {
                    return $query->where('warehouse_id', $request->warehouse_id);
                });
            })->count();

        $data['ReturnSale'] = SaleReturn::where('deleted_at', '=', null)
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('warehouse_id'), function ($query) use ($request) {
                    return $query->where('warehouse_id', $request->warehouse_id);
                });
            })->count();

        $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'data' => $data,
            'warehouses' => $warehouses,
        ], 200);

    }

    //-------------------- Get Sales By Warehouse -------------\\

    public function Sales_Warehouse(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'WarehouseStock', Product::class);
        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $data = [];

        $Role = Auth::user()->roles()->first();
        $ShowRecord = Role::findOrFail($Role->id)->inRole('record_view');

        $sales = Sale::where('deleted_at', '=', null)->with('client')
            ->where(function ($query) use ($ShowRecord) {
                if (!$ShowRecord) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('warehouse_id'), function ($query) use ($request) {
                    return $query->where('warehouse_id', $request->warehouse_id);
                });
            })
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
                        });
                });
            });

        $totalRows = $sales->count();
        $sales = $sales->offset($offSet)
            ->limit($perPage)
            ->orderBy('id', 'desc')
            ->get();

        foreach ($sales as $sale) {
            $item['date'] = $sale->date;
            $item['Ref'] = $sale->Ref;
            $item['client_name'] = $sale['client']->name;
            $item['statut'] = $sale->statut;
            $item['GrandTotal'] = $sale->GrandTotal;
            $item['paid_amount'] = $sale->paid_amount;
            $item['due'] = $sale->GrandTotal - $sale->paid_amount;
            $item['payment_status'] = $sale->payment_statut;

            $data[] = $item;
        }
        return response()->json([
            'totalRows' => $totalRows,
            'sales' => $data,
        ]);

    }

    //-------------------- Get Quotations By Warehouse -------------\\

    public function Quotations_Warehouse(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'WarehouseStock', Product::class);
        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $data = [];

        $Role = Auth::user()->roles()->first();
        $ShowRecord = Role::findOrFail($Role->id)->inRole('record_view');

        $Quotations = Quotation::where('deleted_at', '=', null)
            ->with('client')
            ->where(function ($query) use ($ShowRecord) {
                if (!$ShowRecord) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('warehouse_id'), function ($query) use ($request) {
                    return $query->where('warehouse_id', $request->warehouse_id);
                });
            })
        //Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('statut', 'LIKE', "%{$request->search}%")
                        ->orWhere('GrandTotal', $request->search)
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('client', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });
        $totalRows = $Quotations->count();
        $Quotations = $Quotations->offset($offSet)
            ->limit($perPage)
            ->orderBy('id', 'desc')
            ->get();

        foreach ($Quotations as $Quotation) {
            $item['date'] = $Quotation->date;
            $item['Ref'] = $Quotation->Ref;
            $item['client_name'] = $Quotation['client']->name;
            $item['statut'] = $Quotation->statut;
            $item['GrandTotal'] = $Quotation->GrandTotal;

            $data[] = $item;
        }

        return response()->json([
            'quotations' => $data,
            'totalRows' => $totalRows,
        ]);
    }

    //-------------------- Get Returns Sale By Warehouse -------------\\

    public function Returns_Sale_Warehouse(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'WarehouseStock', Product::class);
        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $data = array();

        //  Check If User Has Permission Show All Records
        $Role = Auth::user()->roles()->first();
        $ShowRecord = Role::findOrFail($Role->id)->inRole('record_view');

        $SaleReturn = SaleReturn::where('deleted_at', '=', null)
            ->with('client')
            ->where(function ($query) use ($ShowRecord) {
                if (!$ShowRecord) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('warehouse_id'), function ($query) use ($request) {
                    return $query->where('warehouse_id', $request->warehouse_id);
                });
            })
        //Search With Multiple Param
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
                        });
                });
            });

        $totalRows = $SaleReturn->count();
        $SaleReturn = $SaleReturn->offset($offSet)
            ->limit($perPage)
            ->orderBy('id', 'desc')
            ->get();

        foreach ($SaleReturn as $Sale_Return) {
            $item['Ref'] = $Sale_Return->Ref;
            $item['statut'] = $Sale_Return->statut;
            $item['client_name'] = $Sale_Return['client']->name;
            $item['GrandTotal'] = $Sale_Return->GrandTotal;
            $item['paid_amount'] = $Sale_Return->paid_amount;
            $item['due'] = $Sale_Return->GrandTotal - $Sale_Return->paid_amount;
            $item['payment_status'] = $Sale_Return->payment_statut;

            $data[] = $item;
        }

        return response()->json([
            'totalRows' => $totalRows,
            'returns_sale' => $data,
        ]);
    }

    //-------------------- Get Returns Purchase By Warehouse -------------\\

    public function Returns_Purchase_Warehouse(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'WarehouseStock', Product::class);
        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $data = array();

        //  Check If User Has Permission Show All Records
        $Role = Auth::user()->roles()->first();
        $ShowRecord = Role::findOrFail($Role->id)->inRole('record_view');

        $PurchaseReturn = PurchaseReturn::where('deleted_at', '=', null)
            ->with('provider')
            ->where(function ($query) use ($ShowRecord) {
                if (!$ShowRecord) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('warehouse_id'), function ($query) use ($request) {
                    return $query->where('warehouse_id', $request->warehouse_id);
                });
            })
        //Search With Multiple Param
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
                        });
                });
            });

        $totalRows = $PurchaseReturn->count();
        $PurchaseReturn = $PurchaseReturn->offset($offSet)
            ->limit($perPage)
            ->orderBy('id', 'desc')
            ->get();

        foreach ($PurchaseReturn as $Purchase_Return) {
            $item['Ref'] = $Purchase_Return->Ref;
            $item['statut'] = $Purchase_Return->statut;
            $item['provider_name'] = $Purchase_Return['provider']->name;
            $item['GrandTotal'] = $Purchase_Return->GrandTotal;
            $item['paid_amount'] = $Purchase_Return->paid_amount;
            $item['due'] = $Purchase_Return->GrandTotal - $Purchase_Return->paid_amount;
            $item['payment_status'] = $Purchase_Return->payment_statut;

            $data[] = $item;
        }

        return response()->json([
            'totalRows' => $totalRows,
            'returns_purchase' => $data,
        ]);
    }

    //-------------------- Get Expenses By Warehouse -------------\\

    public function Expenses_Warehouse(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'WarehouseStock', Product::class);
        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $data = array();

        //  Check If User Has Permission Show All Records
        $Role = Auth::user()->roles()->first();
        $ShowRecord = Role::findOrFail($Role->id)->inRole('record_view');

        $Expenses = Expense::where('deleted_at', '=', null)
            ->with('expense_category')
            ->where(function ($query) use ($ShowRecord) {
                if (!$ShowRecord) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('warehouse_id'), function ($query) use ($request) {
                    return $query->where('warehouse_id', $request->warehouse_id);
                });
            })
        //Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('date', 'LIKE', "%{$request->search}%")
                        ->orWhere('details', 'LIKE', "%{$request->search}%")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('expense_category', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });

        $totalRows = $Expenses->count();
        $Expenses = $Expenses->offset($offSet)
            ->limit($perPage)
            ->orderBy('id', 'desc')
            ->get();

        foreach ($Expenses as $Expense) {

            $item['date'] = $Expense->date;
            $item['Ref'] = $Expense->Ref;
            $item['details'] = $Expense->details;
            $item['amount'] = $Expense->amount;
            $item['category_name'] = $Expense['expense_category']->name;
            $data[] = $item;
        }

        return response()->json([
            'totalRows' => $totalRows,
            'expenses' => $data,
        ]);
    }

    //----------------- Warhouse Count Stock -----------------------\\

    public function Warhouse_Count_Stock(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'WarehouseStock', Product::class);

        $stock_count = product_warehouse::join('products', 'product_warehouse.product_id', '=', 'products.id')
            ->join('warehouses', 'product_warehouse.warehouse_id', '=', 'warehouses.id')
            ->where('product_warehouse.deleted_at', '=', null)
            ->select(
                DB::raw("count(DISTINCT products.id) as value"),
                DB::raw("warehouses.name as name"),
                DB::raw('(IFNULL(SUM(qte),0)) AS value1'),
            )
            ->where('qte', '>', 0)
            ->groupBy('warehouses.name')
            ->get();

        $stock_value = product_warehouse::join('products', 'product_warehouse.product_id', '=', 'products.id')
            ->join('warehouses', 'product_warehouse.warehouse_id', '=', 'warehouses.id')
            ->where('product_warehouse.deleted_at', '=', null)
            ->select(
                DB::raw("SUM(product_warehouse.qte * products.price) as price"),
                DB::raw("SUM(product_warehouse.qte * products.cost) as cost"),
                DB::raw("warehouses.name as name"),
            )
            ->where('qte', '>', 0)
            ->groupBy('warehouses.name')
            ->get();

        $data = [];
        foreach ($stock_value as $key => $value) {
            $item['name'] = $value->name;
            $item['value'] = $value->price;
            $item['value1'] = $value->cost;
            // $item['value2'] = $value->price - $value->cost;
            $data[] = $item;
        }

        $warehouses = Warehouse::where('deleted_at', '=', null)->pluck('name');

        return response()->json([
            'stock_count' => $stock_count,
            'stock_value' => $data,
            'warehouses' => $warehouses,
        ]);

    }

    //----------------- Warehouse stock valuation -----------------------\\

    public function Warhouse_Value_Stock(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'WarehouseStock', Product::class);

        $values = product_warehouse::join('products', 'product_warehouse.product_id', '=', 'products.id')
            ->join('warehouses', 'product_warehouse.warehouse_id', '=', 'warehouses.id')
            ->whereNull('product_warehouse.deleted_at')
            ->where('product_warehouse.qte', '>', 0)
            ->select(
                'warehouses.name',
                DB::raw('SUM(product_warehouse.qte * products.price) AS sale_value'),
                DB::raw('SUM(product_warehouse.qte * products.cost) AS cost_value')
            )
            ->groupBy('warehouses.id', 'warehouses.name')
            ->orderBy('warehouses.name')
            ->get()
            ->map(function ($row) {
                return [
                    'name' => $row->name,
                    'value' => round((float) $row->sale_value, 2),
                    'value1' => round((float) $row->cost_value, 2),
                    'profit_value' => round((float) $row->sale_value - (float) $row->cost_value, 2),
                ];
            });

        return response()->json([
            'stock_value' => $values,
            'warehouses' => Warehouse::whereNull('deleted_at')->pluck('name'),
        ]);
    }

    //-------------- Count  Product Quantity Alerts ---------------\\

    public function count_quantity_alert(request $request)
    {
        // Same permission as the list it opens, and the same count: services
        // left out, only the user's warehouses (DATATEST K83).
        $this->authorizeForUser($request->user('api'), 'Stock_Alerts', Product::class);

        $products_alerts = \App\utils\LowStock::shelves(\App\utils\WarehouseAccess::allowedIds())->count();

        return response()->json($products_alerts);
    }

    //-----------------Profit And Loss ---------------------------\\

    public function ProfitAndLoss(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'Reports_profit', Client::class);

        $role = Auth::user()->roles()->first();
        $view_records = Role::findOrFail($role->id)->inRole('record_view');

        $data = [];
        // In pesos: a foreign sale counts at its stamped base amount (DATATEST K35).
        $item['sales'] = Sale::where('deleted_at', '=', null)->where('statut', '!=', 'voided')
            ->whereBetween('date', array($request->from, $request->to))
            ->select(
                DB::raw('SUM(COALESCE(base_amount, GrandTotal)) AS sum'),
                DB::raw("count(*) as nmbr")
            )->first();

        // Goods received, not orders placed: a rejected or unreceived order
        // was no purchase (DATATEST K32).
        $item['purchases'] = \App\utils\PayablesService::owable(Purchase::query())
            ->whereBetween('date', array($request->from, $request->to))
            ->select(
                DB::raw('SUM(COALESCE(base_amount / NULLIF(GrandTotal, 0), 1) * ' . \App\utils\PayablesService::receivedValueSql() . ') AS sum'),
                DB::raw("count(*) as nmbr")
            )->first();

        $item['returns_sales'] = SaleReturn::where('deleted_at', '=', null)->whereBetween('date', array($request->from, $request->to))
            ->select(
                DB::raw('SUM(GrandTotal) AS sum'),
                DB::raw("count(*) as nmbr")
            )->first();

        $item['returns_purchases'] = PurchaseReturn::where('deleted_at', '=', null)->whereBetween('date', array($request->from, $request->to))
            ->select(
                DB::raw('SUM(GrandTotal) AS sum'),
                DB::raw("count(*) as nmbr")
            )->first();

        $item['paiement_sales'] = PaymentSale::whereBetween('date', array($request->from, $request->to))
            ->select(
                DB::raw('SUM(montant) AS sum')
            )->first();

        $item['PaymentSaleReturns'] = PaymentSaleReturns::whereBetween('date', array($request->from, $request->to))
            ->select(
                DB::raw('SUM(montant) AS sum')
            )->first();

        $item['PaymentPurchaseReturns'] = PaymentPurchaseReturns::whereBetween('date', array($request->from, $request->to))
            ->select(
                DB::raw('SUM(montant) AS sum')
            )->first();

        $item['paiement_purchases'] = PaymentPurchase::whereBetween('date', array($request->from, $request->to))
            ->select(
                DB::raw('SUM(montant) AS sum')
            )->first();

        $item['expenses'] = Expense::whereBetween('date', array($request->from, $request->to))
            ->select(
                DB::raw('SUM(amount) AS sum')
            )->first();

        $item['return_sales'] = SaleReturn::where('deleted_at', '=', null)
            ->whereBetween('date', array($request->from, $request->to))
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->get(DB::raw('SUM(GrandTotal)  As sum'))
            ->first()->sum; 
        
        $item['today_purchases'] = Purchase::where('deleted_at', '=', null)
            ->whereBetween('date', array($request->from, $request->to))
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->get(DB::raw('SUM(GrandTotal)  As sum'))
            ->first()->sum;
    
        $item['purchases_return'] = PurchaseReturn::where('deleted_at', '=', null)
            ->whereBetween('date', array($request->from, $request->to))
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })
            ->get(DB::raw('SUM(GrandTotal)  As sum'))
            ->first()->sum;
        

        // Revenue, cost of sales and gross profit come from one calculation
        // shared with the Dashboard (ProfitCalculator): completed sales less
        // customer returns, FIFO cost less the cost of goods that came back.
        // Expenses come off after. A supplier return is not income, so it is
        // no longer added (ReportProfitTest).
        $calc = ProfitCalculator::forPeriod($request->from, $request->to, $view_records ? null : Auth::user()->id);
        $item['revenue'] = $calc['revenue'];
        $item['cost_of_goods_sold'] = $calc['cost_of_sales'];
        $item['gross_profit'] = $calc['gross_profit'];
        $item['profit'] = round($calc['gross_profit'] - (float) ($item['expenses']['sum'] ?? 0), 2);
        $item['payment_received'] = $item['paiement_sales']['sum'] + $item['PaymentPurchaseReturns']['sum'];
        $item['payment_sent'] = $item['paiement_purchases']['sum'] + $item['PaymentSaleReturns']['sum'] + $item['expenses']['sum'];
        $item['paiement_net'] = $item['payment_received'] - $item['payment_sent'];

        return response()->json(['data' => $item]);
    }

    //-------------------- Fomrmat units -------------\\

    public function formatSizeUnits($bytes)
    {
        if ($bytes >= 1073741824) {
            $bytes = number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            $bytes = number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            $bytes = number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            $bytes = $bytes . ' bytes';
        } elseif ($bytes == 1) {
            $bytes = $bytes . ' byte';
        } else {
            $bytes = '0 bytes';
        }

        return $bytes;
    }

    //-------------------- Backup Databse -------------\\

    public function GetBackup(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'backup', User::class);

        $data = [];
        $id = 0;
        // Private storage: never served by the website (DatabaseBackUpLocationTest).
        foreach (glob(\App\Console\Commands\DatabaseBackUp::directory() . '/*') as $filename) {
            $item['id'] = $id += 1;
            $item['date'] = basename($filename);
            $size = $this->formatSizeUnits(filesize($filename));
            $item['size'] = $size;

            $data[] = $item;
        }
        $totalRows = sizeof($data);

        return response()->json([
            'backups' => $data,
            'totalRows' => $totalRows,
        ]);

    }

    //-------------------- Generate Databse -------------\\

    public function GenerateBackup(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'backup', User::class);

        Artisan::call('database:backup');

        return response()->json('Generate complete success');
    }

    //-------------------- Delete Databse -------------\\

    public function DeleteBackup(Request $request, $name)
    {

        $this->authorizeForUser($request->user('api'), 'backup', User::class);

        // basename(): a name can only ever point at a file in the backup folder.
        $path = \App\Console\Commands\DatabaseBackUp::directory() . '/' . basename($name);
        if (is_file($path)) {
            @unlink($path);
        }

        return response()->json(['success' => true]);
    }

}
