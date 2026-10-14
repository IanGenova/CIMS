import Vue from "vue";
import store from "./store";
import Router from "vue-router";
import { i18n } from "./plugins/i18n";
import authenticate from "./auth/authenticate";
import IsConnected from "./auth/IsConnected";

import NProgress from "nprogress";

Vue.use(Router);

// create new router

const routes = [
    {
        path: "/",
        component: () => import("./views/app"),
        // beforeEnter: authenticate,
        redirect: "/app/dashboard",

        children: [
            {
                path: "/app/dashboard",
                name: "dashboard",
                component: () => import(/* webpackChunkName: "dashboard" */ "./views/app/dashboard/dashboard")
            },

            //Products
            {
                path: "/app/products",
                component: () => import(/* webpackChunkName: "products" */ "./views/app/pages/products"),
                redirect: "app/products/list",
                children: [
                    {
                        name: "manufacturing",
                        path: "manufacturing",
                        component: () =>
                            import(/* webpackChunkName: "manufacturing" */"./views/app/pages/manufacturing/production")
                    },
                    {
                        name: "index_products",
                        path: "list",
                        component: () =>
                            import(/* webpackChunkName: "index_products" */"./views/app/pages/products/index_products")
                    },
                    {
                        path: "store",
                        name: "store_product",
                        component: () =>
                            import(/* webpackChunkName: "store_product" */"./views/app/pages/products/Add_product")
                    },
                    {
                        path: "edit/:id",
                        name: "edit_product",
                        component: () =>
                            import(/* webpackChunkName: "edit_product" */"./views/app/pages/products/Edit_product")
                    },
                    {
                        path: "detail/:id",
                        name: "detail_product",
                        component: () =>
                            import(/* webpackChunkName: "detail_product" */"./views/app/pages/products/Detail_Product")
                    },
                    {
                        path: "barcode",
                        name: "barcode",
                        component: () =>
                            import(/* webpackChunkName: "barcode" */"./views/app/pages/products/barcode")
                    },
                    {
                        path: "batches",
                        name: "batches",
                        component: () =>
                            import(/* webpackChunkName: "batches" */"./views/app/pages/products/batches")
                    },
                    {
                        path: "serials",
                        name: "serial_lookup",
                        component: () =>
                            import(/* webpackChunkName: "serial_lookup" */"./views/app/pages/products/serial_lookup")
                    },
                    {
                        path: "marketplace",
                        name: "marketplace",
                        component: () =>
                            import(/* webpackChunkName: "marketplace" */"./views/app/pages/products/marketplace")
                    },
                ]
            },

            //Adjustement
            {
                path: "/app/adjustments",
                component: () => import(/* webpackChunkName: "adjustments" */ "./views/app/pages/adjustment"),
                redirect: "/app/adjustments/list",
                children: [
                    {
                        name: "index_adjustment",
                        path: "list",
                        component: () =>
                            import(/* webpackChunkName: "index_adjustment" */
                                "./views/app/pages/adjustment/index_Adjustment"
                            )
                    },
                    {
                        name: "store_adjustment",
                        path: "store",
                        component: () =>
                            import(/* webpackChunkName: "store_adjustment" */
                                "./views/app/pages/adjustment/Create_Adjustment"
                            )
                    },
                    {
                        name: "edit_adjustment",
                        path: "edit/:id",
                        component: () =>
                            import(/* webpackChunkName: "edit_adjustment" */
                                "./views/app/pages/adjustment/Edit_Adjustment"
                            )
                    }
                ]
            },

            //Transfer
            {
                path: "/app/transfers",
                component: () => import(/* webpackChunkName: "transfers" */ "./views/app/pages/transfers"),
                redirect: "/app/transfers/list",
                children: [
                    {
                        name: "index_transfer",
                        path: "list",
                        component: () =>
                            import(/* webpackChunkName: "index_transfer" */"./views/app/pages/transfers/index_transfer")
                    },
                    {
                        name: "store_transfer",
                        path: "store",
                        component: () =>
                            import(/* webpackChunkName: "store_transfer" */
                                "./views/app/pages/transfers/create_transfer"
                            )
                    },
                    {
                        name: "edit_transfer",
                        path: "edit/:id",
                        component: () =>
                            import(/* webpackChunkName: "edit_transfer" */"./views/app/pages/transfers/edit_transfer")
                    }
                ]
            },

            //Expense
            {
                path: "/app/expenses",
                component: () => import(/* webpackChunkName: "expenses" */ "./views/app/pages/expense"),
                redirect: "/app/expenses/list",
                children: [
                    {
                        name: "index_expense",
                        path: "list",
                        component: () =>
                            import(/* webpackChunkName: "index_expense" */"./views/app/pages/expense/index_expense")
                    },
                    {
                        name: "store_expense",
                        path: "store",
                        component: () =>
                            import(/* webpackChunkName: "store_expense" */"./views/app/pages/expense/Create_expense")
                    },
                    {
                        name: "edit_expense",
                        path: "edit/:id",
                        component: () =>
                            import(/* webpackChunkName: "edit_expense" */"./views/app/pages/expense/Edit_expense")
                    },
                    {
                        name: "expense_category",
                        path: "category",
                        component: () =>
                            import(/* webpackChunkName: "expense_category" */"./views/app/pages/expense/category_expense")
                    }
                ]
            },

            //Quotation
            {
                path: "/app/quotations",
                component: () => import(/* webpackChunkName: "quotations" */ "./views/app/pages/quotations"),
                redirect: "/app/quotations/list",
                children: [
                    {
                        name: "index_quotation",
                        path: "list",
                        component: () =>
                            import(
                                "./views/app/pages/quotations/index_quotation"
                            )
                    },
                    {
                        name: "store_quotation",
                        path: "store",
                        component: () =>
                            import(/* webpackChunkName: "store_quotation" */
                                "./views/app/pages/quotations/create_quotation"
                            )
                    },
                    {
                        name: "edit_quotation",
                        path: "edit/:id",
                        component: () =>
                            import(/* webpackChunkName: "edit_quotation" */
                                "./views/app/pages/quotations/edit_quotation"
                            )
                    },
                    {
                        name: "detail_quotation",
                        path: "detail/:id",
                        component: () =>
                            import(/* webpackChunkName: "detail_quotation" */
                                "./views/app/pages/quotations/detail_quotation"
                            )
                    },
                    {
                        name: "change_to_sale",
                        path: "create_sale/:id",
                        component: () =>
                            import(/* webpackChunkName: "change_to_sale" */"./views/app/pages/sales/change_to_sale.vue")
                    }
                ]
            },

            //Purchase
            {
                path: "/app/stock_counts",
                component: () => import(/* webpackChunkName: "stock_counts" */ "./views/app/pages/stock_counts"),
                redirect: "/app/stock_counts/list",
                children: [
                    {
                        name: "index_stock_count",
                        path: "list",
                        component: () =>
                            import(/* webpackChunkName: "index_stock_count" */"./views/app/pages/stock_counts/index_stock_count")
                    },
                    {
                        name: "store_stock_count",
                        path: "store",
                        component: () =>
                            import(/* webpackChunkName: "store_stock_count" */"./views/app/pages/stock_counts/create_stock_count")
                    },
                    {
                        name: "count_sheet",
                        path: "sheet/:id",
                        component: () =>
                            import(/* webpackChunkName: "count_sheet_20261014" */"./views/app/pages/stock_counts/count_sheet")
                    }
                ]
            },
            {
                path: "/app/goods_receipts",
                component: () => import(/* webpackChunkName: "goods_receipts" */ "./views/app/pages/goods_receipts"),
                redirect: "/app/goods_receipts/list",
                children: [
                    {
                        name: "index_goods_receipt",
                        path: "list",
                        component: () =>
                            import(/* webpackChunkName: "index_goods_receipt" */"./views/app/pages/goods_receipts/index_goods_receipt")
                    },
                    {
                        name: "store_goods_receipt",
                        path: "store",
                        component: () =>
                            import(/* webpackChunkName: "store_goods_receipt" */"./views/app/pages/goods_receipts/create_goods_receipt")
                    }
                ]
            },
            {
                path: "/app/purchases",
                component: () => import(/* webpackChunkName: "purchases" */ "./views/app/pages/purchases"),
                redirect: "/app/purchases/list",
                children: [
                    {
                        name: "index_purchases",
                        path: "list",
                        component: () =>
                            import(/* webpackChunkName: "index_purchases" */"./views/app/pages/purchases/index_purchase")
                    },
                    {
                        name: "store_purchase",
                        path: "store",
                        component: () =>
                            import(/* webpackChunkName: "store_purchase" */
                                "./views/app/pages/purchases/create_purchase"
                            )
                    },
                    {
                        name: "edit_purchase",
                        path: "edit/:id",
                        component: () =>
                            import(/* webpackChunkName: "edit_purchase" */"./views/app/pages/purchases/edit_purchase")
                    },
                    {
                        name: "purchase_approvals",
                        path: "approvals",
                        component: () =>
                            import(/* webpackChunkName: "purchase_approvals" */"./views/app/pages/purchases/approvals")
                    },
                    {
                        name: "detail_purchase",
                        path: "detail/:id",
                        component: () =>
                            import(/* webpackChunkName: "detail_purchase" */
                                "./views/app/pages/purchases/detail_purchase"
                            )
                    }
                ]
            },

            //Sale
            {
                path: "/app/sales",
                component: () => import(/* webpackChunkName: "sales" */ "./views/app/pages/sales"),
                redirect: "/app/sales/list",
                children: [
                    {
                        name: "deliveries",
                        path: "deliveries",
                        component: () =>
                            import(/* webpackChunkName: "deliveries" */"./views/app/pages/sales/deliveries")
                    },
                    {
                        name: "index_sales",
                        path: "list",
                        component: () =>
                            import(/* webpackChunkName: "index_sales" */"./views/app/pages/sales/index_sale")
                    },
                    {
                        name: "store_sale",
                        path: "store",
                        component: () =>
                            import(/* webpackChunkName: "store_sale" */"./views/app/pages/sales/create_sale")
                    },
                    {
                        name: "edit_sale",
                        path: "edit/:id",
                        component: () =>
                            import(/* webpackChunkName: "edit_sale" */"./views/app/pages/sales/edit_sale")
                    },
                    {
                        name: "detail_sale",
                        path: "detail/:id",
                        component: () =>
                            import(/* webpackChunkName: "detail_sale" */"./views/app/pages/sales/detail_sale")
                    }
                ]
            },

            // Sales Return
            {
                path: "/app/sale_return",
                component: () => import(/* webpackChunkName: "sale_return" */ "./views/app/pages/sale_return"),
                redirect: "/app/sale_return/list",
                children: [
                    {
                        name: "index_sale_return",
                        path: "list",
                        component: () =>
                            import(/* webpackChunkName: "index_sale_return" */
                                "./views/app/pages/sale_return/index_sale_return"
                            )
                    },
                    {
                        name: "store_sale_return",
                        path: "store",
                        component: () =>
                            import(/* webpackChunkName: "store_sale_return" */
                                "./views/app/pages/sale_return/create_sale_return"
                            )
                    },
                    {
                        name: "edit_sale_return",
                        path: "edit/:id",
                        component: () =>
                            import(/* webpackChunkName: "edit_sale_return" */
                                "./views/app/pages/sale_return/edit_sale_return"
                            )
                    },
                    {
                        name: "detail_sale_return",
                        path: "detail/:id",
                        component: () =>
                            import(/* webpackChunkName: "detail_sale_return" */
                                "./views/app/pages/sale_return/detail_sale_return"
                            )
                    }
                ]
            },

            // purchase Return
            {
                path: "/app/purchase_return",
                component: () => import(/* webpackChunkName: "purchase_return" */  "./views/app/pages/purchase_return"),
                redirect: "/app/purchase_return/list",
                children: [
                    {
                        name: "index_purchase_return",
                        path: "list",
                        component: () =>
                            import(/* webpackChunkName: "index_purchase_return" */
                                "./views/app/pages/purchase_return/index_purchase_return"
                            )
                    },
                    {
                        name: "store_purchase_return",
                        path: "store",
                        component: () =>
                            import(/* webpackChunkName: "store_purchase_return" */
                                "./views/app/pages/purchase_return/create_purchase_return"
                            )
                    },
                    {
                        name: "edit_purchase_return",
                        path: "edit/:id",
                        component: () =>
                            import(/* webpackChunkName: "edit_purchase_return" */
                                "./views/app/pages/purchase_return/edit_purchase_return"
                            )
                    },
                    {
                        name: "detail_purchase_return",
                        path: "detail/:id",
                        component: () =>
                            import(/* webpackChunkName: "detail_purchase_return" */
                                "./views/app/pages/purchase_return/detail_purchase_return"
                            )
                    }
                ]
            },

            // People
            {
                path: "/app/People",
                component: () => import(/* webpackChunkName: "People" */  "./views/app/pages/people"),
                redirect: "/app/People/Customers",
                children: [
                    // Customers
                    {
                        name: "Customers",
                        path: "Customers",
                        component: () =>
                            import(/* webpackChunkName: "Customers" */"./views/app/pages/people/customers")
                    },

                    // Suppliers
                    {
                        name: "Suppliers",
                        path: "Suppliers",
                        component: () =>
                            import(/* webpackChunkName: "Suppliers" */"./views/app/pages/people/providers")
                    },

                    // Users
                    {
                        name: "user",
                        path: "Users",
                        component: () =>
                            import(/* webpackChunkName: "Users" */"./views/app/pages/people/users")
                    }
                ]
            },

            // Settings
            {
                path: "/app/settings",
                component: () => import(/* webpackChunkName: "settings" */  "./views/app/pages/settings"),
                redirect: "/app/settings/System_settings",
                children: [
                    // Permissions
                    {
                        path: "permissions",
                        component: () =>
                            import(/* webpackChunkName: "permissions" */"./views/app/pages/settings/permissions"),
                        redirect: "/app/settings/permissions/list",
                        children: [
                            {
                                name: "groupPermission",
                                path: "list",
                                component: () =>
                                    import(/* webpackChunkName: "groupPermission" */
                                        "./views/app/pages/settings/permissions/Permissions"
                                    )
                            },
                            {
                                name: "store_permission",
                                path: "store",
                                component: () =>
                                    import(/* webpackChunkName: "store_permission" */
                                        "./views/app/pages/settings/permissions/Create_permission"
                                    )
                            },
                            {
                                name: "edit_permission",
                                path: "edit/:id",
                                component: () =>
                                    import(/* webpackChunkName: "edit_permission" */
                                        "./views/app/pages/settings/permissions/Edit_permission"
                                    )
                            }
                        ]
                    },

                    // categories
                    {
                        name: "categories",
                        path: "Categories",
                        component: () =>
                            import(/* webpackChunkName: "Categories" */"./views/app/pages/settings/categorie")
                    },

                    // brands
                    {
                        name: "brands",
                        path: "Brands",
                        component: () =>
                            import(/* webpackChunkName: "Brands" */"./views/app/pages/settings/brands")
                    },

                    // security
                    {
                        name: "security",
                        path: "Security",
                        component: () =>
                            import(/* webpackChunkName: "security" */"./views/app/pages/settings/security")
                    },
                    {
                        name: "backups_manager",
                        path: "Backups",
                        component: () =>
                            import(/* webpackChunkName: "backups_manager" */"./views/app/pages/settings/backups")
                    },
                    {
                        name: "webhooks",
                        path: "Webhooks",
                        component: () =>
                            import(/* webpackChunkName: "webhooks" */"./views/app/pages/settings/webhooks")
                    },
                    {
                        name: "recycle_bin",
                        path: "RecycleBin",
                        component: () =>
                            import(/* webpackChunkName: "recycle_bin" */"./views/app/pages/settings/recycle_bin")
                    },
                    {
                        name: "tenants",
                        path: "Tenants",
                        component: () =>
                            import(/* webpackChunkName: "tenants" */"./views/app/pages/settings/tenants")
                    },
                    {
                        name: "warehouse_locations",
                        path: "BinLocations",
                        component: () =>
                            import(/* webpackChunkName: "warehouse_locations" */"./views/app/pages/settings/warehouse_locations")
                    },
                    {
                        name: "accounting",
                        path: "Accounting",
                        component: () =>
                            import(/* webpackChunkName: "accounting" */"./views/app/pages/settings/accounting")
                    },
                    {
                        name: "tax_groups",
                        path: "TaxGroups",
                        component: () =>
                            import(/* webpackChunkName: "tax_groups" */"./views/app/pages/settings/tax_groups")
                    },
                    {
                        name: "customer_groups",
                        path: "CustomerGroups",
                        component: () =>
                            import(/* webpackChunkName: "customer_groups" */"./views/app/pages/settings/customer_groups")
                    },
                    {
                        name: "supplier_pricing",
                        path: "SupplierPricing",
                        component: () =>
                            import(/* webpackChunkName: "supplier_pricing" */"./views/app/pages/settings/supplier_pricing")
                    },
                    // currencies
                    {
                        name: "currencies",
                        path: "Currencies",
                        component: () =>
                            import(/* webpackChunkName: "Currencies" */"./views/app/pages/settings/currencies")
                    },

                    // units
                    {
                        name: "units",
                        path: "Units",
                        component: () =>
                            import(/* webpackChunkName: "units" */"./views/app/pages/settings/units")
                    },

                    // Backup
                    {
                        name: "Backup",
                        path: "Backup",
                        component: () =>
                            import(/* webpackChunkName: "Backup" */"./views/app/pages/settings/backup")
                    },

                    // Warehouses
                    {
                        name: "Warehouses",
                        path: "Warehouses",
                        component: () =>
                            import(/* webpackChunkName: "Warehouses" */"./views/app/pages/settings/warehouses")
                    },

                    // System Settings
                    {
                        name: "system_settings",
                        path: "System_settings",
                        component: () =>
                            import(/* webpackChunkName: "System_settings" */"./views/app/pages/settings/system_settings")
                    },
                     // updates
                    //  {
                    //     name: "updates",
                    //     path: "updates",
                    //     component: () =>
                    //         import(/* webpackChunkName: "updates" */"./views/app/pages/settings/updates")
                    // }
                ]
            },

            // Reports
            {
                path: "/app/reports",
                component: () => import("./views/app/pages/reports"),
                redirect: "/app/reports/profit_and_loss",
                children: [
                    {
                        name: "payments_purchases",
                        path: "payments_purchase",
                        component: () =>
                            import(/* webpackChunkName: "payments_purchases" */
                                "./views/app/pages/reports/payments/payments_purchases"
                            )
                    },
                    {
                        name: "payments_sales",
                        path: "payments_sale",
                        component: () =>
                            import(/* webpackChunkName: "payments_sales" */
                                "./views/app/pages/reports/payments/payments_sales"
                            )
                    },
                    {
                        name: "payments_purchases_returns",
                        path: "payments_purchases_returns",
                        component: () =>
                            import(/* webpackChunkName: "payments_purchases_returns" */
                                "./views/app/pages/reports/payments/payments_purchases_returns"
                            )
                    },
                    {
                        name: "payments_sales_returns",
                        path: "payments_sales_returns",
                        component: () =>
                            import(/* webpackChunkName: "payments_sales_returns" */
                                "./views/app/pages/reports/payments/payments_sales_returns"
                            )
                    },

                    {
                        name: "trial_balance",
                        path: "trial_balance",
                        component: () =>
                            import(/* webpackChunkName: "trial_balance" */
                                "./views/app/pages/reports/trial_balance"
                            )
                    },

                    {
                        name: "customer_credit",
                        path: "customer_credit",
                        component: () =>
                            import(/* webpackChunkName: "customer_credit" */
                                "./views/app/pages/reports/customer_credit"
                            )
                    },

                    {
                        name: "profit_and_loss",
                        path: "profit_and_loss",
                        component: () =>
                            import(/* webpackChunkName: "profit_and_loss" */
                                "./views/app/pages/reports/profit_and_loss"
                            )
                    },

                    {
                        name: "till_sessions",
                        path: "till_sessions",
                        component: () =>
                            import(/* webpackChunkName: "till_sessions" */
                                "./views/app/pages/reports/till_sessions"
                            )
                    },
                    {
                        name: "stock_movements",
                        path: "stock_movements",
                        component: () =>
                            import(/* webpackChunkName: "stock_movements" */
                                "./views/app/pages/reports/stock_movements"
                            )
                    },
                    {
                        name: "stock_valuation",
                        path: "stock_valuation",
                        component: () =>
                            import(/* webpackChunkName: "stock_valuation" */
                                "./views/app/pages/reports/stock_valuation"
                            )
                    },
                    {
                        name: "quantity_alerts",
                        path: "quantity_alerts",
                        component: () =>
                            import(/* webpackChunkName: "quantity_alerts" */
                                "./views/app/pages/reports/quantity_alerts"
                            )
                    },
                    {
                        name: "expiry_report",
                        path: "expiry_report",
                        component: () =>
                            import(/* webpackChunkName: "expiry_report" */
                                "./views/app/pages/reports/expiry_report"
                            )
                    },
                    {
                        name: "reorder",
                        path: "reorder",
                        component: () =>
                            import(/* webpackChunkName: "reorder" */
                                "./views/app/pages/reports/reorder"
                            )
                    },
                    {
                        name: "warehouse_report",
                        path: "warehouse_report",
                        component: () =>
                            import(/* webpackChunkName: "warehouse_report" */
                                "./views/app/pages/reports/warehouse_report"
                            )
                    },

                    {
                        name: "sales_report",
                        path: "sales_report",
                        component: () =>
                            import(/* webpackChunkName: "sales_report" */
                                "./views/app/pages/reports/sales_report"
                            )
                    },
                    {
                        name: "purchase_report",
                        path: "purchase_report",
                        component: () =>
                            import(/* webpackChunkName: "purchase_report" */
                                "./views/app/pages/reports/purchase_report"
                            )
                    },

                    {
                        name: "customers_report",
                        path: "customers_report",
                        component: () =>
                            import(/* webpackChunkName: "customers_report" */
                                "./views/app/pages/reports/customers_report"
                            )
                    },
                    {
                        name: "detail_customer_report",
                        path: "detail_customer/:id",
                        component: () =>
                            import(/* webpackChunkName: "detail_customer_report" */
                                "./views/app/pages/reports/detail_Customer_Report"
                            )
                    },

                    {
                        name: "providers_report",
                        path: "providers_report",
                        component: () =>
                            import(/* webpackChunkName: "providers_report" */
                                "./views/app/pages/reports/providers_report")
                    },
                    {
                        name: "detail_supplier_report",
                        path: "detail_supplier/:id",
                        component: () =>
                            import(/* webpackChunkName: "detail_supplier_report" */
                                "./views/app/pages/reports/detail_Supplier_Report"
                            )
                    }
                ]
            },

            {
                name: "profile",
                path: "/app/profile",
                component: () => import(/* webpackChunkName: "profile" */ "./views/app/pages/profile")
            }
        ]
    },

    {
        name: "pos",
        path: "/app/pos",
        // beforeEnter: authenticate,
        component: () => import(/* webpackChunkName: "pos" */"./views/app/pages/pos")
    },

    {
        path: "*",
        name: "NotFound",
        component: () => import(/* webpackChunkName: "NotFound" */"./views/app/pages/notFound")
    },

    {
        path: "not_authorize",
        name: "not_authorize",
        component: () => import(/* webpackChunkName: "not_authorize" */"./views/app/pages/NotAuthorize")
    }
];

const router = new Router({
    mode: "history",
    linkActiveClass: "open",
    routes,
    scrollBehavior(to, from, savedPosition) {
        return { x: 0, y: 0 };
    }
});

const originalPush = Router.prototype.push
Router.prototype.push = function push(location, onResolve, onReject) {
    if (onResolve || onReject) return originalPush.call(this, location, onResolve, onReject)
    return originalPush.call(this, location).catch(err => err)
}

router.beforeEach((to, from, next) => {

    // If this isn't an initial page load.
    if (to.path) {

        // Start the route progress bar.
        NProgress.start();
        NProgress.set(0.1);
    }
    next();

    if (
        store.state.language.language &&
        store.state.language.language !== i18n.locale
    ) {
        i18n.locale = store.state.language.language;
        next();
    } else if (!store.state.language.language) {
        store.dispatch("language/setLanguage", navigator.languages).then(() => {
            i18n.locale = store.state.language.language;
            next();
        });
    } else {
        next();
    }

});

router.afterEach(() => {
    // Remove initial loading
    const gullPreLoading = document.getElementById("loading_wrap");
    if (gullPreLoading) {
        gullPreLoading.style.display = "none";
    }
    // Complete the animation of the route progress bar.
    setTimeout(() => NProgress.done(), 500);
    // NProgress.done();

    // Close the drawers once a page is chosen (on a phone or tablet the
    // sidebar sits over the page). See tests/js/largeSidebar.test.js.
    store.dispatch("closeDrawersOnNarrow", window.innerWidth);
    if (window.innerWidth <= 1200 && store.getters.getCompactSideBarToggleProperties.isSideNavOpen) {
        store.dispatch("changeCompactSidebarProperties");
    }
});


async function Check_Token(to, from, next) {
    let token = to.params.token;
    const res = await axios.get('password/find/' + token).then(response => response.data);

    if (!res.success) {
        next("/app/sessions/signIn");
    } else {
        return next();
    }
}


export default router;
