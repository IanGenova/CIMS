<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
 */

/*
 * The payment provider calling us about a wallet payment.
 *
 * Deliberately outside auth:api -- Xendit has no session and no token of ours.
 * It authenticates with a callback token the controller checks, and the body it
 * sends is treated as a claim rather than as evidence: the service goes and asks
 * the provider what actually happened before anything is marked paid.
 */
Route::post('wallet/callback', 'WalletPaymentController@callback');
// Xendit's webhook: proves itself with its token, and only prompts a direct check.
Route::post('xendit/webhook', 'XenditController@webhook');

/*auth middleware api passport token*/
// Is_Active: an inactive account cannot read itself either (DATATEST K67).
Route::middleware(['auth:api', 'Is_Active'])->get('/user', function (Request $request) {
    return $request->user();
});

//--------------------------- Reset Password  ---------------------------

Route::group([
    'prefix' => 'password',
], function () {
    Route::post('create', 'PasswordResetController@create');
    Route::post('reset', 'PasswordResetController@reset');
});

// EnforceWarehouseAccess: staff tied to a branch may not name another one in
// any request (WarehouseAccessTest). After auth:api, so it knows who is asking.
Route::middleware(['auth:api', 'Is_Active', \App\Http\Middleware\EnforceWarehouseAccess::class])->group(function () {

    //-------------------------- Clear Cache ---------------------------

    Route::get("Clear_Cache", "SettingsController@Clear_Cache");

    //-------------------------- Batch / Lot & Expiry ---------------------------

    Route::get('batches', 'BatchController@index');
    Route::get('batches/expiring', 'BatchController@expiring');
    Route::get('batches/verify', 'BatchController@verify');
    Route::get('batches/{id}/trace', 'BatchController@trace')->where('id', '[0-9]+');
    Route::put('batches/tracking/{id}', 'BatchController@setTracking')->where('id', '[0-9]+');

    //-------------------------- Stock Take / Cycle Count ---------------------------

    Route::get('stock_counts', 'StockCountController@index');
    Route::post('stock_counts', 'StockCountController@store');
    Route::get('stock_counts/{id}', 'StockCountController@show')->where('id', '[0-9]+');
    Route::put('stock_counts/{id}', 'StockCountController@update')->where('id', '[0-9]+');
    Route::delete('stock_counts/{id}', 'StockCountController@destroy')->where('id', '[0-9]+');
    Route::post('stock_counts/{id}/complete', 'StockCountController@complete')->where('id', '[0-9]+');
    Route::post('stock_counts/{id}/cancel', 'StockCountController@cancel')->where('id', '[0-9]+');

    //-------------------------- Goods Receipt (GRN) ---------------------------

    Route::get('goods_receipts', 'GoodsReceiptController@index');
    Route::get('goods_receipts/awaiting', 'GoodsReceiptController@awaiting');
    Route::get('goods_receipts/create', 'GoodsReceiptController@create');
    Route::post('goods_receipts', 'GoodsReceiptController@store');
    Route::get('goods_receipts/{id}', 'GoodsReceiptController@show')->where('id', '[0-9]+');
    Route::delete('goods_receipts/{id}', 'GoodsReceiptController@destroy')->where('id', '[0-9]+');

    //-------------------------- Purchase Approval ---------------------------

    Route::get('purchase_approvals/pending', 'PurchaseApprovalController@pending');
    Route::post('purchase_approvals/{id}/approve', 'PurchaseApprovalController@approve')->where('id', '[0-9]+');
    Route::post('purchase_approvals/{id}/reject', 'PurchaseApprovalController@reject')->where('id', '[0-9]+');

    //-------------------------- Reorder / Replenishment ---------------------------

    Route::get('reorder/suggestions', 'ReorderController@suggestions');
    Route::put('reorder/product/{id}', 'ReorderController@updateProductSettings')->where('id', '[0-9]+');

    //-------------------------- Audit Trail ---------------------------
    // Read-only by design: the log is append-only.

    Route::get('audit_logs', 'AuditLogController@index');
    Route::get('audit_logs/{id}', 'AuditLogController@show')->where('id', '[0-9]+');
    Route::get('audit_logs/record/{model}/{id}', 'AuditLogController@forRecord');

    //-------------------------- Reports ---------------------------

    Route::get("report/client", "ReportController@Client_Report");
    Route::get("report/client/{id}", "ReportController@Client_Report_detail");
    Route::get("report/client_Sales", "ReportController@Sales_Client");
    Route::get("report/client_payments", "ReportController@Payments_Client");
    Route::get("report/client_quotations", "ReportController@Quotations_Client");
    Route::get("report/client_returns", "ReportController@Returns_Client");
    Route::get("report/client_Top_Clients", "ReportController@TopCustomers");
    Route::get("report/TopProducts_year", "ReportController@Top_Products_Year");
    Route::get("report/TopProducts_Month", "ReportController@TopProducts_Month");
    Route::get("report/provider", "ReportController@Providers_Report");
    Route::get("report/provider/{id}", "ReportController@Provider_Report_detail");
    Route::get("report/provider_purchases", "ReportController@Purchases_Provider");
    Route::get("report/provider_payments", "ReportController@Payments_Provider");
    Route::get("report/provider_returns", "ReportController@Returns_Provider");
    Route::get("report/ToProviders", "ReportController@ToProviders");
    Route::get("report/Sales", "ReportController@Report_Sales");
    Route::get("report/Purchases", "ReportController@Report_Purchases");
    Route::get("report/Get_last_Sales", "ReportController@Get_last_Sales");
    Route::get("report/stock_alert", "ReportController@Products_Alert");
    Route::get("report/Payment_chart", "ReportController@Payment_chart");
    Route::get("report/Warehouse_Report", "ReportController@Warehouse_Report");
    Route::get("report/Sales_Warehouse", "ReportController@Sales_Warehouse");
    Route::get("report/Quotations_Warehouse", "ReportController@Quotations_Warehouse");
    Route::get("report/Returns_Sale_Warehouse", "ReportController@Returns_Sale_Warehouse");
    Route::get("report/Returns_Purchase_Warehouse", "ReportController@Returns_Purchase_Warehouse");
    Route::get("report/Expenses_Warehouse", "ReportController@Expenses_Warehouse");
    Route::get("report/Warhouse_Count_Stock", "ReportController@Warhouse_Count_Stock");
    Route::get("report/Warhouse_Value_Stock", "ReportController@Warhouse_Value_Stock");
    Route::get("report/report_today", "ReportController@report_today");
    Route::get("report/count_quantity_alert", "ReportController@count_quantity_alert");
    Route::get("report/ProfitAndLoss", "ReportController@ProfitAndLoss");
    Route::get("chart/SalesPurchasesChart", "ReportController@SalesPurchasesChart");
    Route::get("chart/report_with_echart", "ReportController@report_with_echart");
    Route::get("report/report_dashboard", "ReportController@report_dashboard");


    //------------------------------- CLIENTS --------------------------\\
    //------------------------------------------------------------------\\

    Route::resource('clients', 'ClientController');
    Route::get('clients/export/Excel', 'ClientController@exportExcel');
    Route::post('clients/import/csv', 'ClientController@import_clients');
    Route::get('Get_Clients_Without_Paginate', 'ClientController@Get_Clients_Without_Paginate');
    Route::post('clients/delete/by_selection', 'ClientController@delete_by_selection');


    //------------------------------- Providers --------------------------\\
    //--------------------------------------------------------------------\\

    Route::resource('providers', 'ProvidersController');
    Route::get('providers/export/Excel', 'ProvidersController@exportExcel');
    Route::post('providers/import/csv', 'ProvidersController@import_providers');
    Route::post('providers/delete/by_selection', 'ProvidersController@delete_by_selection');


    //---------------------- POS (point of sales) ----------------------\\
    //------------------------------------------------------------------\\

    Route::post('pos/CreatePOS', 'PosController@CreatePOS');
    Route::post('pos/calculTotal', 'PosController@CalculGrandTotal');
    Route::get('getArticlesByCategory/{id}', 'PosController@getArticlesByCategory');
    Route::get('GetProductsByParametre', 'PosController@GetProductsByParametre');
    Route::get('pos/GetELementPos', 'PosController@GetELementPos');

    //------------------------------- PRODUCTS --------------------------\\
    //------------------------------------------------------------------\\

    Route::resource('Products', 'ProductsController');
    Route::get('Products/export/Excel', 'ProductsController@export_Excel');
    Route::post('Products/import/csv', 'ProductsController@import_products');
    Route::get('Products/Warehouse/{id}', 'ProductsController@Products_by_Warehouse');
    Route::get('serials/lookup', 'SerialController@lookup');
    Route::get('serials/available', 'SerialController@available');
    Route::get('Products/Detail/{id}', 'ProductsController@Get_Products_Details');
    Route::get('Products/Stock/Alerts', 'ProductsController@Products_Alert');
    Route::get('Products/Get_element/barcode', 'ProductsController@Get_element_barcode');
    Route::post('Products/delete/by_selection', 'ProductsController@delete_by_selection');

    // Route::get('Products/filter/{id}/{input}', 'ProductsController@Filter_Products');


    //------------------------------- Category --------------------------\\
    //------------------------------------------------------------------\\

    Route::resource('categories', 'CategorieController');
    Route::post('categories/delete/by_selection', 'CategorieController@delete_by_selection');

    //------------------------------- Units --------------------------\\
    //------------------------------------------------------------------\\

    Route::resource('units', 'UnitsController');
    Route::get('Get_Units_SubBase', 'UnitsController@Get_Units_SubBase');
    Route::get('Get_sales_units', 'UnitsController@Get_sales_units');

    //------------------------------- Brands--------------------------\\
    //------------------------------------------------------------------\\
    Route::resource('brands', 'BrandsController');
    Route::post('brands/delete/by_selection', 'BrandsController@delete_by_selection');

    //------------------------------- Currencies --------------------------\\
    //------------------------------------------------------------------\\

    Route::resource('currencies', 'CurrencyController');
    Route::get('Get_Currencies/All', 'CurrencyController@Get_Currencies');
    Route::post('currencies/delete/by_selection', 'CurrencyController@delete_by_selection');


    //------------------------------------------------------------------\\
    //------------------------- Tax Groups (multi-tax) ------------------\\
    //------------------------------------------------------------------\\

    Route::resource('tax_groups', 'TaxGroupController');
    Route::get('tax_groups/options/all', 'TaxGroupController@options');
    Route::post('tax_groups/delete/by_selection', 'TaxGroupController@delete_by_selection');


    //------------------------------------------------------------------\\
    //------------------- Customer Groups and Price Lists ---------------\\
    //------------------------------------------------------------------\\

    Route::resource('customer_groups', 'CustomerGroupController');
    Route::get('customer_groups/options/all', 'CustomerGroupController@options');
    Route::post('customer_groups/delete/by_selection', 'CustomerGroupController@delete_by_selection');

    Route::get('price_lists', 'CustomerGroupController@lists');
    Route::post('price_lists', 'CustomerGroupController@storeList');
    Route::put('price_lists/{id}', 'CustomerGroupController@updateList');
    Route::delete('price_lists/{id}', 'CustomerGroupController@destroyList');
    Route::get('price_lists/{id}/items', 'CustomerGroupController@listItems');
    Route::post('price_lists/{id}/items', 'CustomerGroupController@storeListItem');
    Route::delete('price_list_items/{itemId}', 'CustomerGroupController@destroyListItem');

    // What this customer actually pays for this product at this quantity.
    Route::get('pricing/resolve', 'CustomerGroupController@resolve');


    //------------------------------------------------------------------\\
    //--------------------- Customer credit control ---------------------\\
    //------------------------------------------------------------------\\

    Route::get('credit/customers', 'CreditController@index');
    Route::get('credit/ageing', 'CreditController@ageing');
    Route::get('credit/statement/{clientId}', 'CreditController@statement');


    //------------------------------------------------------------------\\
    //------------- Supplier price lists and lead times -----------------\\
    //------------------------------------------------------------------\\

    Route::resource('supplier_pricing', 'SupplierPricingController');
    Route::get('supplier_pricing/product/{productId}', 'SupplierPricingController@forProduct');


    //------------------------------------------------------------------\\
    //------------------ Warehouse bin / rack locations -----------------\\
    //------------------------------------------------------------------\\

    Route::resource('warehouse_locations', 'WarehouseLocationController');
    Route::get('warehouse_locations/stock/view', 'WarehouseLocationController@stock');
    Route::get('warehouse_locations/movements/recent', 'WarehouseLocationController@movements');
    Route::post('warehouse_locations/putaway', 'WarehouseLocationController@putAway');
    Route::post('warehouse_locations/move', 'WarehouseLocationController@move');
    Route::put('warehouse_locations/warehouse/{warehouseId}/toggle', 'WarehouseLocationController@toggleWarehouse');


    //------------------------------------------------------------------\\
    //------------------- Delivery / shipment tracking ------------------\\
    //------------------------------------------------------------------\\

    Route::resource('deliveries', 'DeliveryController');
    Route::get('deliveries/sales/undelivered', 'DeliveryController@undelivered');
    Route::post('deliveries/{id}/transition', 'DeliveryController@transition');


    //------------------------------------------------------------------\\
    //------------------------ Accounting -------------------------------\\
    //------------------------------------------------------------------\\

    Route::get('accounting/accounts', 'AccountingController@accounts');
    Route::post('accounting/accounts', 'AccountingController@storeAccount');
    Route::put('accounting/accounts/{id}', 'AccountingController@updateAccount');
    Route::delete('accounting/accounts/{id}', 'AccountingController@destroyAccount');
    Route::get('accounting/journal', 'AccountingController@journal');
    Route::post('accounting/journal', 'AccountingController@storeEntry');
    Route::post('accounting/journal/{id}/reverse', 'AccountingController@reverseEntry');
    Route::get('accounting/trial_balance', 'AccountingController@trialBalance');
    Route::get('accounting/trial_balance/export', 'AccountingController@exportTrialBalance');
    Route::post('accounting/post_pending', 'AccountingController@postPending');
    Route::get('accounting/posting_status', 'AccountingController@postingStatus');
    Route::post('accounting/auto_post', 'AccountingController@setAutoPost');

    //-------------------------- Stock ledger --------------------------\\

    Route::get('stock/movements', 'StockMovementController@index');
    Route::get('stock/movements/product/{id}', 'StockMovementController@product');
    Route::get('stock/valuation', 'StockMovementController@valuation');

    //--------------------------- Till ---------------------------\\

    Route::get('till/current', 'TillController@current');
    Route::post('till/open', 'TillController@open');
    Route::post('till/cash', 'TillController@cash');
    Route::get('till/sessions', 'TillController@index');
    Route::get('till/sessions/{id}', 'TillController@show');
    Route::get('till/sessions/{id}/reading', 'TillController@reading');
    Route::post('till/sessions/{id}/close', 'TillController@close');

    //------------------- Voids, and baskets on hold -------------------\\

    Route::post('sales/{id}/void', 'SalesController@voidSale');

    //------------------------ Wallet payments -------------------------\

    Route::get('wallet/channels', 'WalletPaymentController@channels');
    Route::post('wallet/start', 'WalletPaymentController@start');
    Route::get('wallet/{id}/status', 'WalletPaymentController@status');
    Route::post('wallet/{id}/attach', 'WalletPaymentController@attach');

    //--------------------- Marketplace selling ------------------------\

    Route::get('marketplace', 'MarketplaceController@index');
    Route::post('marketplace/accounts', 'MarketplaceController@storeAccount');
    Route::put('marketplace/accounts/{id}', 'MarketplaceController@updateAccount');
    Route::post('marketplace/accounts/{id}/sync', 'MarketplaceController@syncNow');
    Route::post('marketplace/accounts/{id}/connect', 'MarketplaceController@connect');

    Route::get('marketplace/listings', 'MarketplaceController@listings');
    Route::post('marketplace/listings', 'MarketplaceController@storeListing');
    Route::put('marketplace/listings/{id}', 'MarketplaceController@updateListing');
    Route::delete('marketplace/listings/{id}', 'MarketplaceController@destroyListing');

    Route::get('marketplace/orders', 'MarketplaceController@orders');
    Route::post('marketplace/orders/{id}/retry', 'MarketplaceController@retryOrder');
    Route::get('marketplace/products', 'MarketplaceController@products');

    Route::get('pos/holds', 'PosHoldController@index');
    Route::post('pos/holds', 'PosHoldController@store');
    Route::get('pos/holds/{id}', 'PosHoldController@show');
    Route::post('pos/holds/{id}/recall', 'PosHoldController@recall');
    Route::delete('pos/holds/{id}', 'PosHoldController@destroy');


    //------------------------------------------------------------------\\
    //---------------- Manufacturing / bill of materials ----------------\\
    //------------------------------------------------------------------\\

    Route::get('manufacturing/boms', 'ManufacturingController@boms');
    Route::post('manufacturing/boms', 'ManufacturingController@storeBom');
    Route::put('manufacturing/boms/{id}', 'ManufacturingController@updateBom');
    Route::delete('manufacturing/boms/{id}', 'ManufacturingController@destroyBom');
    Route::get('manufacturing/orders', 'ManufacturingController@orders');
    Route::post('manufacturing/orders', 'ManufacturingController@storeOrder');
    Route::get('manufacturing/orders/{id}/requirements', 'ManufacturingController@requirements');
    Route::post('manufacturing/orders/{id}/complete', 'ManufacturingController@completeOrder');
    Route::post('manufacturing/orders/{id}/cancel', 'ManufacturingController@cancelOrder');


    //------------------------------------------------------------------\\
    //------------------ Security: 2FA, policy, sessions ----------------\\
    //------------------------------------------------------------------\\

    Route::get('security/me', 'SecurityController@me');
    Route::post('security/two_factor/start', 'SecurityController@startTwoFactor');
    Route::post('security/two_factor/confirm', 'SecurityController@confirmTwoFactor');
    Route::post('security/two_factor/disable', 'SecurityController@disableTwoFactor');
    Route::post('security/two_factor/recovery_codes', 'SecurityController@regenerateRecoveryCodes');
    Route::post('security/password', 'SecurityController@changePassword');
    Route::get('security/sessions', 'SecurityController@sessions');
    Route::post('security/sessions/sign_out_others', 'SecurityController@signOutOthers');

    Route::get('security/policy', 'SecurityController@getPolicy');
    Route::put('security/policy', 'SecurityController@updatePolicy');
    Route::get('security/users', 'SecurityController@userSecurity');
    Route::post('security/users/{id}/unlock', 'SecurityController@unlockUser');
    Route::post('security/users/{id}/force_password_change', 'SecurityController@forcePasswordChange');
    Route::post('security/users/{id}/reset_two_factor', 'SecurityController@resetTwoFactor');


    //------------------------------------------------------------------\\
    //------------------------ Notifications ----------------------------\\
    //------------------------------------------------------------------\\

    Route::get('notifications', 'NotificationController@index');
    Route::get('notifications/count', 'NotificationController@count');
    Route::post('notifications/{id}/read', 'NotificationController@markRead');
    Route::post('notifications/read_all', 'NotificationController@markAllRead');
    Route::post('notifications/refresh', 'NotificationController@refresh');
    Route::get('push/key', 'PushController@key');
    Route::post('push/subscribe', 'PushController@subscribe');
    Route::post('push/unsubscribe', 'PushController@unsubscribe');


    //------------------------------------------------------------------\\
    //-------------------------- Backups --------------------------------\\
    //------------------------------------------------------------------\\

    Route::get('backups', 'BackupController@index');
    Route::post('backups/run', 'BackupController@run');
    Route::post('backups/{id}/verify', 'BackupController@verify');
    Route::get('backups/{id}/download', 'BackupController@download');
    Route::delete('backups/{id}', 'BackupController@destroy');
    Route::put('backups/settings', 'BackupController@updateSettings');


    //------------------------------------------------------------------\\
    //------------------------- Webhooks --------------------------------\\
    //------------------------------------------------------------------\\

    Route::get('webhooks', 'WebhookController@index');
    Route::post('webhooks', 'WebhookController@store');
    Route::put('webhooks/{id}', 'WebhookController@update');
    Route::delete('webhooks/{id}', 'WebhookController@destroy');
    Route::post('webhooks/{id}/rotate', 'WebhookController@rotateSecret');
    Route::post('webhooks/{id}/test', 'WebhookController@test');
    Route::get('webhook_deliveries', 'WebhookController@deliveries');
    Route::post('webhook_deliveries/deliver', 'WebhookController@deliverPending');


    //------------------------------------------------------------------\\
    //------------------------ Recycle bin ------------------------------\\
    //------------------------------------------------------------------\\

    Route::get('recycle_bin/summary', 'RecycleBinController@summary');
    Route::get('recycle_bin', 'RecycleBinController@index');
    Route::post('recycle_bin/restore', 'RecycleBinController@restore');
    Route::post('recycle_bin/purge', 'RecycleBinController@purge');
    Route::get('recycle_bin/log', 'RecycleBinController@restoreLog');


    //------------------------------------------------------------------\\
    //--------------------- Offline POS sync -----------------------------\\
    //------------------------------------------------------------------\\

    Route::post('offline/sales/sync', 'OfflineSaleController@sync');
    Route::post('offline/sales/status', 'OfflineSaleController@status');
    Route::get('offline/sales/rejected', 'OfflineSaleController@rejected');


    //------------------------------------------------------------------\\
    //-------------------------- Tenants --------------------------------\\
    //------------------------------------------------------------------\\

    Route::get('tenants', 'TenantController@index');
    Route::post('tenants', 'TenantController@store');
    Route::post('tenants/{id}/suspend', 'TenantController@suspend');
    Route::post('tenants/{id}/activate', 'TenantController@activate');


    //------------------------------- WAREHOUSES --------------------------\\

    Route::resource('warehouses', 'WarehouseController');
    Route::get('Get_Warehouses/All', 'WarehouseController@Get_Warehouses');
    Route::post('warehouses/delete/by_selection', 'WarehouseController@delete_by_selection');

    //------------------------------- PURCHASES --------------------------\\
    //------------------------------------------------------------------\\

    Route::resource('purchases', 'PurchasesController');
    Route::get('purchases/Payments/{id}', 'PurchasesController@Get_Payments');
    Route::post('purchases/send/email', 'PurchasesController@Send_Email');
    Route::post('purchases/send/sms', 'PurchasesController@Send_SMS');
    Route::get('purchases/export/Excel', 'PurchasesController@exportExcel');
    Route::post('purchases/delete/by_selection', 'PurchasesController@delete_by_selection');

    // Stop waiting for a balance that is never coming.
    Route::post('purchases/{id}/close_short', 'PurchasesController@closeShort')->where('id', '[0-9]+');


    //------------------------------- Payments  Purchases --------------------------\\
    //------------------------------------------------------------------------------\\

    Route::resource('payment/purchase', 'PaymentPurchasesController');
    Route::get('payment/purchase/Number/Order', 'PaymentPurchasesController@peekNumberOrder');
    Route::get('payment/purchase/export/Excel', 'PaymentPurchasesController@exportExcel');
    Route::post('payment/purchase/send/email', 'PaymentPurchasesController@SendEmail');
    Route::post('payment/purchase/send/sms', 'PaymentPurchasesController@Send_SMS');

    //-------------------------------  Sales --------------------------\\
    //------------------------------------------------------------------\\

    Route::resource('sales', 'SalesController');
    Route::get('sales/Change_to_Sale/{id}', 'SalesController@Elemens_Change_To_Sale');
    Route::get('sales/payments/{id}', 'SalesController@Payments_Sale');
    Route::post('sales/send/email', 'SalesController@Send_Email');
    Route::post('sales/send/sms', 'SalesController@Send_SMS');
    Route::get('sales/export/Excel', 'SalesController@exportExcel');
    Route::post('sales/delete/by_selection', 'SalesController@delete_by_selection');


    //------------------------------- Payments  Sales --------------------------\\
    //------------------------------------------------------------------\\

    Route::resource('payment/sale', 'PaymentSalesController');
    Route::get('payment/sale/Number/Order', 'PaymentSalesController@peekNumberOrder');
    Route::get('payment/sale/export/Excel', 'PaymentSalesController@exportExcel');
    Route::post('payment/sale/send/email', 'PaymentSalesController@SendEmail');
    Route::post('payment/sale/send/sms', 'PaymentSalesController@Send_SMS');

    //------------------------------- Expenses --------------------------\\
    //------------------------------------------------------------------\\

    Route::resource('expenses', 'ExpensesController');
    Route::get('expenses/export/Excel', 'ExpensesController@exportExcel');
    Route::post('expenses/delete/by_selection', 'ExpensesController@delete_by_selection');


    //------------------------------- Expenses Category--------------------------\\
    //------------------------------------------------------------------\\

    Route::resource('expensescategory', 'CategoryExpenseController');
    Route::post('expensescategory/delete/by_selection', 'CategoryExpenseController@delete_by_selection');


    //------------------------------- Quotations --------------------------\\
    //------------------------------------------------------------------\\
    Route::resource('quotations', 'QuotationsController');
    Route::post('quotations/sendQuote/email', 'QuotationsController@SendEmail');
    Route::post('quotations/send/sms', 'QuotationsController@Send_SMS');
    Route::get('quotations/export/Excel', 'QuotationsController@exportExcel');
    Route::post('quotations/delete/by_selection', 'QuotationsController@delete_by_selection');

    //------------------------------- Sales Return --------------------------\\
    //------------------------------------------------------------------\\

    // Before the resource, or "for_client" is read as a return id.
    Route::get('returns/sale/for_client', 'SalesReturnController@salesForClient');
    Route::resource('returns/sale', 'SalesReturnController');
    Route::post('returns/sale/send/email', 'SalesReturnController@Send_Email');
    Route::post('returns/sale/send/sms', 'SalesReturnController@Send_SMS');
    Route::get('returns/sale/export/Excel', 'SalesReturnController@exportExcel');
    Route::get('returns/sale/payment/{id}', 'SalesReturnController@Payment_Returns');
    Route::post('returns/sale/delete/by_selection', 'SalesReturnController@delete_by_selection');

    //------------------------------- Purchases Return --------------------------\\
    //------------------------------------------------------------------\\

    Route::resource('returns/purchase', 'PurchasesReturnController');
    Route::post('returns/purchase/send/email', 'PurchasesReturnController@Send_Email');
    Route::post('returns/purchase/send/sms', 'PurchasesReturnController@Send_SMS');
    Route::get('returns/purchase/export/Excel', 'PurchasesReturnController@exportExcel');
    Route::get('returns/purchase/payment/{id}', 'PurchasesReturnController@Payment_Returns');
    Route::post('returns/purchase/delete/by_selection', 'PurchasesReturnController@delete_by_selection');

    //------------------------------- Payment Sale Returns --------------------------\\
    //--------------------------------------------------------------------------------\\

    Route::resource('payment/returns_sale', 'PaymentSaleReturnsController');
    Route::get('payment/returns_sale/Number/order', 'PaymentSaleReturnsController@peekNumberOrder');
    Route::get('payment/returns_sale/export/Excel', 'PaymentSaleReturnsController@exportExcel');
    Route::post('payment/returns_sale/send/email', 'PaymentSaleReturnsController@SendEmail');
    Route::post('payment/returns_sale/send/sms', 'PaymentSaleReturnsController@Send_SMS');

    //------------------------------- Payments Purchase Returns --------------------------\\
    //---------------------------------------------------------------------------------------\\

    Route::resource('payment/returns_purchase', 'PaymentPurchaseReturnsController');
    Route::get('payment/returns_purchase/Number/Order', 'PaymentPurchaseReturnsController@peekNumberOrder');
    Route::get('payment/returns_purchase/export/Excel', 'PaymentPurchaseReturnsController@exportExcel');
    Route::post('payment/returns_purchase/send/email', 'PaymentPurchaseReturnsController@SendEmail');
    Route::post('payment/returns_purchase/send/sms', 'PaymentPurchaseReturnsController@Send_SMS');

    //------------------------------- Adjustments --------------------------\\
    //------------------------------------------------------------------\\

    Route::resource('adjustments', 'AdjustmentController');
    Route::get('adjustments/detail/{id}', 'AdjustmentController@Adjustment_detail');
    Route::get('adjustments/export/Excel', 'AdjustmentController@exportExcel');
    Route::post('adjustments/delete/by_selection', 'AdjustmentController@delete_by_selection');

    //------------------------------- Transfers --------------------------\\
    //--------------------------------------------------------------------\\
    Route::resource('transfers', 'TransferController');
    Route::get('transfers/export/Excel', 'TransferController@exportExcel');
    Route::post('transfers/delete/by_selection', 'TransferController@delete_by_selection');

    //------------------------------- Users --------------------------\\
    //------------------------------------------------------------------\\

    Route::get('GetUserAuth', 'UserController@GetUserAuth');
    Route::get("/GetPermissions", "UserController@GetPermissions");
    Route::resource('users', 'UserController');
    Route::put('users/Activated/{id}', 'UserController@IsActivated');
    Route::get('users/export/Excel', 'UserController@exportExcel');
    Route::get('users/Get_Info/Profile', 'UserController@GetInfoProfile');
    Route::put('updateProfile/{id}', 'UserController@updateProfile');

    //------------------------------- Permission Groups user -----------\\
    //------------------------------------------------------------------\\

    Route::resource('roles', 'PermissionsController');
    Route::get('roles/check/Create_page', 'PermissionsController@Check_Create_Page');
    Route::get('getRoleswithoutpaginate', 'PermissionsController@getRoleswithoutpaginate');
    Route::post('roles/delete/by_selection', 'PermissionsController@delete_by_selection');

    
    //------------------------------- Settings ------------------------\\
    //------------------------------------------------------------------\\    
    Route::resource('settings', 'SettingsController');

    Route::put('pos_settings/{id}', 'SettingsController@update_pos_settings');

    // QZ Tray signing. Read-only, and inert until a key pair is configured --
    // the certificate is public by design and the private key never leaves the
    // server, only the signature does.
    Route::get('pos/qz/certificate', 'QzController@certificate');
    Route::get('pos/qz/sign', 'QzController@sign');
    Route::get('get_pos_Settings', 'SettingsController@get_pos_Settings');
    
    Route::put('SMTP/{id}', 'SettingsController@updateSMTP');
    Route::post('SMTP', 'SettingsController@CreateSMTP');
    Route::get('getSettings', 'SettingsController@getSettings');
    Route::get('getSMTP', 'SettingsController@getSMTP');
    Route::get('get_sms_config', 'SettingsController@get_sms_config');


    Route::post('payment_gateway', 'SettingsController@Update_payment_gateway');
    Route::post('payment_gateway/test', 'SettingsController@Test_payment_gateway');
    Route::post('xendit/checkouts', 'XenditController@store');
    Route::get('xendit/checkouts/{id}', 'XenditController@show');
    Route::post('xendit/checkouts/{id}/cancel', 'XenditController@cancel');
    Route::post('sms_config', 'SettingsController@sms_config');
    Route::get('Get_payment_gateway', 'SettingsController@Get_payment_gateway');

    //------------------------------- Backup --------------------------\\
    //------------------------------------------------------------------\\
    
    Route::get("GetBackup", "ReportController@GetBackup");
    Route::get("GenerateBackup", "ReportController@GenerateBackup");
    Route::delete("DeleteBackup/{name}", "ReportController@DeleteBackup");


    //-------------------------------  Print & PDF ------------------------\\
    //------------------------------------------------------------------\\

    Route::get('Sale_PDF/{id}', 'SalesController@Sale_PDF');
    Route::get('Quote_PDF/{id}', 'QuotationsController@Quotation_pdf');
    Route::get('Purchase_PDF/{id}', 'PurchasesController@Purchase_pdf');
    Route::get('Return_sale_PDF/{id}', 'SalesReturnController@Return_pdf');
    Route::get('Return_Purchase_PDF/{id}', 'PurchasesReturnController@Return_pdf');
    Route::get('Payment_Purchase_PDF/{id}', 'PaymentPurchasesController@Payment_purchase_pdf');
    Route::get('payment_Return_sale_PDF/{id}', 'PaymentSaleReturnsController@payment_return');
    Route::get('payment_Return_Purchase_PDF/{id}', 'PaymentPurchaseReturnsController@payment_return');
    Route::get('payment_Sale_PDF/{id}', 'PaymentSalesController@payment_sale');
    Route::get('Sales/Print_Invoice/{id}', 'SalesController@Print_Invoice_POS');

    Route::get('Products/filter/{id}/{input}', 'ProductsController@Filter_Products');

});
