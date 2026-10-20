<template>
  <div
    class="side-content-wrap"
    @mouseenter="isMenuOver = true"
    @mouseleave="isMenuOver = false"
    @touchstart="isMenuOver = true"
  >
    <vue-perfect-scrollbar
      :settings="{ suppressScrollX: true, wheelPropagation: false }"
      :class="{ open: getSideBarToggleProperties.isSideNavOpen }"
      ref="myData"
      class="sidebar-left rtl-ps-none ps scroll"
    >
      <div>
        <ul class="navigation-left">
          <li
            @mouseenter="toggleSubMenu"
            :class="{ active: selectedParentMenu == 'dashboard' }"
            class="nav-item"
            data-item="dashboard"
          >
            <router-link tag="a" class="nav-item-hold" to="/app/dashboard">
              <i class="nav-icon i-Bar-Chart"></i>
              <span class="nav-text">{{ $t("dashboard") }}</span>
            </router-link>
          </li>
          <li
            v-show="currentUserPermissions
            && (currentUserPermissions.includes('products_add')
            || currentUserPermissions.includes('products_view')
            || currentUserPermissions.includes('products_edit')
            || currentUserPermissions.includes('manufacturing')
            || currentUserPermissions.includes('barcode_view'))"
            @mouseenter="toggleSubMenu"
            class="nav-item"
            :class="{ active: selectedParentMenu == 'products' }"
            data-item="products"
            :data-submenu="true"
          >
            <a class="nav-item-hold" href="#">
              <i class="nav-icon i-Library-2"></i>
              <span class="nav-text">{{$t('Products')}}</span>
            </a>
            <div class="triangle"></div>
          </li>
          <li
            v-show="currentUserPermissions
              && (currentUserPermissions.includes('adjustment_view')
              || currentUserPermissions.includes('adjustment_add')
              || currentUserPermissions.includes('stock_count_view')
              || currentUserPermissions.includes('stock_count_add'))"
            @mouseenter="toggleSubMenu"
            class="nav-item"
            :class="{ active: selectedParentMenu == 'adjustments' }"
            data-item="adjustments"
            :data-submenu="true"
          >
            <a class="nav-item-hold" href="#">
              <i class="nav-icon i-Edit-Map"></i>
              <span class="nav-text">{{$t('StockAdjustement')}}</span>
            </a>
            <div class="triangle"></div>
          </li>
          <li
            v-show="currentUserPermissions && (currentUserPermissions.includes('transfer_view')
                     || currentUserPermissions.includes('transfer_add'))"
            @mouseenter="toggleSubMenu"
            class="nav-item"
            :class="{ active: selectedParentMenu == 'transfers' }"
            data-item="transfers"
            :data-submenu="true"
          >
            <a class="nav-item-hold" href="#">
              <i class="nav-icon i-Back"></i>
              <span class="nav-text">{{$t('StockTransfers')}}</span>
            </a>
            <div class="triangle"></div>
          </li>

          <li
            v-show="currentUserPermissions && (currentUserPermissions.includes('Purchases_view')
                        || currentUserPermissions.includes('Purchases_add')
                        || currentUserPermissions.includes('purchases_approve')
                        || currentUserPermissions.includes('goods_receipt_view')
                        || currentUserPermissions.includes('goods_receipt_add'))"
            @mouseenter="toggleSubMenu"
            class="nav-item"
            :class="{ active: selectedParentMenu == 'purchases' }"
            data-item="purchases"
            :data-submenu="true"
          >
            <a class="nav-item-hold" href="#">
              <i class="nav-icon i-Receipt"></i>
              <span class="nav-text">{{$t('Purchases')}}</span>
            </a>
            <div class="triangle"></div>
          </li>
          <li
            v-show="currentUserPermissions && (currentUserPermissions.includes('Sales_view')
                        || currentUserPermissions.includes('Sales_add'))"
            class="nav-item"
            @mouseenter="toggleSubMenu"
            :class="{ active: selectedParentMenu == 'sales' }"
            data-item="sales"
            :data-submenu="true"
          >
            <a class="nav-item-hold" href="#">
              <i class="nav-icon i-Full-Cart"></i>
              <span class="nav-text">{{$t('Sales')}}</span>
            </a>
            <div class="triangle"></div>
          </li>
          <li
            v-show="currentUserPermissions && (currentUserPermissions.includes('Quotations_view')
                        || currentUserPermissions.includes('Quotations_add'))"
            class="nav-item"
            @mouseenter="toggleSubMenu"
            :class="{ active: selectedParentMenu == 'quotations' }"
            data-item="quotations"
            :data-submenu="true"
          >
            <a class="nav-item-hold" href="#">
              <i class="nav-icon i-File-Copy"></i>
              <span class="nav-text">{{$t('Quotations')}}</span>
            </a>
            <div class="triangle"></div>
          </li>
          <li
            v-show="currentUserPermissions && (currentUserPermissions.includes('Sale_Returns_view')
                        || currentUserPermissions.includes('Sale_Returns_add'))"
            @mouseenter="toggleSubMenu"
            class="nav-item"
            :class="{ active: selectedParentMenu == 'sale_return' }"
            data-item="sale_return"
            :data-submenu="true"
          >
            <a class="nav-item-hold" href="#">
              <i class="nav-icon i-Right"></i>
              <span class="nav-text">{{$t('SalesReturn')}}</span>
            </a>
            <div class="triangle"></div>
          </li>
          <li
            v-show="currentUserPermissions && (currentUserPermissions.includes('Purchase_Returns_view')
                        || currentUserPermissions.includes('Purchase_Returns_add'))"
            @mouseenter="toggleSubMenu"
            class="nav-item"
            :class="{ active: selectedParentMenu == 'purchase_return' }"
            data-item="purchase_return"
            :data-submenu="true"
          >
            <a class="nav-item-hold" href="#">
              <i class="nav-icon i-Left"></i>
              <span class="nav-text">{{$t('PurchasesReturn')}}</span>
            </a>
            <div class="triangle"></div>
          </li>
          <!-- Expenses had a sub-menu but no item to open it, so the screens
               could only be reached by typing the address. -->
          <li
            v-show="currentUserPermissions && (currentUserPermissions.includes('expense_view')
                        || currentUserPermissions.includes('expense_add'))"
            @mouseenter="toggleSubMenu"
            :class="{ active: selectedParentMenu == 'expenses' }"
            class="nav-item"
            data-item="expenses"
            :data-submenu="true"
          >
            <a class="nav-item-hold" href="#">
              <i class="nav-icon i-Money-Bag"></i>
              <span class="nav-text">{{$t('Expenses')}}</span>
            </a>
            <div class="triangle"></div>
          </li>
          <li
            v-show="currentUserPermissions && (currentUserPermissions.includes('Customers_view')
                        ||currentUserPermissions.includes('Suppliers_view')
                        ||currentUserPermissions.includes('users_view'))"
            @mouseenter="toggleSubMenu"
            :class="{ active: selectedParentMenu == 'People' }"
            class="nav-item"
            data-item="People"
            :data-submenu="true"
          >
            <a class="nav-item-hold" href="#">
              <i class="nav-icon i-Business-Mens"></i>
              <span class="nav-text">{{$t('People')}}</span>
            </a>
            <div class="triangle"></div>
          </li>

          <li
            v-show="currentUserPermissions && (currentUserPermissions.includes('setting_system')
                        || currentUserPermissions.includes('warehouse') || currentUserPermissions.includes('brand')
                        || currentUserPermissions.includes('backup')    || currentUserPermissions.includes('unit')
                        || currentUserPermissions.includes('currency')  || currentUserPermissions.includes('category')
                        || currentUserPermissions.includes('tax_group') || currentUserPermissions.includes('customer_group')
                        || currentUserPermissions.includes('accounting')
                        || currentUserPermissions.includes('Suppliers_view')
                        || currentUserPermissions.includes('users_view')
                        || currentUserPermissions.includes('permissions_view'))"
            @mouseenter="toggleSubMenu"
            :class="{ active: selectedParentMenu == 'settings' }"
            class="nav-item"
            data-item="settings"
            :data-submenu="true"
          >
            <a class="nav-item-hold" href="#">
              <i class="nav-icon i-Data-Settings"></i>
              <span class="nav-text">{{$t('Settings')}}</span>
            </a>
            <div class="triangle"></div>
          </li>

          <li
            v-show="currentUserPermissions &&
                     (currentUserPermissions.includes('Reports_payments_Sales')
                     || currentUserPermissions.includes('Reports_payments_Purchases')
                     || currentUserPermissions.includes('Reports_payments_Sale_Returns')
                     || currentUserPermissions.includes('Reports_payments_purchase_Return')
                     || currentUserPermissions.includes('Warehouse_report')
                     || currentUserPermissions.includes('Reports_profit')
                     || currentUserPermissions.includes('Reports_purchase')
                     || currentUserPermissions.includes('Reports_quantity_alerts')
                     || currentUserPermissions.includes('Reports_sales')
                     || currentUserPermissions.includes('Reports_suppliers')
                     || currentUserPermissions.includes('Reports_customers')
                     || currentUserPermissions.includes('Customers_view')
                     || currentUserPermissions.includes('accounting')
                     || currentUserPermissions.includes('Pos_view'))"
            @mouseenter="toggleSubMenu"
            :class="{ active: selectedParentMenu == 'reports' }"
            class="nav-item"
            data-item="reports"
            :data-submenu="true"
          >
            <a class="nav-item-hold" href="#">
              <i class="nav-icon i-Line-Chart"></i>
              <span class="nav-text">{{$t('Reports')}}</span>
            </a>
            <div class="triangle"></div>
          </li>
        </ul>
      </div>
    </vue-perfect-scrollbar>

    <vue-perfect-scrollbar
      :class="{ open: getSideBarToggleProperties.isSecondarySideNavOpen }"
      :settings="{ suppressScrollX: true, wheelPropagation: false }"
      class="sidebar-left-secondary ps rtl-ps-none"
    >
      <div ref="sidebarChild">
        <ul
          class="childNav d-none"
          data-parent="products"
          :class="{ 'd-block': selectedParentMenu == 'products' }"
        >
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('products_add')"
          >
            <router-link tag="a" class to="/app/products/store">
              <i class="nav-icon i-Add-File"></i>
              <span class="item-name">{{$t('AddProduct')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('products_view')"
          >
            <router-link tag="a" class to="/app/products/list">
              <i class="nav-icon i-Files"></i>
              <span class="item-name">{{$t('productsList')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('manufacturing')"
          >
            <router-link tag="a" class to="/app/products/manufacturing">
              <i class="nav-icon i-Gear-2"></i>
              <span class="item-name">{{$t('Manufacturing')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('barcode_view')"
          >
            <router-link tag="a" class to="/app/products/barcode">
              <i class="nav-icon i-Bar-Code"></i>
              <span class="item-name">{{$t('Printbarcode')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('products_view')"
          >
            <router-link tag="a" class to="/app/products/batches">
              <i class="nav-icon i-Split-Vertical"></i>
              <span class="item-name">{{$t('Batches')}}</span>
            </router-link>
          </li>
          <li class="nav-item" v-if="currentUserPermissions && currentUserPermissions.includes('products_view') && currentUser && currentUser.all_warehouses">
            <router-link tag="a" class to="/app/products/traceability">
              <i class="nav-icon i-Magnifi-Glass1"></i>
              <span class="item-name">Trace Explorer</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('products_view')"
          >
            <router-link tag="a" class to="/app/products/serials">
              <i class="nav-icon i-Bar-Code"></i>
              <span class="item-name">{{$t('SerialLookup')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('products_edit')"
          >
            <router-link tag="a" class to="/app/products/marketplace">
              <i class="nav-icon i-Shop"></i>
              <span class="item-name">{{$t('Marketplace')}}</span>
            </router-link>
          </li>
        </ul>

        <ul
          class="childNav d-none"
          data-parent="adjustments"
          :class="{ 'd-block': selectedParentMenu == 'adjustments' }"
        >
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('adjustment_add')"
          >
            <router-link tag="a" class to="/app/adjustments/store">
              <i class="nav-icon i-Add-File"></i>
              <span class="item-name">{{$t('CreateAdjustment')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('adjustment_view')"
          >
            <router-link tag="a" class to="/app/adjustments/list">
              <i class="nav-icon i-Files"></i>
              <span class="item-name">{{$t('ListAdjustments')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('stock_count_add')"
          >
            <router-link tag="a" class to="/app/stock_counts/store">
              <i class="nav-icon i-Add-File"></i>
              <span class="item-name">{{$t('OpenStockCount')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('stock_count_view')"
          >
            <router-link tag="a" class to="/app/stock_counts/list">
              <i class="nav-icon i-Check"></i>
              <span class="item-name">{{$t('StockCounts')}}</span>
            </router-link>
          </li>
        </ul>

        <ul
          class="childNav d-none"
          data-parent="transfers"
          :class="{ 'd-block': selectedParentMenu == 'transfers' }"
        >
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('transfer_add')"
          >
            <router-link tag="a" class to="/app/transfers/store">
              <i class="nav-icon i-Add-File"></i>
              <span class="item-name">{{$t('CreateTransfer')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('transfer_view')"
          >
            <router-link tag="a" class to="/app/transfers/list">
              <i class="nav-icon i-Files"></i>
              <span class="item-name">{{$t('ListTransfers')}}</span>
            </router-link>
          </li>
          <li class="nav-item" v-if="currentUserPermissions && currentUserPermissions.includes('transfer_view')">
            <router-link tag="a" class to="/app/transfers/branch_requests">
              <i class="nav-icon i-Files"></i>
              <span class="item-name">Branch Requests</span>
            </router-link>
          </li>
        </ul>

        <ul
          class="childNav d-none"
          data-parent="expenses"
          :class="{ 'd-block': selectedParentMenu == 'expenses' }"
        >
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('expense_add')"
          >
            <router-link tag="a" class to="/app/expenses/store">
              <i class="nav-icon i-Add-File"></i>
              <span class="item-name">{{$t('Create_Expense')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('expense_view')"
          >
            <router-link tag="a" class to="/app/expenses/list">
              <i class="nav-icon i-Files"></i>
              <span class="item-name">{{$t('ListExpenses')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('expense_view')"
          >
            <router-link tag="a" class to="/app/expenses/category">
              <i class="nav-icon i-Files"></i>
              <span class="item-name">{{$t('Expense_Category')}}</span>
            </router-link>
          </li>
        </ul>



        <ul
          class="childNav d-none"
          data-parent="purchases"
          :class="{ 'd-block': selectedParentMenu == 'purchases' }"
        >
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Purchases_add')"
          >
            <router-link tag="a" class to="/app/purchases/store">
              <i class="nav-icon i-Add-File"></i>
              <span class="item-name">{{$t('AddPurchase')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Purchases_view')"
          >
            <router-link tag="a" class to="/app/purchases/list">
              <i class="nav-icon i-Files"></i>
              <span class="item-name">{{$t('ListPurchases')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('purchases_approve')"
          >
            <router-link tag="a" class to="/app/purchases/approvals">
              <i class="nav-icon i-Check"></i>
              <span class="item-name">{{$t('PurchaseApprovals')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('goods_receipt_add')"
          >
            <router-link tag="a" class to="/app/goods_receipts/store">
              <i class="nav-icon i-Add-File"></i>
              <span class="item-name">{{$t('CreateGoodsReceipt')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('goods_receipt_view')"
          >
            <router-link tag="a" class to="/app/goods_receipts/list">
              <i class="nav-icon i-Receipt-4"></i>
              <span class="item-name">{{$t('GoodsReceipts')}}</span>
            </router-link>
          </li>
        </ul>

        <ul
          class="childNav d-none"
          data-parent="sales"
          :class="{ 'd-block': selectedParentMenu == 'sales' }"
        >
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Sales_add')"
          >
            <router-link tag="a" class to="/app/sales/store">
              <i class="nav-icon i-Add-File"></i>
              <span class="item-name">{{$t('AddSale')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Sales_view')"
          >
            <router-link tag="a" class to="/app/sales/list">
              <i class="nav-icon i-Files"></i>
              <span class="item-name">{{$t('ListSales')}}</span>
            </router-link>
          </li>
          <li class="nav-item" v-if="currentUserPermissions && currentUserPermissions.includes('Sales_view')">
            <router-link tag="a" class to="/app/sales/customer_orders">
              <i class="nav-icon i-Files"></i>
              <span class="item-name">Customer Orders & Pre-orders</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Sales_view')"
          >
            <router-link tag="a" class to="/app/sales/deliveries">
              <i class="nav-icon i-Truck"></i>
              <span class="item-name">{{$t('Deliveries')}}</span>
            </router-link>
          </li>
        </ul>

        <ul
          class="childNav d-none"
          data-parent="quotations"
          :class="{ 'd-block': selectedParentMenu == 'quotations' }"
        >
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Quotations_add')"
          >
            <router-link tag="a" class to="/app/quotations/store">
              <i class="nav-icon i-Add-File"></i>
              <span class="item-name">{{$t('CreateQuotation')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Quotations_view')"
          >
            <router-link tag="a" class to="/app/quotations/list">
              <i class="nav-icon i-Files"></i>
              <span class="item-name">{{$t('ListQuotations')}}</span>
            </router-link>
          </li>
        </ul>

        <ul
          class="childNav d-none"
          data-parent="sale_return"
          :class="{ 'd-block': selectedParentMenu == 'sale_return' }"
        >
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Sale_Returns_add')"
          >
            <router-link tag="a" class to="/app/sale_return/store">
              <i class="nav-icon i-Add-File"></i>
              <span class="item-name">{{$t('AddReturn')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Sale_Returns_view')"
          >
            <router-link tag="a" class to="/app/sale_return/list">
              <i class="nav-icon i-Files"></i>
              <span class="item-name">{{$t('ListReturns')}}</span>
            </router-link>
          </li>
        </ul>

        <ul
          class="childNav d-none"
          data-parent="purchase_return"
          :class="{ 'd-block': selectedParentMenu == 'purchase_return' }"
        >
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Purchase_Returns_add')"
          >
            <router-link tag="a" class to="/app/purchase_return/store">
              <i class="nav-icon i-Add-File"></i>
              <span class="item-name">{{$t('AddReturn')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Purchase_Returns_view')"
          >
            <router-link tag="a" class to="/app/purchase_return/list">
              <i class="nav-icon i-Files"></i>
              <span class="item-name">{{$t('ListReturns')}}</span>
            </router-link>
          </li>
        </ul>

        <ul
          class="childNav d-none"
          data-parent="People"
          :class="{ 'd-block': selectedParentMenu == 'People' }"
        >
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Customers_view')"
          >
            <router-link tag="a" class to="/app/People/Customers">
              <i class="nav-icon i-Administrator"></i>
              <span class="item-name">{{$t('Customers_Menu')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Suppliers_view')"
          >
            <router-link tag="a" class to="/app/People/Suppliers">
              <i class="nav-icon i-Administrator"></i>
              <span class="item-name">{{$t('Suppliers')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('users_view')"
          >
            <router-link tag="a" class to="/app/People/Users">
              <i class="nav-icon i-Administrator"></i>
              <span class="item-name">{{$t('Users')}}</span>
            </router-link>
          </li>
        </ul>

        <ul
          class="childNav d-none"
          data-parent="settings"
          :class="{ 'd-block': selectedParentMenu == 'settings' }"
        >

          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('permissions_view')"
          >
            <router-link tag="a" class to="/app/settings/permissions">
              <i class="nav-icon i-Key"></i>
              <span class="item-name">{{$t('GroupPermissions')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('warehouse')"
          >
            <router-link tag="a" class to="/app/settings/Warehouses">
              <i class="nav-icon i-Clothing-Store"></i>
              <span class="item-name">{{$t('Warehouses')}}</span>
            </router-link>
          </li>
          <li class="nav-item" v-if="currentUserPermissions && currentUserPermissions.includes('setting_system')">
            <router-link tag="a" class to="/app/settings/OnlineShop">
              <i class="nav-icon i-Shopping-Bag"></i>
              <span class="item-name">Online Shop</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('warehouse')"
          >
            <router-link tag="a" class to="/app/settings/BinLocations">
              <i class="nav-icon i-Split-FourSquareWindow"></i>
              <span class="item-name">{{$t('BinLocations')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('accounting')"
          >
            <router-link tag="a" class to="/app/settings/Accounting">
              <i class="nav-icon i-Financial"></i>
              <span class="item-name">{{$t('Accounting')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('category')"
          >
            <router-link tag="a" class to="/app/settings/Categories">
              <i class="nav-icon i-Duplicate-Layer"></i>
              <span class="item-name">{{$t('Categories')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('brand')"
          >
            <router-link tag="a" class to="/app/settings/Brands">
              <i class="nav-icon i-Bookmark"></i>
              <span class="item-name">{{$t('Brand')}}</span>
            </router-link>
          </li>


          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('unit')"
          >
            <router-link tag="a" class to="/app/settings/Units">
              <i class="nav-icon i-Quotes"></i>
              <span class="item-name">{{$t('Units')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('currency')"
          >
            <router-link tag="a" class to="/app/settings/Currencies">
              <i class="nav-icon i-Coins"></i>
              <span class="item-name">{{$t('Currencies')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('tax_group')"
          >
            <router-link tag="a" class to="/app/settings/TaxGroups">
              <i class="nav-icon i-Calculator"></i>
              <span class="item-name">{{$t('TaxGroups')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('customer_group')"
          >
            <router-link tag="a" class to="/app/settings/CustomerGroups">
              <i class="nav-icon i-Conference"></i>
              <span class="item-name">{{$t('CustomerGroups')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Suppliers_view')"
          >
            <router-link tag="a" class to="/app/settings/SupplierPricing">
              <i class="nav-icon i-Tag-2"></i>
              <span class="item-name">{{$t('SupplierPricing')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('backup')"
          >
            <router-link tag="a" class to="/app/settings/Backup">
              <i class="nav-icon i-Data-Backup"></i>
              <span class="item-name">{{$t('Backup')}}</span>
            </router-link>
          </li>
          <!-- Also in the user menu, for staff who have no Settings menu (DATATEST K89). -->
          <li class="nav-item">
            <router-link tag="a" class to="/app/settings/Security">
              <i class="nav-icon i-Lock-2"></i>
              <span class="item-name">{{$t('Security')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('users_view')"
          >
            <router-link tag="a" class to="/app/settings/Backups">
              <i class="nav-icon i-Data-Save"></i>
              <span class="item-name">{{$t('Backups')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('users_view')"
          >
            <router-link tag="a" class to="/app/settings/Webhooks">
              <i class="nav-icon i-Link"></i>
              <span class="item-name">{{$t('Webhooks')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('users_view')"
          >
            <router-link tag="a" class to="/app/settings/RecycleBin">
              <i class="nav-icon i-Recycling-2"></i>
              <span class="item-name">{{$t('RecycleBin')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('users_view')"
          >
            <router-link tag="a" class to="/app/settings/Tenants">
              <i class="nav-icon i-Building"></i>
              <span class="item-name">{{$t('Tenants')}}</span>
            </router-link>
          </li>


        </ul>

        <ul
          class="childNav d-none"
          data-parent="reports"
          :class="{ 'd-block': selectedParentMenu == 'reports' }"
        >

          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Reports_profit')"
          >
            <router-link tag="a" class to="/app/reports/profit_and_loss">
              <i class="nav-icon i-Financial"></i>
              <span class="item-name">{{$t('ProfitandLoss')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Customers_view')"
          >
            <router-link tag="a" class to="/app/reports/customer_credit">
              <i class="nav-icon i-Credit-Card"></i>
              <span class="item-name">{{$t('CustomerCredit')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('accounting')"
          >
            <router-link tag="a" class to="/app/reports/trial_balance">
              <i class="nav-icon i-Books"></i>
              <span class="item-name">{{$t('TrialBalance')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Pos_view')"
          >
            <router-link tag="a" class to="/app/reports/till_sessions">
              <i class="nav-icon i-Cash-register2"></i>
              <span class="item-name">{{$t('TillSessions')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Reports_quantity_alerts')"
          >
            <router-link tag="a" class to="/app/reports/stock_movements">
              <i class="nav-icon i-Loading-2"></i>
              <span class="item-name">{{$t('StockMovements')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Reports_quantity_alerts')"
          >
            <router-link tag="a" class to="/app/reports/stock_valuation">
              <i class="nav-icon i-Money-2"></i>
              <span class="item-name">{{$t('StockValuation')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Reports_quantity_alerts')"
          >
            <router-link tag="a" class to="/app/reports/quantity_alerts">
              <i class="nav-icon i-Bell"></i>
              <span class="item-name">{{$t('ProductQuantityAlerts')}}</span>
            </router-link>
          </li>

          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Reports_quantity_alerts')"
          >
            <router-link tag="a" class to="/app/reports/expiry_report">
              <i class="nav-icon i-Clock-Back"></i>
              <span class="item-name">{{$t('ExpiryReport')}}</span>
            </router-link>
          </li>

          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Reports_quantity_alerts')"
          >
            <router-link tag="a" class to="/app/reports/reorder">
              <i class="nav-icon i-Shopping-Cart"></i>
              <span class="item-name">{{$t('ReorderSuggestions')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Warehouse_report')"
          >
            <router-link tag="a" class to="/app/reports/warehouse_report">
              <i class="nav-icon i-Pie-Chart"></i>
              <span class="item-name">{{$t('Warehouse_report')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Reports_sales')"
          >
            <router-link tag="a" class to="/app/reports/sales_report">
              <i class="nav-icon i-Line-Chart"></i>
              <span class="item-name">{{$t('SalesReport')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Reports_sales')"
          >
            <router-link tag="a" class to="/app/reports/product_sales_analytics">
              <i class="nav-icon i-Line-Chart2"></i>
              <span class="item-name">Product Sales Analytics</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Reports_purchase')"
          >
            <router-link tag="a" class to="/app/reports/purchase_report">
              <i class="nav-icon i-Bar-Chart5"></i>
              <span class="item-name">{{$t('PurchasesReport')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Reports_customers')"
          >
            <router-link tag="a" class to="/app/reports/customers_report">
              <i class="nav-icon i-Bar-Chart"></i>
              <span class="item-name">{{$t('CustomersReport')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Reports_suppliers')"
          >
            <router-link tag="a" class to="/app/reports/providers_report">
              <i class="nav-icon i-Pie-Chart"></i>
              <span class="item-name">{{$t('SuppliersReport')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Reports_payments_Sales')"
          >
            <router-link tag="a" class to="/app/reports/payments_sale">
              <i class="nav-icon i-Credit-Card"></i>
              <span class="item-name">{{$t('PaymentsSales')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Reports_payments_Purchases')"
          >
            <router-link tag="a" class to="/app/reports/payments_purchase">
              <i class="nav-icon i-Credit-Card2"></i>
              <span class="item-name">{{$t('PaymentsPurchases')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Reports_payments_Sale_Returns')"
          >
            <router-link tag="a" class to="/app/reports/payments_sales_returns">
              <i class="nav-icon i-Back"></i>
              <span class="item-name">{{$t('payments_Sales_Return')}}</span>
            </router-link>
          </li>
          <li
            class="nav-item"
            v-if="currentUserPermissions && currentUserPermissions.includes('Reports_payments_purchase_Return')"
          >
            <router-link tag="a" class to="/app/reports/payments_purchases_returns">
              <i class="nav-icon i-Arrow-Back"></i>
              <span class="item-name">{{$t('payments_Purchases_Return')}}</span>
            </router-link>
          </li>
        </ul>
      </div>
    </vue-perfect-scrollbar>
    <!-- Tap outside to close. On a phone or tablet the sidebar is a drawer
         over the page, so the overlay is there whenever the drawer is open,
         not only when the second-level menu is. -->
    <div
      @click="removeOverlay()"
      class="sidebar-overlay"
      :class="{
        open: getSideBarToggleProperties.isSecondarySideNavOpen ||
          (isNarrow && getSideBarToggleProperties.isSideNavOpen),
        'main-only': !getSideBarToggleProperties.isSecondarySideNavOpen
      }"
    ></div>
  </div>
  <!--=============== Left side End ================-->
</template>

<script>
import Topnav from "./TopNav";
import { isMobile } from "mobile-device-detect";

import { mapGetters, mapActions } from "vuex";

export default {
  components: {
    Topnav
  },

  data() {
    return {
      isDisplay: true,
      isMenuOver: false,
      isStyle: true,
      selectedParentMenu: "",
      isMobile,
      isNarrow: window.innerWidth <= 1200
    };
  },
  mounted() {
    this.toggleSelectedParentMenu();
    window.addEventListener("resize", this.handleWindowResize);
    document.addEventListener("click", this.returnSelectedParentMenu);
    this.handleWindowResize();
  },

  beforeDestroy() {
    document.removeEventListener("click", this.returnSelectedParentMenu);
    window.removeEventListener("resize", this.handleWindowResize);
  },

  computed: {
    ...mapGetters(["getSideBarToggleProperties", "currentUserPermissions", "currentUser"])
  },

  methods: {
    ...mapActions([
      "changeSecondarySidebarProperties",
      "changeSecondarySidebarPropertiesViaMenuItem",
      "changeSecondarySidebarPropertiesViaOverlay",
      "changeSidebarProperties",
      "setSidebarOpen"
    ]),

    handleWindowResize() {
      this.isNarrow = window.innerWidth <= 1200;
      if (window.innerWidth <= 1200) {
        if (this.getSideBarToggleProperties.isSideNavOpen) {
          this.changeSidebarProperties();
        }
        if (this.getSideBarToggleProperties.isSecondarySideNavOpen) {
          this.changeSecondarySidebarProperties();
        }
      } else {
        if (!this.getSideBarToggleProperties.isSideNavOpen) {
          this.changeSidebarProperties();
        }
      }
    },
    toggleSelectedParentMenu() {
      const currentParentUrl = this.$route.path
        .split("/")
        .filter(x => x !== "")[1];
      if (currentParentUrl !== undefined || currentParentUrl !== null) {
        this.selectedParentMenu = currentParentUrl.toLowerCase();
      } else {
        this.selectedParentMenu = "dashboard";
      }
    },
    toggleSubMenu(e) {
      let hasSubmenu = e.target.dataset.submenu;
      let parent = e.target.dataset.item;

      if (hasSubmenu) {
        this.selectedParentMenu = parent;

        this.changeSecondarySidebarPropertiesViaMenuItem(true);
      } else {
        this.selectedParentMenu = parent;
        this.changeSecondarySidebarPropertiesViaMenuItem(false);
      }
    },

    // Close, never toggle: the overlay can now be tapped with only the main
    // drawer open, and a toggle would have opened the second-level menu.
    removeOverlay() {
      if (this.getSideBarToggleProperties.isSecondarySideNavOpen) {
        this.changeSecondarySidebarPropertiesViaOverlay();
      }
      if (window.innerWidth <= 1200) {
        this.setSidebarOpen(false);
      }
      this.toggleSelectedParentMenu();
    },
    returnSelectedParentMenu() {
      if (!this.isMenuOver) {
        this.toggleSelectedParentMenu();
      }
    },

    toggleSidebarDropdwon(event) {
      let dropdownMenus = this.$el.querySelectorAll(".dropdown-sidemenu.open");

      event.currentTarget.classList.toggle("open");

      dropdownMenus.forEach(dropdown => {
        dropdown.classList.remove("open");
      });
    }
  }
};
</script>

<style lang="" scoped>
</style>

