<template>
  <div class="pos_page">
    <div class="container-fluid p-0 app-admin-wrap layout-sidebar-large clearfix" id="pos">
      <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

      <!-- The till must say plainly when it is working without a connection. -->
      <div v-if="!isLoading && (isOffline || pendingOffline > 0)" class="offline-bar">
        <span v-if="isOffline" class="badge badge-warning mr-2">{{ $t('Offline') }}</span>
        <span v-if="isOffline">{{ $t('OfflineTillNotice') }}</span>
        <span v-if="pendingOffline > 0" class="ml-2">
          {{ $t('HeldSales') }}: <strong>{{ pendingOffline }}</strong>
        </span>
        <a v-if="!isOffline && pendingOffline > 0" class="ml-2 cursor-pointer text-primary"
           @click="syncOffline">{{ $t('SyncNow') }}</a>
      </div>

      <!-- When the shop requires a session, say so before the cashier finds
           out by having a full basket refused. -->
      <div v-if="!isLoading && tillRequired && !till" class="offline-bar till-blocked">
        <span class="badge badge-danger mr-2">{{ $t('TillClosed') }}</span>
        <span>{{ $t('TillRequiredNotice') }}</span>
        <a class="ml-2 cursor-pointer text-primary" @click="Till_Panel()">{{ $t('OpenTill') }}</a>
      </div>

      <b-row v-if="!isLoading">
        <!-- Card Left Panel Details Sale-->
        <b-col lg="5">
          <b-card no-body class="card-order">
            <div class="main-header">
              <div class="logo">
                <router-link to="/app/dashboard">
                  <img :src="'/images/'+currentUser.logo" alt width="60" height="60">
                </router-link>
              </div>
              <div class="mx-auto"></div>

              <div class="header-part-right">
                <!-- The drawer, where the cashier can always see it. -->
                <div
                  class="till-chip"
                  :class="till ? 'till-is-open' : 'till-is-shut'"
                  @click="Till_Panel()"
                  :title="$t('TillSession')"
                >
                  <i class="i-Money-2"></i>
                  <span v-if="till">{{ currentUser.currency }} {{ tillExpected }}</span>
                  <span v-else>{{ tillRequired ? $t('TillClosed') : 'Till optional' }}</span>
                </div>

                <!-- Full screen toggle -->
                <i
                  class="i-Full-Screen header-icon d-none d-sm-inline-block"
                  @click="handleFullScreen"
                ></i>
                <!-- Grid menu Dropdown -->

                <div class="dropdown">
                  <b-dropdown
                    id="dropdown"
                    text="Dropdown Button"
                    class="m-md-2"
                    toggle-class="text-decoration-none"
                    no-caret
                    right
                    variant="link"
                  >
                    <template slot="button-content">
                      <i
                        class="i-Globe text-muted header-icon"
                        role="button"
                        id="dropdownMenuButton"
                        data-toggle="dropdown"
                        aria-haspopup="true"
                        aria-expanded="false"
                      ></i>
                    </template>
                    <vue-perfect-scrollbar
                      :settings="{ suppressScrollX: true, wheelPropagation: false }"
                      ref="myData"
                      class="dropdown-menu-left rtl-ps-none notification-dropdown ps scroll"
                    >
                      <div class="menu-icon-grid">
                        <a @click="SetLocal('en')">
                          <i title="en" class="flag-icon flag-icon-squared flag-icon-gb"></i> English
                        </a>
                        <a @click="SetLocal('fr')">
                          <i title="fr" class="flag-icon flag-icon-squared flag-icon-fr"></i>
                          <span class="title-lang">French</span>
                        </a>
                        <a @click="SetLocal('ar')">
                          <i title="sa" class="flag-icon flag-icon-squared flag-icon-sa"></i>
                          <span class="title-lang">Arabic</span>
                        </a>
                        <a @click="SetLocal('tur')">
                          <i title="sa" class="flag-icon flag-icon-squared flag-icon-tr"></i>
                          <span class="title-lang">Turkish</span>
                        </a>

                        <a @click="SetLocal('sm_ch')">
                          <i title="sa" class="flag-icon flag-icon-squared flag-icon-cn"></i>
                          <span class="title-lang">Simplified Chinese</span>
                        </a>

                        <a @click="SetLocal('thai')">
                          <i title="sa" class="flag-icon flag-icon-squared flag-icon-tw"></i>
                          <span class="title-lang">Thaï</span>
                        </a>

                        <a @click="SetLocal('hn')">
                          <i title="sa" class="flag-icon flag-icon-squared flag-icon-in"></i>
                          <span class="title-lang">Hindi</span>
                        </a>

                        <a @click="SetLocal('de')">
                          <i title="de" class="flag-icon flag-icon-squared flag-icon-de"></i>
                          <span class="title-lang">German</span>
                        </a>
                        <a @click="SetLocal('es')">
                          <i title="es" class="flag-icon flag-icon-squared flag-icon-es"></i>
                          <span class="title-lang">Spanish</span>
                        </a>
                        <a @click="SetLocal('it')">
                          <i title="it" class="flag-icon flag-icon-squared flag-icon-it"></i>
                          <span class="title-lang">Italien</span>
                        </a>
                        <a @click="SetLocal('Ind')">
                          <i title="sa" class="flag-icon flag-icon-squared flag-icon-id"></i>
                          <span class="title-lang">Indonesian</span>
                        </a>

                        <a @click="SetLocal('tr_ch')">
                          <i title="sa" class="flag-icon flag-icon-squared flag-icon-cn"></i>
                          <span class="title-lang">Traditional Chinese</span>
                        </a>

                        <a @click="SetLocal('ru')">
                          <i title="sa" class="flag-icon flag-icon-squared flag-icon-ru"></i>
                          <span class="title-lang">Russian</span>
                        </a>

                        <a @click="SetLocal('vn')">
                          <i title="sa" class="flag-icon flag-icon-squared flag-icon-vn"></i>
                          <span class="title-lang">Vietnamese</span>
                        </a>
                      </div>
                    </vue-perfect-scrollbar>
                  </b-dropdown>
                </div>

                <!-- User avatar dropdown -->
                <div class="dropdown">
                  <b-dropdown
                    id="dropdown-1"
                    text="Dropdown Button"
                    class="m-md-2 user col align-self-end"
                    toggle-class="text-decoration-none"
                    no-caret
                    variant="link"
                    right
                  >
                    <template slot="button-content">
                      <img
                        :src="'/images/avatar/'+currentUser.avatar"
                        id="userDropdown"
                        alt
                        data-toggle="dropdown"
                        aria-haspopup="true"
                        aria-expanded="false"
                      >
                    </template>

                    <div class="dropdown-menu-left" aria-labelledby="userDropdown">
                      <div class="dropdown-header">
                        <i class="i-Lock-User mr-1"></i>
                        <span>{{currentUser.username}}</span>
                      </div>
                      <router-link to="/app/profile" class="dropdown-item">{{$t('profil')}}</router-link>
                      <router-link
                        v-if="currentUserPermissions && currentUserPermissions.includes('setting_system')"
                        to="/app/settings/System_settings"
                        class="dropdown-item"
                      >{{$t('Settings')}}</router-link>
                      <a class="dropdown-item" href="#" @click.prevent="logoutUser">{{$t('logout')}}</a>
                    </div>
                  </b-dropdown>
                </div>
              </div>
            </div>
            <validation-observer ref="create_pos">
              <b-form @submit.prevent="Submit_Pos">
                <b-card-body>
                  <b-row>
                    <!-- Warehouse is the first required step; products and the
                         scanner depend on its stock and price context. -->
                    <b-col lg="12" md="12" sm="12">
                      <validation-provider name="warehouse" :rules="{ required: true}">
                        <b-form-group slot-scope="{ valid, errors }" class="mt-2" label="Selling location" label-for="pos-warehouse">
                          <v-select
                            :class="{'is-invalid': !!errors.length}"
                            :state="errors[0] ? false : (valid ? true : null)"
                            :disabled="details.length > 0"
                            @input="Selected_Warehouse"
                            v-model="sale.warehouse_id"
                            :reduce="label => label.value"
                            input-id="pos-warehouse" aria-label="Selling location"
                            :placeholder="$t('Choose_Warehouse')"
                            :options="warehouses.map(warehouses => ({label: warehouses.name, value: warehouses.id}))"
                          />
                        </b-form-group>
                      </validation-provider>
                    </b-col>

                    <!-- Customer -->
                    <b-col lg="12" md="12" sm="12">
                      <validation-provider name="Customer" :rules="{ required: true}">
                        <b-input-group slot-scope="{ valid, errors }" class="input-customer">
                          <v-select
                            :class="{'is-invalid': !!errors.length}"
                            :state="errors[0] ? false : (valid ? true : null)"
                            v-model="sale.client_id"
                            :reduce="label => label.value"
                            input-id="pos-customer" aria-label="Customer"
                            :placeholder="$t('Choose_Customer')"
                            class="w-100"
                            :options="clients.map(clients => ({label: clients.name, value: clients.id}))"
                          />
                          <b-input-group-append>
                            <b-button variant="primary" @click="New_Client()" aria-label="Add customer">
                              <span>
                                <i class="i-Add-User"></i>
                              </span>
                            </b-button>
                          </b-input-group-append>
                        </b-input-group>
                      </validation-provider>
                    </b-col>

                    <!-- Details Product  -->
                    <b-col md="12" class="mt-2">
                      <div class="pos-detail">
                        <div class="table-responsive">
                          <table class="table table-striped">
                            <thead>
                              <tr>
                                <th scope="col">{{$t('ProductName')}}</th>
                                <th scope="col" class="text-right">{{$t('SubTotal')}}</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr v-if="details.length <= 0">
                                <td colspan="2" class="text-muted text-center py-3">
                                  <span v-if="!sale.warehouse_id">{{$t('ChooseWarehouseToStart')}}</span>
                                  <span v-else>{{$t('ScanOrTapToAdd')}}</span>
                                </td>
                              </tr>
                              <tr v-for="(detail, index) in details" :key="index">
                                <td class="pos-line">
                                  <div class="pos-line-name">{{detail.name}}</div>
                                  <serial-entry
                                    v-if="detail.has_serials"
                                    v-model="detail.serials"
                                    mode="pick"
                                    :product-id="detail.product_id"
                                    :variant-id="detail.product_variant_id"
                                    :warehouse-id="sale.warehouse_id"
                                    :units="detail.quantity * (detail.unit_factor || 1)"
                                    :name="detail.name"
                                  ></serial-entry>
                                  <div class="pos-line-meta">
                                    {{detail.code}} &middot; {{currentUser.currency}} {{formatNumber(detail.Total_price, 2)}}
                                  </div>

                                  <!-- Bigger hit areas: these were 10px wide, which
                                       is unusable with a finger and fiddly with a
                                       mouse, and adjusting quantity is half of what
                                       a cashier does. -->
                                  <div class="pos-qty">
                                    <span class="pos-qty-btn"
                                      @click.stop="decrement(detail, detail.detail_id)">&minus;</span>
                                    <input class="pos-qty-input"
                                      @input="Verified_Qty(detail, detail.detail_id)"
                                      @click.stop
                                      v-model.number="detail.quantity">
                                    <span class="pos-qty-btn"
                                      @click.stop="increment(detail.detail_id)">+</span>

                                    <span class="pos-line-actions">
                                      <i class="i-Edit" :title="$t('Edit')"
                                        @click.stop="Modal_Update_Detail(detail)"></i>
                                      <i class="i-Close-Window text-danger" :title="$t('Delete')"
                                        @click.stop="delete_Product_Detail(detail.detail_id)"></i>
                                    </span>
                                  </div>
                                </td>
                                <td class="pos-line-total">
                                  {{currentUser.currency}} {{detail.subtotal.toFixed(2)}}
                                </td>
                              </tr>
                            </tbody>
                          </table>
                        </div>
                      </div>
                    </b-col>
                  </b-row>

                  <!-- Senior Citizen / PWD. The discount is a statutory
                       entitlement, not a promotion: the VAT comes off first and
                       the 20% applies to what is left, which the server works
                       out. The till only captures who is claiming it.

                       This sits in the cart column, which is narrow, so the
                       fields stack rather than sitting in a row -- a label like
                       "Beneficiaries" has nowhere to go across. -->
                  <div class="footer_panel" style="margin-bottom:8px">
                    <b-row>
                      <b-col cols="12">
                        <b-form-group class="mb-2" :label="$t('StatutoryDiscount')">
                          <b-form-select size="sm" v-model="sale.statutory_type"
                            @change="onStatutoryChange">
                            <option value="none">{{ $t('None') }}</option>
                            <option value="senior">{{ $t('SeniorCitizen') }}</option>
                            <option value="pwd">{{ $t('PWD') }}</option>
                          </b-form-select>
                        </b-form-group>
                      </b-col>

                      <template v-if="sale.statutory_type !== 'none'">
                        <b-col cols="12">
                          <b-form-group class="mb-2">
                            <b-form-input size="sm" v-model="sale.statutory_id_number"
                              :placeholder="$t('IdNumber')"></b-form-input>
                          </b-form-group>
                        </b-col>
                        <b-col cols="12">
                          <b-form-group class="mb-2">
                            <b-form-input size="sm" v-model="sale.statutory_holder_name"
                              :placeholder="$t('CardholderName')"></b-form-input>
                          </b-form-group>
                        </b-col>
                        <!-- A shared bill is apportioned by head: the discount
                             applies to the beneficiaries' share, not the lot. -->
                        <b-col cols="6">
                          <b-form-group class="mb-2">
                            <b-form-input size="sm" type="number" min="1"
                              v-model="sale.statutory_beneficiaries"
                              @input="CaclulTotal()"></b-form-input>
                            <small class="text-muted">{{ $t('Beneficiaries') }}</small>
                          </b-form-group>
                        </b-col>
                        <b-col cols="6">
                          <b-form-group class="mb-2">
                            <b-form-input size="sm" type="number" min="1"
                              v-model="sale.statutory_total_persons"
                              @input="CaclulTotal()"></b-form-input>
                            <small class="text-muted">{{ $t('TotalPersons') }}</small>
                          </b-form-group>
                        </b-col>
                      </template>
                    </b-row>
                  </div>

                  <!-- The VAT breakdown, shown before the sale is taken so the
                       cashier can tell the customer what they owe and why. -->
                  <div class="footer_panel" v-if="vat && (vat.statutory_discount > 0 || vat.vat_exempt_sales > 0 || vat.zero_rated_sales > 0)"
                       style="margin-bottom:8px; font-size:.82rem">
                    <div class="d-flex justify-content-between" v-if="vat.vatable_sales > 0">
                      <span>{{ $t('VatableSales') }}</span>
                      <span>{{ currentUser.currency }} {{ vat.vatable_sales.toFixed(2) }}</span>
                    </div>
                    <div class="d-flex justify-content-between" v-if="vat.vat_amount > 0">
                      <span>{{ currentUser.vat_rate }}% {{ $t('Vat') }}</span>
                      <span>{{ currentUser.currency }} {{ vat.vat_amount.toFixed(2) }}</span>
                    </div>
                    <div class="d-flex justify-content-between" v-if="vat.vat_exempt_sales > 0">
                      <span>{{ $t('VatExemptSales') }}</span>
                      <span>{{ currentUser.currency }} {{ vat.vat_exempt_sales.toFixed(2) }}</span>
                    </div>
                    <div class="d-flex justify-content-between" v-if="vat.zero_rated_sales > 0">
                      <span>{{ $t('ZeroRatedSales') }}</span>
                      <span>{{ currentUser.currency }} {{ vat.zero_rated_sales.toFixed(2) }}</span>
                    </div>
                    <div class="d-flex justify-content-between font-weight-bold text-primary"
                         v-if="vat.statutory_discount > 0">
                      <span>{{ sale.statutory_type === 'pwd' ? $t('PWD') : $t('SeniorCitizen') }} 20%</span>
                      <span>- {{ currentUser.currency }} {{ vat.statutory_discount.toFixed(2) }}</span>
                    </div>
                  </div>

                  <!-- Calcul Grand Total -->
                  <div class="footer_panel">
                    <b-row>
                      <b-col md="12">
                        <div class="grandtotal">
                          <span>{{$t("Total")}} : {{currentUser.currency}} {{GrandTotal.toFixed(2)}}</span>
                        </div>
                      </b-col>

                      <!-- Order Tax  -->
                      <b-col cols="4">
                        <validation-provider
                          name="Order Tax"
                          :rules="{ regex: /^\d*\.?\d*$/}"
                          v-slot="validationContext"
                        >
                          <b-form-group :label="$t('Tax')" append="%">
                            <b-input-group append="%">
                              <b-form-input
                                :state="getValidationState(validationContext)"
                                aria-describedby="OrderTax-feedback"
                                label="Order Tax"
                                v-model.number="sale.tax_rate"
                                @input="keyup_OrderTax()"
                              ></b-form-input>
                            </b-input-group>
                            <b-form-invalid-feedback
                              id="OrderTax-feedback"
                            >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                          </b-form-group>
                        </validation-provider>
                      </b-col>

                      <!-- Discount -->
                      <b-col cols="4">
                        <validation-provider
                          name="Discount"
                          :rules="{ regex: /^\d*\.?\d*$/}"
                          v-slot="validationContext"
                        >
                          <b-form-group :label="$t('Discount')" append="%">
                            <b-input-group :append="currentUser.currency">
                              <b-form-input
                                :state="getValidationState(validationContext)"
                                aria-describedby="Discount-feedback"
                                label="Discount"
                                v-model.number="sale.discount"
                                @input="keyup_Discount()"
                              ></b-form-input>
                            </b-input-group>
                            <b-form-invalid-feedback
                              id="Discount-feedback"
                            >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                          </b-form-group>
                        </validation-provider>
                      </b-col>

                      <!-- Shipping  -->
                      <b-col cols="4">
                        <validation-provider
                          name="Shipping"
                          :rules="{ regex: /^\d*\.?\d*$/}"
                          v-slot="validationContext"
                        >
                          <b-form-group :label="$t('Shipping')">
                            <b-input-group :append="currentUser.currency">
                              <b-form-input
                                :state="getValidationState(validationContext)"
                                aria-describedby="Shipping-feedback"
                                label="Shipping"
                                v-model.number="sale.shipping"
                                @input="keyup_Shipping()"
                              ></b-form-input>
                            </b-input-group>

                            <b-form-invalid-feedback
                              id="Shipping-feedback"
                            >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                          </b-form-group>
                        </validation-provider>
                      </b-col>

                      <!-- Park the basket, or pick one back up. A hold takes no
                           invoice number, but its items are set aside from stock
                           until it is recalled or discarded (PosHoldController). -->
                      <b-col cols="6">
                        <b-button
                          @click="Ask_Hold()"
                          :disabled="!details.length"
                          variant="outline-primary ripple btn-rounded btn-block mt-1"
                        >
                          <i class="i-Inbox-Into"></i>
                          {{ $t("HoldSale") }}
                        </b-button>
                      </b-col>
                      <b-col cols="6">
                        <b-button
                          @click="Open_Holds()"
                          variant="outline-secondary ripple btn-rounded btn-block mt-1"
                        >
                          <i class="i-Inbox-Out"></i>
                          {{ $t("HeldBaskets") }}
                          <b-badge v-if="holds.length" variant="primary">{{ holds.length }}</b-badge>
                        </b-button>
                      </b-col>
                      <b-col cols="6">
                        <b-button
                          @click="Confirm_Reset()"
                          variant="danger ripple btn-rounded btn-block mt-1"
                        >
                          <i class="i-Power-2"></i>
                          {{ $t("Reset") }}
                        </b-button>
                      </b-col>
                      <b-col cols="6">
                        <b-button type="submit" variant="primary ripple mt-1 btn-rounded btn-block" :disabled="!sale.warehouse_id || !sale.client_id || !details.length || (tillRequired && !till && !isOffline)" :title="!sale.warehouse_id ? 'Choose a warehouse first' : !sale.client_id ? 'Choose a customer first' : !details.length ? 'Add a product first' : (tillRequired && !till && !isOffline) ? 'Open the till first' : 'Take payment'">
                          <i class="i-Checkout"></i>
                          {{ $t("payNow") }}
                        </b-button>
                      </b-col>
                      <b-col cols="12" v-if="!sale.warehouse_id || !sale.client_id || !details.length || (tillRequired && !till && !isOffline)" class="small text-muted mt-2" role="status">
                        {{ !sale.warehouse_id ? 'Choose a warehouse to start.' : !sale.client_id ? 'Choose a customer.' : !details.length ? 'Scan or select a product to add to the basket.' : 'Open the till before taking payment.' }}
                      </b-col>
                    </b-row>
                  </div>
                </b-card-body>
              </b-form>
            </validation-observer>

            <!-- Update Detail Product -->
            <validation-observer ref="Update_Detail">
              <b-modal hide-footer size="md" id="form_Update_Detail" :title="detail.name">
                <b-form @submit.prevent="submit_Update_Detail">
                  <b-row>
                    <!-- Unit Price -->
                    <b-col lg="12" md="12" sm="12">
                      <validation-provider
                        name="Product Price"
                        :rules="{ required: true , regex: /^\d*\.?\d*$/}"
                        v-slot="validationContext"
                      >
                        <b-form-group :label="$t('ProductPrice')" id="Price-input">
                          <b-form-input
                            label="Product Price"
                            v-model="detail.Unit_price"
                            :disabled="!canOverridePrice"
                            :state="getValidationState(validationContext)"
                            aria-describedby="Price-feedback"
                          ></b-form-input>
                          <b-form-invalid-feedback
                            id="Price-feedback"
                          >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                        </b-form-group>
                      </validation-provider>
                    </b-col>

                    <!-- Tax Method -->
                    <b-col lg="12" md="12" sm="12">
                      <validation-provider name="Tax Method" :rules="{ required: true}">
                        <b-form-group slot-scope="{ valid, errors }" :label="$t('TaxMethod')">
                          <v-select
                            :class="{'is-invalid': !!errors.length}"
                            :state="errors[0] ? false : (valid ? true : null)"
                            v-model="detail.tax_method"
                            :reduce="label => label.value"
                            :placeholder="$t('Choose_Method')"
                            :options="
                           [
                            {label: 'Exclusive', value: '1'},
                            {label: 'Inclusive', value: '2'}
                           ]"
                          ></v-select>
                          <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                        </b-form-group>
                      </validation-provider>
                    </b-col>

                    <!-- Tax -->
                    <b-col lg="12" md="12" sm="12">
                      <validation-provider
                        name="Tax"
                        :rules="{ required: true , regex: /^\d*\.?\d*$/}"
                        v-slot="validationContext"
                      >
                        <b-form-group :label="$t('Tax')">
                          <b-input-group append="%">
                            <b-form-input
                              label="Tax"
                              v-model="detail.tax_percent"
                              :state="getValidationState(validationContext)"
                              aria-describedby="Tax-feedback"
                            ></b-form-input>
                          </b-input-group>
                          <b-form-invalid-feedback
                            id="Tax-feedback"
                          >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                        </b-form-group>
                      </validation-provider>
                    </b-col>

                    <!-- Discount Method -->
                    <b-col lg="12" md="12" sm="12">
                      <validation-provider name="Discount Method" :rules="{ required: true}">
                        <b-form-group slot-scope="{ valid, errors }" :label="$t('Discount_Method')">
                          <v-select
                            v-model="detail.discount_Method"
                            :reduce="label => label.value"
                            :placeholder="$t('Choose_Method')"
                            :class="{'is-invalid': !!errors.length}"
                            :state="errors[0] ? false : (valid ? true : null)"
                            :options="
                              [
                                {label: 'Percent %', value: '1'},
                                {label: 'Fixed', value: '2'}
                              ]"
                          ></v-select>
                          <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                        </b-form-group>
                      </validation-provider>
                    </b-col>

                    <!-- Discount Rate -->
                    <b-col lg="12" md="12" sm="12">
                      <validation-provider
                        name="Discount Rate"
                        :rules="{ required: true , regex: /^\d*\.?\d*$/}"
                        v-slot="validationContext"
                      >
                        <b-form-group :label="$t('Discount')">
                          <b-form-input
                            label="Discount"
                            v-model="detail.discount"
                            :disabled="!canOverridePrice"
                            :state="getValidationState(validationContext)"
                            aria-describedby="Discount-feedback"
                          ></b-form-input>
                          <b-form-invalid-feedback
                            id="Discount-feedback"
                          >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                        </b-form-group>
                      </validation-provider>
                    </b-col>

                    <!-- Unit Sale -->
                    <b-col lg="12" md="12" sm="12">
                      <validation-provider name="Unit Sale" :rules="{ required: true}">
                        <b-form-group slot-scope="{ valid, errors }" :label="$t('UnitSale')">
                          <v-select
                            :class="{'is-invalid': !!errors.length}"
                            :state="errors[0] ? false : (valid ? true : null)"
                            @input="Reprice_For_Unit"
                            v-model="detail.sale_unit_id"
                            :placeholder="$t('Choose_Unit_Sale')"
                            :reduce="label => label.value"
                            :options="units.map(units => ({label: units.name, value: units.id}))"
                          />
                          <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                        </b-form-group>
                      </validation-provider>
                    </b-col>

                    <b-col md="12">
                      <b-form-group>
                        <b-button variant="primary" type="submit">{{$t('submit')}}</b-button>
                      </b-form-group>
                    </b-col>
                  </b-row>
                </b-form>
              </b-modal>
            </validation-observer>
          </b-card>
        </b-col>

        <!-- Card right Of Products -->
        <b-col lg="7">
          <b-card class="list-grid">
            <div v-if="!sale.warehouse_id" class="pos-warehouse-empty" role="status">
              <i class="i-Shop" aria-hidden="true"></i>
              <h2>Choose a selling location</h2>
              <p>Select the warehouse or branch on the left to load available products and start scanning.</p>
            </div>
            <template v-else>
            <b-row>
              <b-col md="6">
                <button v-b-toggle.sidebar-category class="btn btn-outline-info mt-1 btn-block">
                  <i class="i-Two-Windows"></i>
                  {{$t('ListofCategory')}}
                </button>
              </b-col>
              <b-col md="6">
                <button v-b-toggle.sidebar-brand class="btn btn-outline-info mt-1 btn-block">
                  <i class="i-Library"></i>
                  {{$t('ListofBrand')}}
                </button>
              </b-col>


               <!-- Product -->
                <b-col md="12" class="mt-2 mb-2">
                  <h6>{{$t('ProductName')}}</h6>
                 
                  <div id="autocomplete" class="autocomplete">
                    <input
                     id="pos-product-search" ref="posSearch" aria-label="Scan or search product by code or name"
                     :placeholder="$t('Scan_Search_Product_by_Code_Name')"
                      @keydown="onScanKey"
                      @keyup="search()"
                      @focus="handleFocus"
                      @blur="handleBlur"
                      v-model="search_input"
                      class="autocomplete-input" />
                    <ul class="autocomplete-result-list" v-show="focused">
                      <li class="autocomplete-result" v-for="product_fil in product_filter" @mousedown="SearchProduct(product_fil)">{{getResultValue(product_fil)}}</li>
                    </ul>
                </div>
                </b-col>

              <div class="col-md-12 d-flex flex-row flex-wrap bd-highlight list-item mt-2">
                <div
                  @click="Check_Product_Exist(product , product.id)"
                  @keydown.enter="Check_Product_Exist(product, product.id)"
                  @keydown.space.prevent="Check_Product_Exist(product, product.id)"
                  v-for="product in products"
                  :key="product.id" role="button" tabindex="0"
                  :aria-label="`Add ${product.name} to basket`"
                  class="card o-hidden bd-highlight m-1"
                >
                  <div class="list-thumb d-flex" v-if="hasImage(product)">
                    <img alt :src="'/images/products/'+product.image"
                      @error="imageFailed(product)">
                  </div>
                  <div class="flex-grow-1 d-bock">
                    <div class="card-body pos-card-body">
                      <div class="pos-card-name" :title="product.name">{{product.name}}</div>
                      <div class="pos-card-code">{{product.code}}</div>
                      <div class="pos-card-foot">
                        <span class="badge badge-primary">{{currentUser.currency}} {{formatNumber(product.Net_price , 2)}}</span>
                        <span v-if="product.is_service" class="badge badge-secondary">{{$t('Service')}}</span>
                        <span v-else class="badge badge-info">{{formatNumber(product.qte_sale , 2)}} {{product.unitSale}}</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </b-row>
            <b-row>
              <b-col md="12" class="mt-4">
                <b-pagination
                  @change="Product_onPageChanged"
                  :total-rows="product_totalRows"
                  :per-page="product_perPage"
                  v-model="product_currentPage"
                  class="my-0 gull-pagination align-items-center"
                  align="center"
                  first-text
                  last-text
                >
                  <p class="list-arrow m-0" slot="prev-text">
                    <i class="i-Arrow-Left text-40"></i>
                  </p>
                  <p class="list-arrow m-0" slot="next-text">
                    <i class="i-Arrow-Right text-40"></i>
                  </p>
                </b-pagination>
              </b-col>
            </b-row>
            </template>
          </b-card>
        </b-col>

        <!-- Sidebar Brand -->
        <b-sidebar id="sidebar-brand" :title="$t('ListofBrand')" bg-variant="white" right shadow>
          <div class="px-3 py-2">
            <b-row>
              <div class="col-md-12 d-flex flex-row flex-wrap bd-highlight list-item mt-2">
                <div
                  @click="GetAllBrands()"
                  :class="{ 'brand-Active' : brand_id == ''}"
                  class="card o-hidden bd-highlight m-1"
                >
                  <div class="list-thumb d-flex">
                    <img alt :src="'/images/no-image.png'">
                  </div>
                  <div class="flex-grow-1 d-bock">
                    <div
                      class="card-body align-self-center d-flex flex-column justify-content-between align-items-lg-center"
                    >
                      <div class="item-title">{{$t('All_Brand')}}</div>
                    </div>
                  </div>
                </div>
                <div
                  class="card o-hidden bd-highlight m-1"
                  v-for="brand in paginated_Brands"
                  :key="brand.id"
                  @click="Products_by_Brands(brand.id)"
                  :class="{ 'brand-Active' : brand.id === brand_id}"
                >
                  <img alt :src="'/images/brands/'+brand.image">
                  <div class="flex-grow-1 d-bock">
                    <div
                      class="card-body align-self-center d-flex flex-column justify-content-between align-items-lg-center"
                    >
                      <div class="item-title">{{brand.name}}</div>
                    </div>
                  </div>
                </div>
              </div>
            </b-row>

            <b-row>
              <b-col md="12" class="mt-4">
                <b-pagination
                  @change="BrandonPageChanged"
                  :total-rows="brand_totalRows"
                  :per-page="brand_perPage"
                  v-model="brand_currentPage"
                  class="my-0 gull-pagination align-items-center"
                  align="center"
                  first-text
                  last-text
                >
                  <p class="list-arrow m-0" slot="prev-text">
                    <i class="i-Arrow-Left text-40"></i>
                  </p>
                  <p class="list-arrow m-0" slot="next-text">
                    <i class="i-Arrow-Right text-40"></i>
                  </p>
                </b-pagination>
              </b-col>
            </b-row>
          </div>
        </b-sidebar>

        <!-- Sidebar category -->
        <b-sidebar
          id="sidebar-category"
          :title="$t('ListofCategory')"
          bg-variant="white"
          right
          shadow
        >
          <div class="px-3 py-2">
            <b-row>
              <div class="col-md-12 d-flex flex-row flex-wrap bd-highlight list-item mt-2">
                <div
                  @click="getAllCategory()"
                  :class="{ 'brand-Active' : category_id == ''}"
                  class="card o-hidden bd-highlight m-1"
                >
                  <div class="list-thumb d-flex">
                    <img alt :src="'/images/no-image.png'">
                  </div>
                  <div class="flex-grow-1 d-bock">
                    <div
                      class="card-body align-self-center d-flex flex-column justify-content-between align-items-lg-center"
                    >
                      <div class="item-title">{{$t('All_Category')}}</div>
                    </div>
                  </div>
                </div>
                <div
                  class="card o-hidden bd-highlight m-1"
                  v-for="category in paginated_Category"
                  :key="category.id"
                  @click="Products_by_Category(category.id)"
                  :class="{ 'brand-Active' : category.id === category_id}"
                >
                  <img alt :src="'/images/no-image.png'">
                  <div class="flex-grow-1 d-bock">
                    <div
                      class="card-body align-self-center d-flex flex-column justify-content-between align-items-lg-center"
                    >
                      <div class="item-title">{{category.name}}</div>
                    </div>
                  </div>
                </div>
              </div>
            </b-row>

            <b-row>
              <b-col md="12" class="mt-4">
                <b-pagination
                  @change="Category_onPageChanged"
                  :total-rows="category_totalRows"
                  :per-page="category_perPage"
                  v-model="category_currentPage"
                  class="my-0 gull-pagination align-items-center"
                  align="center"
                  first-text
                  last-text
                >
                  <p class="list-arrow m-0" slot="prev-text">
                    <i class="i-Arrow-Left text-40"></i>
                  </p>
                  <p class="list-arrow m-0" slot="next-text">
                    <i class="i-Arrow-Right text-40"></i>
                  </p>
                </b-pagination>
              </b-col>
            </b-row>
          </div>
        </b-sidebar>

        <!-- Modal Show Invoice POS-->
        <b-modal hide-footer size="sm" scrollable id="Show_invoice" :title="$t('Invoice_POS')">
          <div id="invoice-POS">
            <div style="max-width:400px;margin:0px auto">
              <div class="info">
                <h2 class="text-center">{{invoice_pos.setting.CompanyName}}</h2>
                <p>
                  <span>{{$t('date')}} : {{invoice_pos.sale.date}} <br></span>
                  <span v-show="pos_settings.show_address">{{$t('Adress')}} : {{invoice_pos.setting.CompanyAdress}} <br></span>
                  <span v-show="pos_settings.show_email">{{$t('Email')}} : {{invoice_pos.setting.email}} <br></span>
                  <span v-show="pos_settings.show_phone">{{$t('Phone')}} : {{invoice_pos.setting.CompanyPhone}} <br></span>
                  <span v-show="pos_settings.show_customer">{{$t('Customer')}} : {{invoice_pos.sale.client_name}} <br></span>
                </p>
              </div>

              <table class="table_data">
                <tbody>
                  <tr v-for="detail_invoice in invoice_pos.details">
                    <td colspan="3">
                      <span>
                        {{detail_invoice.name}}
                        <br>
                        {{formatNumber(detail_invoice.quantity,2)}} {{detail_invoice.unit_sale}} x {{formatNumber(detail_invoice.total/detail_invoice.quantity,2)}}
                      </span>
                    </td>
                    <td
                      style="text-align:right;vertical-align:bottom"
                    >{{formatNumber(detail_invoice.total,2)}}</td>
                  </tr>

                  <tr style="margin-top:10px">
                    <td colspan="3" class="total">{{$t('OrderTax')}}</td>
                    <td style="text-align:right;" class="total">{{invoice_pos.symbol}} {{formatNumber(invoice_pos.sale.taxe ,2)}} ({{formatNumber(invoice_pos.sale.tax_rate,2)}} %)</td>
                  </tr>

                  <tr style="margin-top:10px">
                    <td colspan="3" class="total">{{$t('Discount')}}</td>
                    <td style="text-align:right;" class="total">{{invoice_pos.symbol}} {{formatNumber(invoice_pos.sale.discount ,2)}}</td>
                  </tr>

                  <!-- The statutory discount, kept apart from any trade discount
                       because it is a different line on the invoice and a
                       different figure in the books. -->
                  <tr style="margin-top:10px" v-if="invoice_pos.sale.statutory_label">
                    <td colspan="3" class="total">
                      {{invoice_pos.sale.statutory_label}} (20%)
                    </td>
                    <td style="text-align:right;" class="total">
                      - {{invoice_pos.symbol}} {{formatNumber(invoice_pos.sale.statutory_discount ,2)}}
                    </td>
                  </tr>

                  <tr style="margin-top:10px">
                    <td colspan="3" class="total">{{$t('Total')}}</td>
                    <td
                      style="text-align:right;"
                      class="total"
                    >{{invoice_pos.symbol}} {{formatNumber(invoice_pos.sale.GrandTotal ,2)}}</td>
                  </tr>

                  <!-- For a walk-in customer this slip IS the invoice, so it has
                       to carry the same four figures the A4 one does. A 0% rate
                       and a VAT-exempt sale are the same number and different
                       filings, so they are reported on separate lines. -->
                  <tr><td colspan="4"><hr style="margin:6px 0 4px"></td></tr>
                  <tr>
                    <td colspan="3">{{$t('VatableSales')}}</td>
                    <td style="text-align:right;">{{invoice_pos.symbol}} {{formatNumber(invoice_pos.sale.vatable_sales ,2)}}</td>
                  </tr>
                  <tr>
                    <td colspan="3">{{invoice_pos.sale.vat_rate}}% {{$t('Vat')}}</td>
                    <td style="text-align:right;">{{invoice_pos.symbol}} {{formatNumber(invoice_pos.sale.vat_amount ,2)}}</td>
                  </tr>
                  <tr>
                    <td colspan="3">{{$t('VatExemptSales')}}</td>
                    <td style="text-align:right;">{{invoice_pos.symbol}} {{formatNumber(invoice_pos.sale.vat_exempt_sales ,2)}}</td>
                  </tr>
                  <tr>
                    <td colspan="3">{{$t('ZeroRatedSales')}}</td>
                    <td style="text-align:right;">{{invoice_pos.symbol}} {{formatNumber(invoice_pos.sale.zero_rated_sales ,2)}}</td>
                  </tr>

                  <tr v-show="invoice_pos.sale.paid_amount < invoice_pos.sale.GrandTotal">
                    <td colspan="3" class="total">{{$t('Paid')}}</td>
                    <td
                      style="text-align:right;"
                      class="total"
                    >{{invoice_pos.symbol}} {{formatNumber(invoice_pos.sale.paid_amount ,2)}}</td>
                  </tr>

                  <tr v-show="invoice_pos.sale.paid_amount < invoice_pos.sale.GrandTotal">
                    <td colspan="3" class="total">{{$t('Due')}}</td>
                    <td
                      style="text-align:right;"
                      class="total"
                    >{{invoice_pos.symbol}} {{parseFloat(invoice_pos.sale.GrandTotal - invoice_pos.sale.paid_amount).toFixed(2)}}</td>
                  </tr>
                </tbody>
              </table>

              <table
                class="change mt-3"
                style=" font-size: 10px;"
                v-show="invoice_pos.sale.paid_amount > 0"
              >
                <thead>
                  <tr style="background: #eee; ">
                    <th style="text-align: left;" colspan="1">{{$t('PayeBy')}}:</th>
                    <th style="text-align: center;" colspan="2">{{$t('Amount')}}:</th>
                    <th style="text-align: right;" colspan="1">{{$t('Change')}}:</th>
                  </tr>
                </thead>

                <tbody>
                  <tr v-for="payment_pos in payments">
                    <td style="text-align: left;" colspan="1">{{payment_pos.Reglement}}</td>
                    <td
                      style="text-align: center;"
                      colspan="2"
                    >{{formatNumber(payment_pos.montant ,2)}}</td>
                    <td
                      style="text-align: right;"
                      colspan="1"
                    >{{formatNumber(payment_pos.change ,2)}}</td>
                  </tr>
                </tbody>
              </table>

              <!-- The law asks for the beneficiary's name, ID number and
                   signature on the face of the invoice, not merely in the day's
                   records, so the slip has to leave room to sign. -->
              <div v-if="invoice_pos.sale.statutory_label" class="ml-2"
                   style="margin-top:10px; border-top:1px dashed #999; padding-top:8px; font-size:.9em">
                <div>{{invoice_pos.sale.statutory_label}} : {{invoice_pos.sale.statutory_holder_name}}</div>
                <div>{{$t('IdNumber')}} : {{invoice_pos.sale.statutory_id_number}}</div>
                <div v-if="invoice_pos.sale.statutory_total_persons > 1">
                  {{$t('Beneficiaries')}} : {{invoice_pos.sale.statutory_beneficiaries}} / {{invoice_pos.sale.statutory_total_persons}}
                </div>
                <div style="margin-top:18px">{{$t('Signature')}} : _____________________</div>
              </div>

              <div id="legalcopy" class="ml-2">
                <p class="legal" v-show="pos_settings.show_note">
                  <strong>{{pos_settings.note_customer}}</strong>
                </p>
                <div id="bar" v-show="pos_settings.show_barcode">
                  <barcode
                    class="barcode"
                    :format="barcodeFormat"
                    :value="invoice_pos.sale.Ref"
                    textmargin="0"
                    fontoptions="bold"
                    fontSize="15"
                    height="25"
                    width="1"
                  ></barcode>
                </div>
              </div>
            </div>
          </div>
          <button @click="print_pos()" class="btn btn-outline-primary">
            <i class="i-Printer"></i>
            {{$t('print')}}
          </button>
        </b-modal>

        <!-- Modal Add Payment-->
        <validation-observer ref="Add_payment">
          <b-modal hide-footer size="lg" id="Add_Payment" :title="$t('AddPayment')">
            <b-form @submit.prevent="Submit_Payment">
              <b-row>
                <b-col md="6">
                  <b-row>
                    <!-- Received  Amount  -->
                    <b-col lg="12" md="12" sm="12">
                      <validation-provider
                        name="Received Amount"
                        :rules="{ required: true , regex: /^\d*\.?\d*$/}"
                        v-slot="validationContext"
                      >
                        <b-form-group :label="$t('Received_Amount')">
                          <b-form-input
                            @input="Verified_Received_Amount(payment.received_amount)"
                            label="Received_Amount"
                            :placeholder="$t('Received_Amount')"
                            v-model.number="payment.received_amount"
                            :state="getValidationState(validationContext)"
                            aria-describedby="Received_Amount-feedback"
                          ></b-form-input>
                          <b-form-invalid-feedback
                            id="Received_Amount-feedback"
                          >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                        </b-form-group>
                      </validation-provider>
                    </b-col>

                    <!-- Paying  Amount  -->
                    <b-col lg="12" md="12" sm="12">
                      <validation-provider
                        name="Paying Amount"
                        :rules="{ required: true , regex: /^\d*\.?\d*$/}"
                        v-slot="validationContext"
                      >
                        <b-form-group :label="$t('Paying_Amount')">
                          <b-form-input
                            label="Paying_Amount"
                            @input="Verified_paidAmount(payment.amount)"
                            :placeholder="$t('Paying_Amount')"
                            v-model.number="payment.amount"
                            :state="getValidationState(validationContext)"
                            aria-describedby="Paying_Amount-feedback"
                          ></b-form-input>
                          <b-form-invalid-feedback
                            id="Paying_Amount-feedback"
                          >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                        </b-form-group>
                      </validation-provider>
                    </b-col>

                    <!-- change  Amount  -->
                    <b-col lg="12" md="12" sm="12">
                      <label>{{$t('Change')}} :</label>
                      <p
                        class="change_amount"
                      >{{parseFloat(payment.received_amount - payment.amount).toFixed(2)}}</p>
                    </b-col>

                    <!-- Payment choice. Hidden while splitting: the methods are
                         then the parts, and a single answer here would only be a
                         second, contradictory one. -->
                    <b-col lg="12" md="12" sm="12" v-if="!splitPayment">
                      <validation-provider name="Payment choice" :rules="{ required: true}">
                        <b-form-group slot-scope="{ valid, errors }" :label="$t('Paymentchoice')">
                          <v-select
                            :class="{'is-invalid': !!errors.length}"
                            :state="errors[0] ? false : (valid ? true : null)"
                            v-model="payment.Reglement"
                            @input="Selected_PaymentMethod"
                            :reduce="label => label.value"
                            :placeholder="$t('PleaseSelect')"
                            :options="singlePaymentMethods"
                          ></v-select>
                          <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                        </b-form-group>
                      </validation-provider>
                    </b-col>

                    <!-- Paying with a wallet. The customer scans, the till waits,
                         and the sale is only rung up once the money has landed. -->
                    <b-col lg="12" md="12" sm="12" v-if="isWalletMethod(payment.Reglement)">
                      <div class="wallet-panel">
                        <div v-if="!walletPayment">
                          <p class="mb-2">{{ $t('WalletAskCustomer') }}</p>
                          <b-button
                            variant="primary"
                            :disabled="walletBusy || GrandTotal <= 0"
                            @click="Start_Wallet"
                          >
                            {{ $t('WalletCharge') }}
                            {{ currentUser.currency }} {{ money(GrandTotal) }}
                          </b-button>
                        </div>

                        <div v-else class="wallet-live">
                          <div class="wallet-qr" v-if="walletPayment.qr">
                            <img :src="walletPayment.qr" :alt="$t('WalletScanToPay')">
                          </div>
                          <div class="wallet-qr-missing" v-else-if="walletPayment.action_value">
                            <p class="mb-1"><strong>{{ $t('WalletOpenLink') }}</strong></p>
                            <a
                              :href="walletPayment.action_value"
                              target="_blank"
                              rel="noopener"
                            >{{ walletPayment.action_value }}</a>
                          </div>

                          <div class="wallet-state">
                            <p class="mb-1">
                              <strong>{{ walletPayment.label }}</strong> &mdash;
                              {{ currentUser.currency }} {{ money(walletPayment.amount) }}
                            </p>
                            <p class="mb-1 wallet-pending" v-if="walletPayment.status === 'pending'">
                              <span class="wallet-dot"></span>{{ $t('WalletWaiting') }}
                            </p>
                            <p class="mb-1 wallet-paid" v-else-if="walletPayment.status === 'paid'">
                              {{ $t('WalletPaid') }}
                            </p>
                            <p class="mb-1 wallet-failed" v-else>
                              {{ walletStatusText(walletPayment) }}
                            </p>
                            <small class="text-muted d-block">{{ walletPayment.reference_id }}</small>
                            <b-button
                              size="sm"
                              variant="outline-secondary"
                              class="mt-2"
                              :disabled="walletBusy"
                              @click="Cancel_Wallet"
                            >{{ $t('WalletStartOver') }}</b-button>
                          </div>
                        </div>
                      </div>
                    </b-col>

                    <!-- Split tender. Off by default: most sales are paid one
                         way, and the extra rows would only be in the way. -->
                    <b-col lg="12" md="12" sm="12" class="mt-2">
                      <label class="switch switch-primary">
                        {{$t('SplitPayment')}}
                        <input type="checkbox" v-model="splitPayment" @change="Init_Split">
                        <span class="slider"></span>
                      </label>
                    </b-col>

                    <b-col lg="12" md="12" sm="12" v-if="splitPayment">
                      <div v-for="(part, i) in paymentParts" :key="'part' + i" class="split-row">
                        <v-select
                          class="split-method"
                          v-model="part.Reglement"
                          :reduce="label => label.value"
                          :clearable="false"
                          :options="paymentMethods"
                        ></v-select>
                        <b-form-input
                          class="split-amount"
                          type="number"
                          step="any"
                          min="0"
                          v-model.number="part.amount"
                        ></b-form-input>
                        <a class="split-drop cursor-pointer" @click="Remove_Part(i)"
                           :title="$t('Delete')"><i class="i-Close-Window"></i></a>
                      </div>

                      <div class="d-flex align-items-center mt-1">
                        <b-button size="sm" variant="outline-primary" @click="Add_Part">
                          {{$t('AddPaymentPart')}}
                        </b-button>
                        <span class="ml-auto" :class="splitStateClass">
                          <template v-if="splitRemaining < 0">
                            {{$t('OverTendered')}}:
                            {{ currentUser.currency }} {{ money(-splitRemaining) }}
                          </template>
                          <template v-else>
                            {{$t('StillToPay')}}:
                            {{ currentUser.currency }} {{ money(splitRemaining) }}
                          </template>
                        </span>
                      </div>
                    </b-col>

                    <!-- Note -->
                    <b-col lg="12" md="12" sm="12" class="mt-2">
                      <b-form-group :label="$t('Note')">
                        <b-form-textarea
                          id="textarea"
                          v-model="payment.notes"
                          rows="3"
                          max-rows="6"
                        ></b-form-textarea>
                      </b-form-group>
                    </b-col>
                  </b-row>
                </b-col>

                <b-col md="6">
                  <b-card>
                    <b-list-group>
                      <b-list-group-item class="d-flex justify-content-between align-items-center">
                        {{$t('TotalProducts')}}
                        <b-badge variant="primary" pill>{{details.length}}</b-badge>
                      </b-list-group-item>

                      <b-list-group-item class="d-flex justify-content-between align-items-center">
                        {{$t('OrderTax')}}
                        <span
                          class="font-weight-bold"
                        >{{currentUser.currency}} {{sale.TaxNet.toFixed(2)}} ({{sale.tax_rate}} %)</span>
                      </b-list-group-item>
                      <b-list-group-item class="d-flex justify-content-between align-items-center">
                        {{$t('Discount')}}
                        <span
                          class="font-weight-bold"
                        >{{currentUser.currency}} {{sale.discount.toFixed(2)}}</span>
                      </b-list-group-item>

                      <b-list-group-item class="d-flex justify-content-between align-items-center">
                        {{$t('Shipping')}}
                        <span
                          class="font-weight-bold"
                        >{{currentUser.currency}} {{sale.shipping.toFixed(2)}}</span>
                      </b-list-group-item>

                      <b-list-group-item class="d-flex justify-content-between align-items-center">
                        {{$t('Total')}}
                        <span
                          class="font-weight-bold"
                        >{{currentUser.currency}} {{GrandTotal.toFixed(2)}}</span>
                      </b-list-group-item>
                    </b-list-group>
                  </b-card>
                </b-col>

                <b-col md="12" class="mt-3">
                  <b-button
                    variant="primary"
                    type="submit"
                    :disabled="paymentProcessing || splitRemaining < 0 || walletBlocking"
                  >{{$t('submit')}}</b-button>
                  <div v-once class="typo__p" v-if="paymentProcessing">
                    <div class="spinner sm spinner-primary mt-3"></div>
                  </div>
                </b-col>
              </b-row>
            </b-form>
          </b-modal>
        </validation-observer>

        <validation-observer ref="Create_Customer">
          <b-modal hide-footer size="lg" id="New_Customer" :title="$t('Add')">
            <b-form @submit.prevent="Submit_Customer">
              <b-row>
                <!-- Customer Name -->
                <b-col md="6" sm="12">
                  <validation-provider
                    name="Name Customer"
                    :rules="{ required: true}"
                    v-slot="validationContext"
                  >
                    <b-form-group :label="$t('CustomerName')">
                      <b-form-input
                        :state="getValidationState(validationContext)"
                        aria-describedby="name-feedback"
                        label="name"
                        v-model="client.name"
                      ></b-form-input>
                      <b-form-invalid-feedback id="name-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <!-- Customer Email -->
                <b-col md="6" sm="12">
                  <validation-provider
                    name="Email customer"
                    :rules="{ required: true}"
                    v-slot="validationContext"
                  >
                    <b-form-group :label="$t('Email')">
                      <b-form-input
                        :state="getValidationState(validationContext)"
                        aria-describedby="Email-feedback"
                        label="Email"
                        v-model="client.email"
                      ></b-form-input>
                      <b-form-invalid-feedback id="Email-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <!-- Customer Phone -->
                <b-col md="6" sm="12">
                  <validation-provider
                    name="Phone Customer"
                    :rules="{ required: true}"
                    v-slot="validationContext"
                  >
                    <b-form-group :label="$t('Phone')">
                      <b-form-input
                        :state="getValidationState(validationContext)"
                        aria-describedby="Phone-feedback"
                        label="Phone"
                        v-model="client.phone"
                      ></b-form-input>
                      <b-form-invalid-feedback id="Phone-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <!-- Customer Country -->
                <b-col md="6" sm="12">
                  <validation-provider
                    name="Country customer"
                    :rules="{ required: true}"
                    v-slot="validationContext"
                  >
                    <b-form-group :label="$t('Country')">
                      <b-form-input
                        :state="getValidationState(validationContext)"
                        aria-describedby="Country-feedback"
                        label="Country"
                        v-model="client.country"
                      ></b-form-input>
                      <b-form-invalid-feedback
                        id="Country-feedback"
                      >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <!-- Customer City -->
                <b-col md="6" sm="12">
                  <validation-provider
                    name="City Customer"
                    :rules="{ required: true}"
                    v-slot="validationContext"
                  >
                    <b-form-group :label="$t('City')">
                      <b-form-input
                        :state="getValidationState(validationContext)"
                        aria-describedby="City-feedback"
                        label="City"
                        v-model="client.city"
                      ></b-form-input>
                      <b-form-invalid-feedback id="City-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <!-- Customer Adress -->
                <b-col md="6" sm="12">
                  <validation-provider
                    name="Adress customer"
                    :rules="{ required: true}"
                    v-slot="validationContext"
                  >
                    <b-form-group :label="$t('Adress')">
                      <b-form-input
                        :state="getValidationState(validationContext)"
                        aria-describedby="Adress-feedback"
                        label="Adress"
                        v-model="client.adresse"
                      ></b-form-input>
                      <b-form-invalid-feedback
                        id="Adress-feedback"
                      >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col md="12" class="mt-3">
                  <b-button variant="primary" type="submit">{{$t('submit')}}</b-button>
                </b-col>
              </b-row>
            </b-form>
          </b-modal>
        </validation-observer>

        <!-- Parking a basket. It is given a name because a cashier picking it
             back up needs to recognise it, not decode it. -->
        <b-modal id="pos_hold" :title="$t('HoldSale')" hide-footer>
          <b-form-group :label="$t('HoldLabel')">
            <b-form-input v-model="holdForm.label" :placeholder="$t('HoldLabelHint')"/>
          </b-form-group>
          <b-form-group :label="$t('Note')">
            <b-form-input v-model="holdForm.notes"/>
          </b-form-group>
          <p class="text-muted">{{ details.length }} {{ $t('lines') }} &mdash;
             {{ currentUser.currency }} {{ money(GrandTotal) }}</p>
          <b-button variant="primary" :disabled="holdBusy || !holdForm.label" @click="Hold_Sale">
            {{ $t('HoldSale') }}
          </b-button>
        </b-modal>

        <!-- The baskets waiting to be picked back up. -->
        <b-modal id="pos_holds" size="lg" :title="$t('HeldBaskets')" hide-footer>
          <p v-if="!holds.length" class="text-muted mb-0">{{ $t('NoHeldBaskets') }}</p>
          <div v-else class="table-responsive">
            <table class="table table-hover">
              <thead class="bg-gray-300">
                <tr>
                  <th>{{ $t('HoldLabel') }}</th>
                  <th>{{ $t('Customer') }}</th>
                  <th class="text-right">{{ $t('lines') }}</th>
                  <th class="text-right">{{ $t('Total') }}</th>
                  <th>{{ $t('Cashier') }}</th>
                  <th class="text-center">{{ $t('Action') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="h in holds" :key="h.id">
                  <td>
                    {{ h.label }}
                    <small class="text-muted d-block" v-if="h.notes">{{ h.notes }}</small>
                    <b-badge v-if="h.stock_held" variant="warning" class="mt-1">{{ $t('StockSetAside') }}</b-badge>
                    <b-badge v-else-if="h.stock_released_at" variant="light" class="mt-1">{{ $t('StockReturnedAfterHours') }}</b-badge>
                  </td>
                  <td>{{ h.client || '—' }}</td>
                  <td class="text-right">{{ h.item_count }}</td>
                  <td class="text-right">{{ currentUser.currency }} {{ money(h.total) }}</td>
                  <td>{{ h.cashier }}</td>
                  <td class="text-center text-nowrap">
                    <a class="cursor-pointer mr-2" :title="$t('Recall')" @click="Recall_Hold(h)">
                      <i class="i-Download text-25 text-success"></i>
                    </a>
                    <a class="cursor-pointer" :title="$t('Delete')" @click="Discard_Hold(h)">
                      <i class="i-Close-Window text-25 text-danger"></i>
                    </a>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </b-modal>

        <!--------------------------------- the drawer --------------------------------->

        <!-- Opening: a float, and which drawer this is. -->
        <b-modal id="till_open" :title="$t('OpenTill')" hide-footer>
          <b-form-group :label="$t('Terminal')">
            <b-form-input v-model="tillForm.terminal" :placeholder="'MAIN'"/>
            <small class="text-muted">{{ $t('TerminalHint') }}</small>
          </b-form-group>
          <b-form-group :label="$t('OpeningFloat')">
            <b-form-input type="number" step="any" min="0" v-model.number="tillForm.opening_float"/>
          </b-form-group>
          <b-form-group :label="$t('Notes')">
            <b-form-textarea rows="2" v-model="tillForm.notes"/>
          </b-form-group>
          <b-button variant="primary" :disabled="tillBusy" @click="Open_Till">
            {{ $t('OpenTill') }}
          </b-button>
        </b-modal>

        <!-- Cash that is not a sale. -->
        <b-modal id="till_cash" :title="cashForm.type === 'cash_in' ? $t('CashIn') : $t('CashOut')" hide-footer>
          <b-form-group :label="$t('Amount')">
            <b-form-input type="number" step="any" min="0" v-model.number="cashForm.amount"/>
          </b-form-group>
          <b-form-group :label="$t('Reason')">
            <b-form-input v-model="cashForm.reason" :placeholder="$t('ReasonHint')"/>
          </b-form-group>
          <b-button variant="primary" :disabled="tillBusy" @click="Till_Cash">
            {{ $t('Submit') }}
          </b-button>
        </b-modal>

        <!-- Counting it down. The expected figure is shown, but the count is
             typed first so the drawer is counted rather than confirmed. -->
        <b-modal id="till_close" :title="$t('CloseTill')" hide-footer>
          <b-form-group :label="$t('CountedCash')">
            <b-form-input type="number" step="any" min="0" v-model.number="closeForm.counted_cash"/>
          </b-form-group>
          <p v-if="closeForm.counted_cash !== null && closeForm.counted_cash !== ''" class="mb-2">
            {{ $t('ExpectedCash') }}: <strong>{{ currentUser.currency }} {{ tillExpected }}</strong>
            &nbsp;&mdash;&nbsp;
            <span :class="closeVariance < 0 ? 'till-short' : (closeVariance > 0 ? 'till-over' : '')">
              {{ $t('Variance') }}: {{ currentUser.currency }} {{ money(closeVariance) }}
            </span>
          </p>
          <b-form-group :label="$t('Notes')">
            <b-form-textarea rows="2" v-model="closeForm.notes"/>
          </b-form-group>
          <b-button variant="danger" :disabled="tillBusy" @click="Close_Till">
            {{ $t('CloseTill') }}
          </b-button>
        </b-modal>

        <!-- The panel: the shift as it stands, and what can be done to it. -->
        <b-modal id="till_panel" size="lg" :title="$t('TillSession')" hide-footer>
          <div v-if="!till">
            <p class="text-muted" v-if="!warehouse_id">{{ $t('ChooseWarehouseForTill') }}</p>
            <p class="text-muted" v-else>{{ $t('NoTillOpen') }}</p>
            <b-button variant="primary" :disabled="!warehouse_id" @click="Ask_Open_Till">
              {{ $t('OpenTill') }}
            </b-button>
          </div>

          <div v-else-if="tillReading">
            <b-row class="mb-3">
              <b-col md="3">
                <p class="text-muted mb-0">{{ $t('Terminal') }}</p>
                <p class="mb-0"><strong>{{ tillReading.session.terminal }}</strong></p>
              </b-col>
              <b-col md="3">
                <p class="text-muted mb-0">{{ $t('OpenedAt') }}</p>
                <p class="mb-0">{{ tillReading.session.opened_at }}</p>
              </b-col>
              <b-col md="3">
                <p class="text-muted mb-0">{{ $t('Sales') }}</p>
                <p class="mb-0">{{ tillReading.sales.count }}</p>
              </b-col>
              <b-col md="3">
                <p class="text-muted mb-0">{{ $t('ExpectedCash') }}</p>
                <p class="text-24 line-height-1 mb-0">
                  {{ currentUser.currency }} {{ money(tillReading.cash.expected) }}
                </p>
              </b-col>
            </b-row>

            <table class="table table-sm mb-3">
              <tbody>
                <tr><td>{{ $t('OpeningFloat') }}</td>
                    <td class="text-right">{{ money(tillReading.cash.opening_float) }}</td></tr>
                <tr><td>{{ $t('CashSales') }}</td>
                    <td class="text-right">{{ money(tillReading.cash.cash_sales) }}</td></tr>
                <tr><td>{{ $t('CashIn') }}</td>
                    <td class="text-right">{{ money(tillReading.cash.cash_in) }}</td></tr>
                <tr><td>{{ $t('CashOut') }}</td>
                    <td class="text-right">{{ less(tillReading.cash.cash_out) }}</td></tr>
                <tr v-if="tillReading.cash.refunds > 0">
                    <td>{{ $t('Refunds') }}</td>
                    <td class="text-right">{{ less(tillReading.cash.refunds) }}</td></tr>
                <tr><th>{{ $t('ExpectedCash') }}</th>
                    <th class="text-right">{{ money(tillReading.cash.expected) }}</th></tr>
              </tbody>
            </table>

            <div class="d-flex flex-wrap">
              <b-button class="mr-2 mb-2" variant="outline-primary"
                        @click="Ask_Cash('cash_in')">{{ $t('CashIn') }}</b-button>
              <b-button class="mr-2 mb-2" variant="outline-primary"
                        @click="Ask_Cash('cash_out')">{{ $t('CashOut') }}</b-button>
              <b-button class="mr-2 mb-2" variant="outline-secondary"
                        @click="Print_Reading('X')">{{ $t('PrintXReading') }}</b-button>
              <b-button class="mr-2 mb-2" variant="danger"
                        @click="Ask_Close_Till">{{ $t('CloseTill') }}</b-button>
            </div>
          </div>
        </b-modal>

        <!-- The reading as it prints. Kept off-screen; the print path reads it. -->
        <div style="display:none">
          <div id="till-reading-print" v-if="tillReading">
            <div style="text-align:center">
              <h3 style="margin:0">{{ currentUser.company_name || '' }}</h3>
              <p style="margin:0"><strong>{{ readingKind === 'Z' ? $t('ZReading') : $t('XReading') }}</strong></p>
              <p style="margin:0" v-if="tillReading.session.z_counter">
                Z# {{ tillReading.session.z_counter }}
              </p>
              <p style="margin:0">{{ $t('Terminal') }}: {{ tillReading.session.terminal }}</p>
              <p style="margin:0">{{ tillReading.session.opened_at }} &ndash;
                 {{ tillReading.session.closed_at || $t('Open') }}</p>
            </div>
            <hr>
            <table style="width:100%">
              <tr><td>{{ $t('Sales') }}</td><td style="text-align:right">{{ tillReading.sales.count }}</td></tr>
              <tr><td>{{ $t('FirstInvoice') }}</td><td style="text-align:right">{{ tillReading.sales.first_invoice || '-' }}</td></tr>
              <tr><td>{{ $t('LastInvoice') }}</td><td style="text-align:right">{{ tillReading.sales.last_invoice || '-' }}</td></tr>
              <tr><td>{{ $t('GrossSales') }}</td><td style="text-align:right">{{ money(tillReading.sales.gross) }}</td></tr>
              <tr><td>{{ $t('Discount') }}</td><td style="text-align:right">{{ less(tillReading.discounts.statutory + tillReading.discounts.regular) }}</td></tr>
              <tr v-if="tillReading.discounts.vat_exemption > 0"><td>{{ $t('VatExemptScPwd') }}</td><td style="text-align:right">{{ less(tillReading.discounts.vat_exemption) }}</td></tr>
              <tr><td><strong>{{ $t('NetSales') }}</strong></td><td style="text-align:right"><strong>{{ money(tillReading.sales.net) }}</strong></td></tr>
            </table>
            <hr>
            <table style="width:100%">
              <tr><td>{{ $t('VatableSales') }}</td><td style="text-align:right">{{ money(tillReading.vat.vatable_sales) }}</td></tr>
              <tr><td>{{ $t('VAT') }}</td><td style="text-align:right">{{ money(tillReading.vat.vat_amount) }}</td></tr>
              <tr><td>{{ $t('VatExemptSales') }}</td><td style="text-align:right">{{ money(tillReading.vat.vat_exempt_sales) }}</td></tr>
              <tr><td>{{ $t('ZeroRatedSales') }}</td><td style="text-align:right">{{ money(tillReading.vat.zero_rated_sales) }}</td></tr>
            </table>
            <hr>
            <table style="width:100%">
              <tr v-for="(m, i) in tillReading.payments" :key="'pm' + i">
                <td>{{ m.method }} ({{ m.count }})</td>
                <td style="text-align:right">{{ money(m.amount) }}</td>
              </tr>
            </table>
            <hr>
            <table style="width:100%">
              <tr><td>{{ $t('OpeningFloat') }}</td><td style="text-align:right">{{ money(tillReading.cash.opening_float) }}</td></tr>
              <tr><td>{{ $t('CashSales') }}</td><td style="text-align:right">{{ money(tillReading.cash.cash_sales) }}</td></tr>
              <tr><td>{{ $t('CashIn') }}</td><td style="text-align:right">{{ money(tillReading.cash.cash_in) }}</td></tr>
              <tr><td>{{ $t('CashOut') }}</td><td style="text-align:right">{{ less(tillReading.cash.cash_out) }}</td></tr>
              <tr v-if="tillReading.cash.refunds > 0"><td>{{ $t('Refunds') }}</td><td style="text-align:right">{{ less(tillReading.cash.refunds) }}</td></tr>
              <tr><td><strong>{{ $t('ExpectedCash') }}</strong></td><td style="text-align:right"><strong>{{ money(tillReading.cash.expected) }}</strong></td></tr>
              <tr v-if="tillReading.cash.counted !== null"><td>{{ $t('CountedCash') }}</td><td style="text-align:right">{{ money(tillReading.cash.counted) }}</td></tr>
              <tr v-if="tillReading.cash.variance !== null"><td><strong>{{ $t('Variance') }}</strong></td><td style="text-align:right"><strong>{{ money(tillReading.cash.variance) }}</strong></td></tr>
            </table>
            <hr v-if="tillReading.accumulated.after !== null">
            <table style="width:100%" v-if="tillReading.accumulated.after !== null">
              <tr><td>{{ $t('AccumulatedBefore') }}</td><td style="text-align:right">{{ money(tillReading.accumulated.before) }}</td></tr>
              <tr><td>{{ $t('AccumulatedAfter') }}</td><td style="text-align:right">{{ money(tillReading.accumulated.after) }}</td></tr>
            </table>
          </div>
        </div>
      </b-row>
    </div>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { repriceForUnit } from "../../../utils/unitPrice";
import offlineQueue from "../../../plugins/offlineQueue";
import { startingWarehouse } from "../../../utils/defaultWarehouse";
import { firstSerialProblem, unitFactor } from "../../../utils/serials";
import { mapActions, mapGetters } from "vuex";
import vueEasyPrint from "vue-easy-print";
import VueBarcode from "vue-barcode";
import FlagIcon from "vue-flag-icon";
import Util from "./../../../utils";

export default {
  components: {
    vueEasyPrint,
    barcode: VueBarcode,
    FlagIcon
  },
  metaInfo: {
    title: "POS"
  },
  data() {
    return {
      // Paying by wallet: the charge, and the timer that waits for it.
      walletChannels: [],
      walletConfigured: false,
      walletPayment: null,
      walletBusy: false,
      walletTimer: null,
      walletPollSeconds: 3,

      // Baskets put to one side, and paying one sale more than one way.
      holds: [],
      holdBusy: false,
      holdForm: { label: "", notes: "" },
      splitPayment: false,
      paymentParts: [],
      paymentMethods: [
        { label: "Cash", value: "Cash" },
        { label: "cheque", value: "cheque" },
        { label: "Western Union", value: "Western Union" },
        { label: "bank transfer", value: "bank transfer" },
        { label: "other", value: "other" }
      ],

      // The drawer this cashier is working.
      till: null,
      tillReading: null,
      tillRequired: false,
      tillBusy: false,
      readingKind: "X",
      tillForm: { terminal: "MAIN", opening_float: 0, notes: "" },
      cashForm: { type: "cash_in", amount: 0, reason: "" },
      closeForm: { counted_cash: "", notes: "" },

      // Offline till state.
      isOffline: false,
      pendingOffline: 0,
      syncingOffline: false,
      onlineHandler: null,
      offlineHandler: null,

      langs: [
        "en",
        "fr",
        "ar",
        "de",
        "es",
        "it",
        "Ind",
        "thai",
        "tr_ch",
        "sm_ch",
        "tur",
        "ru",
        "hn",
        "vn"
      ],
      paymentProcessing: false,
      payment: {
        amount: "",
        received_amount: "",
        Reglement: "",
        notes: ""
      },
      focused: false,
      timer:null,
      search_input:'',
      product_filter:[],
      // Barcode scanner support. A scanner is a keyboard that types very fast
      // and finishes with Enter; a person types slowly and does not. Telling the
      // two apart is what stops one scan running onto the end of the last.
      // Products whose image 404s, so the frame can be dropped for the name.
      failedImages: {},
      lastKeyAt: 0,
      scanBurst: false,
      SCAN_GAP_MS: 60,
      isLoading: true,
      GrandTotal: 0,
      total: 0,
      Ref: "",
      clients: [],
      units: [],
      warehouses: [],
      payments: [],
      products: [],
      products_pos: [],
      details: [],
      detail: {},
      categories: [],
      brands: [],
      pos_settings:{},
      product_currentPage: 1,
      paginated_Products: "",
      // Cards are smaller now that the empty image frames are gone,
      // so more fit without scrolling -- five pages for 33 items was
      // more paging than picking.
      product_perPage: 18,
      product_totalRows: "",
      paginated_Brands: "",
      brand_currentPage: 1,
      brand_perPage: 3,
      paginated_Category: "",
      category_currentPage: 1,
      category_perPage: 3,
      barcodeFormat: "CODE128",
      invoice_pos: {
        sale: {
          Ref: "",
          client_name: "",
          discount: "",
          taxe: "",
          date: "",
          tax_rate: "",
          shipping: "",
          GrandTotal: "",
          paid_amount: ""
        },
        
        details: [],
        setting: {
          logo: "",
          CompanyName: "",
          CompanyAdress: "",
          email: "",
          CompanyPhone: ""
        }
      },

      sale: {
        warehouse_id: "",
        client_id: "",
        tax_rate: 0,
        shipping: 0,
        discount: 0,
        TaxNet: 0,
        // Senior Citizen / PWD. "none" rather than an empty string, so the
        // select shows None on load and the fields stay hidden until someone
        // actually claims the discount.
        statutory_type: "none",
        statutory_id_number: "",
        statutory_holder_name: "",
        statutory_beneficiaries: 1,
        statutory_total_persons: 1
      },
      vat: null,
      client: {
        id: "",
        name: "",
        code: "",
        email: "",
        phone: "",
        country: "",
        city: "",
        adresse: ""
      },
      category_id: "",
      brand_id: "",
      product: {
        id: "",
        code: "",
        current: "",
        quantity: "",
        check_qty: "",
        discount: "",
        DiscountNet: "",
        discount_Method: "",
        sale_unit_id: "",
        fix_stock: "",
        fix_price: "",
        name: "",
        unitSale: "",
        Net_price: "",
        Unit_price: "",
        Total_price: "",
        subtotal: "",
        product_id: "",
        detail_id: "",
        taxe: "",
        tax_percent: "",
        tax_method: "",
        product_variant_id: ""
      },
      sound: "/audio/Beep.wav",
      audio: new Audio("/audio/Beep.wav")
    };
  },

  watch: {
    // The amount field the sale validates against follows the parts.
    paymentParts: {
      deep: true,
      handler() {
        this.Sync_Split();
      }
    }
  },

  computed: {
    // Only a user allowed to override prices may change a line's price or
    // discount; the server uses the list price for everyone else (DATATEST K3).
    canOverridePrice() {
      const p = this.$store.getters.currentUserPermissions;
      return !!(p && p.includes("price_override"));
    },
    ...mapGetters(["currentUser", "currentUserPermissions"]),

    // The single-payment choice: the split list plus card, which takes one
    // payment only. Built from paymentMethods so GCash and Maya, added once
    // the server names them, appear here too.
    singlePaymentMethods() {
      return [
        this.paymentMethods[0],
        { label: this.$t("CardTerminal"), value: "credit card" },
        ...this.paymentMethods.slice(1)
      ];
    },

    // The warehouse picker binds to sale.warehouse_id, but the till panel, the
    // held baskets and Get_Till were all written against a bare warehouse_id
    // that was never declared anywhere -- so it read undefined for ever. The
    // Open till button stayed disabled whatever you chose, and both Get_Till
    // and Get_Holds returned at their own guard before making a request.
    //
    // Derived rather than copied, so the two can never drift apart again.
    warehouse_id() {
      return this.sale.warehouse_id;
    },

    // A wallet sale is held back until the money has actually arrived.
    walletBlocking() {
      if (!this.isWalletMethod(this.payment.Reglement)) {
        return false;
      }
      return !this.walletPayment || this.walletPayment.status !== "paid";
    },

    // What the parts add up to, and what is left on the sale. Shown while
    // the cashier types so the shortfall is visible before they commit.
    splitTotal() {
      return this.paymentParts.reduce((sum, p) => sum + (parseFloat(p.amount) || 0), 0);
    },

    splitRemaining() {
      return Math.round((this.GrandTotal - this.splitTotal) * 100) / 100;
    },

    // Three states, not two. The parts are amounts applied to the sale, so
    // overshooting is not change owed -- it is a sale the server will refuse,
    // and showing it in the same green as a settled basket would hide that.
    splitStateClass() {
      if (this.splitRemaining > 0) return "split-owing";
      if (this.splitRemaining < 0) return "split-over";
      return "split-settled";
    },

    // What the drawer should hold right now, for the chip in the header.
    tillExpected() {
      return this.tillReading ? this.money(this.tillReading.cash.expected) : "0.00";
    },

    // Shown while counting down, so a cashier sees the difference forming
    // as they type rather than only after committing.
    closeVariance() {
      if (!this.tillReading) return 0;
      const counted = parseFloat(this.closeForm.counted_cash);
      if (isNaN(counted)) return 0;
      return counted - this.tillReading.cash.expected;
    },

    brand_totalRows() {
      return this.brands.length;
    },
    category_totalRows() {
      return this.categories.length;
    }
  },

  mounted() {
    // The POS has no sidebar of its own (it sits outside the main layout), so
    // it leaves the sidebar's state alone. It used to toggle it here, which
    // closed the desktop sidebar for good and opened the phone drawer.
    this.paginate_products(this.product_perPage, 0);

    // Keep the POS shell loadable when the network drops.
    offlineQueue.registerServiceWorker();

    this.isOffline = !offlineQueue.isOnline();
    this.refreshPendingCount();

    // Bank anything held as soon as the connection returns.
    this.onlineHandler = () => {
      this.isOffline = false;
      this.syncOffline();
    };
    this.offlineHandler = () => {
      this.isOffline = true;
    };
    window.addEventListener("online", this.onlineHandler);
    window.addEventListener("offline", this.offlineHandler);

    if (!this.isOffline) {
      this.syncOffline();
    }
  },

  beforeDestroy() {
    this.Stop_Wallet_Poll();
    if (this.onlineHandler) window.removeEventListener("online", this.onlineHandler);
    if (this.offlineHandler) window.removeEventListener("offline", this.offlineHandler);
  },

  methods: {
    // A different unit is a different price: a strip of 10 costs ten
    // tablets, a box of 100 a hundred (utils/unitPrice). The stock already
    // moved by the unit; the price stayed per piece.
    Reprice_For_Unit(unitId) {
      const from = this.units.find(u => u.id == this.detail.priced_unit_id);
      const to = this.units.find(u => u.id == unitId);
      this.detail.Unit_price = repriceForUnit(this.detail.Unit_price, from, to);
      this.detail.priced_unit_id = unitId;
    },

    //--------------------------- wallet payments ----------------------------\\
    //
    // GCash and Maya settle after the customer acts, not before, so this is the
    // one payment method where the till waits. Nothing here decides whether the
    // money arrived -- it asks the server, which asks the provider. A status
    // this page invented would be a status an attacker could invent too.

    Get_Wallet_Channels() {
      axios
        .get("wallet/channels")
        .then(response => {
          this.walletConfigured = response.data.configured;
          this.walletChannels = response.data.channels || [];
          this.walletPollSeconds = response.data.poll_seconds || 3;

          // Offered beside the other methods, so a cashier picks a wallet the
          // same way they pick cash.
          this.walletChannels.forEach(c => {
            if (!this.paymentMethods.some(m => m.value === c.channel)) {
              this.paymentMethods.push({ label: c.label, value: c.channel });
            }
          });
        })
        .catch(() => {
          this.walletConfigured = false;
        });
    },

    isWalletMethod(method) {
      return !!method && this.walletChannels.some(c => c.channel === method);
    },

    walletStatusText(p) {
      if (!p) return "";
      if (p.failure_reason) return p.failure_reason;
      if (p.status === "mismatch") return this.$t("WalletMismatch");
      if (p.status === "expired") return this.$t("WalletExpired");
      if (p.status === "cancelled") return this.$t("WalletCancelled");
      return this.$t("WalletFailed");
    },

    Start_Wallet() {
      this.walletBusy = true;
      axios
        .post("wallet/start", {
          channel: this.payment.Reglement,
          amount: this.GrandTotal,
          warehouse_id: this.warehouse_id
        })
        .then(response => {
          this.walletBusy = false;
          this.walletPayment = response.data.payment;
          this.Poll_Wallet();
        })
        .catch(error => {
          this.walletBusy = false;
          const data = error.response && error.response.data;
          this.makeToast("danger", (data && data.message) || this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    // Ask on a timer while the customer pays. Stops as soon as the answer is
    // final, so an abandoned charge does not poll for the rest of the shift.
    Poll_Wallet() {
      this.Stop_Wallet_Poll();
      if (!this.walletPayment) {
        return;
      }
      this.walletTimer = setInterval(() => {
        axios
          .get("wallet/" + this.walletPayment.id + "/status")
          .then(response => {
            this.walletPayment = response.data.payment;
            if (this.walletPayment.status !== "pending") {
              this.Stop_Wallet_Poll();
            }
            if (this.walletPayment.status === "paid") {
              this.payment.amount = this.walletPayment.amount;
              this.payment.received_amount = this.walletPayment.amount;
              this.makeToast("success", this.$t("WalletPaid"), this.$t("Success"));
            }
          })
          .catch(() => {
            // A poll that fails is not an answer either way; keep asking.
          });
      }, this.walletPollSeconds * 1000);
    },

    // The money is already with the provider; this is what puts it against the
    // sale that was just written.
    Attach_Wallet(saleId) {
      if (!this.walletPayment || this.walletPayment.status !== "paid" || !saleId) {
        return;
      }
      const reference = this.walletPayment.reference_id;
      axios
        .post("wallet/" + this.walletPayment.id + "/attach", { sale_id: saleId })
        .catch(() => {
          // The money is taken and the sale exists; only the link failed. Say so
          // plainly, because someone has to join them up by hand.
          this.makeToast("danger", this.$t("WalletAttachFailed") + " " + reference, this.$t("Failed"));
        });
    },

    Stop_Wallet_Poll() {
      if (this.walletTimer) {
        clearInterval(this.walletTimer);
        this.walletTimer = null;
      }
    },

    // Abandoning the charge at the till only abandons it here. The charge itself
    // expires on its own, and if the customer pays after this it settles and
    // turns up unattached rather than quietly vanishing.
    Cancel_Wallet() {
      this.Stop_Wallet_Poll();
      this.walletPayment = null;
    },

    //------------------ holds, and paying more than one way ------------------\\

    Ask_Hold() {
      this.holdForm = {
        label: this.sale.client_id && this.clients.length
          ? (this.clients.find(c => c.id === this.sale.client_id) || {}).name || ""
          : "",
        notes: ""
      };
      this.$bvModal.show("pos_hold");
    },

    Hold_Sale() {
      this.holdBusy = true;
      axios
        .post("pos/holds", {
          warehouse_id: this.warehouse_id,
          client_id: this.sale.client_id,
          label: this.holdForm.label,
          notes: this.holdForm.notes,
          cart: this.details,
          total: this.GrandTotal
        })
        .then(() => {
          this.holdBusy = false;
          this.$bvModal.hide("pos_hold");
          // The basket is safe now, so the till is free for the next customer.
          this.Reset_Pos();
          this.Get_Holds();
          this.Refresh_Stock();
          this.makeToast("success", this.$t("SaleHeld"), this.$t("Success"));
        })
        .catch(error => {
          this.holdBusy = false;
          const data = error.response && error.response.data;
          const stock = data && data.errors && data.errors.stock;
          this.makeToast("danger", (stock && stock[0]) || (data && data.message) || this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    // A hold sets stock aside and a recall or discard gives it back: show the
    // shelf as it now stands, in the grid and in the scan search.
    Refresh_Stock() {
      if (this.warehouse_id) {
        this.Get_Products_By_Warehouse(this.warehouse_id);
      }
      this.getProducts(1);
    },

    Get_Holds() {
      if (!this.warehouse_id) {
        this.holds = [];
        return;
      }
      axios
        .get("pos/holds", { params: { warehouse_id: this.warehouse_id } })
        .then(response => {
          this.holds = response.data.holds || [];
        })
        .catch(() => {});
    },

    Open_Holds() {
      this.Get_Holds();
      this.$bvModal.show("pos_holds");
    },

    // Recall takes the basket away as it hands it over, so two tills cannot
    // pick up the same one. The current basket is replaced, not merged --
    // merging two customers' shopping is worse than making the cashier choose.
    Recall_Hold(hold) {
      if (this.details.length) {
        this.$bvModal
          .msgBoxConfirm(this.$t("RecallReplacesBasket"), {
            okVariant: "danger",
            okTitle: this.$t("Yes"),
            cancelTitle: this.$t("No"),
            centered: true
          })
          .then(yes => { if (yes) { this.Do_Recall(hold); } });
        return;
      }
      this.Do_Recall(hold);
    },

    Do_Recall(hold) {
      axios
        .post("pos/holds/" + hold.id + "/recall")
        .then(response => {
          const cart = (response.data.hold && response.data.hold.cart) || [];
          this.details = cart;
          if (response.data.hold.client_id) {
            this.sale.client_id = response.data.hold.client_id;
          }
          // The lines are back but nothing has recomputed the totals, so the
          // till would show a full basket against a total of zero.
          this.CaclulTotal();
          this.$bvModal.hide("pos_holds");
          this.Get_Holds();
          this.Refresh_Stock();
          this.makeToast("success", this.$t("SaleRecalled"), this.$t("Success"));
        })
        .catch(error => {
          const data = error.response && error.response.data;
          this.Get_Holds();
          this.makeToast("danger", (data && data.message) || this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Discard_Hold(hold) {
      this.$bvModal
        .msgBoxConfirm(this.$t("DiscardHoldConfirm", { label: hold.label }), {
          okVariant: "danger",
          okTitle: this.$t("Yes"),
          cancelTitle: this.$t("No"),
          centered: true
        })
        .then(yes => {
          if (!yes) {
            return;
          }
          axios
            .delete("pos/holds/" + hold.id)
            .then(() => {
              this.Get_Holds();
              this.Refresh_Stock();
              this.makeToast("success", this.$t("HoldDiscardedStockBack"), this.$t("Success"));
            })
            .catch(() => {
              this.Get_Holds();
            });
        });
    },

    // Split tender starts as one part for the whole amount, so turning it on
    // never loses what the cashier has already typed.
    Init_Split() {
      if (this.splitPayment) {
        this.paymentParts = [
          { Reglement: this.payment.Reglement || "Cash", amount: this.GrandTotal }
        ];
        // The sale still validates a payment method even when the real answer
        // is the list of parts, so it carries the first one.
        this.payment.Reglement = this.paymentParts[0].Reglement;
        this.Sync_Split();
      } else {
        this.paymentParts = [];
      }
    },

    Add_Part() {
      const left = this.splitRemaining > 0 ? this.splitRemaining : 0;
      this.paymentParts.push({ Reglement: "Cash", amount: left });
      this.Sync_Split();
    },

    Remove_Part(i) {
      this.paymentParts.splice(i, 1);
      this.Sync_Split();
    },

    // The single amount field is what the sale validates against, so it has to
    // follow the parts rather than sit alongside them saying something else.
    Sync_Split() {
      if (!this.splitPayment) {
        return;
      }
      this.payment.amount = Math.round(this.splitTotal * 100) / 100;
      this.payment.received_amount = this.payment.amount;
      if (this.paymentParts.length) {
        this.payment.Reglement = this.paymentParts[0].Reglement;
      }
    },

    //--------------------------- the drawer ----------------------------\\
    //
    // A till session is what makes the drawer answerable: a float to start
    // from, every non-sale movement of cash recorded, and a moment at the end
    // where it is counted rather than assumed. The readings are computed on the
    // server so the X a cashier takes mid-shift and the Z that closes it cannot
    // drift apart.

    money(value) {
      return Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    },

    // A deduction of nothing should read as nothing. "-0.00" on a Z-reading
    // looks like a rounding fault to whoever checks it.
    less(value) {
      const n = Number(value || 0);
      return (n > 0 ? "-" : "") + this.money(n);
    },

    Get_Till() {
      if (!this.warehouse_id) {
        return;
      }
      axios
        .get("till/current", { params: { warehouse_id: this.warehouse_id } })
        .then(response => {
          this.till = response.data.session;
          this.tillReading = response.data.reading;
          this.tillRequired = response.data.required;
        })
        .catch(() => {
          // A till that cannot reach the server still has to sell; the drawer
          // state simply stays as it was.
        });
    },

    Till_Panel() {
      this.Get_Till();
      this.$bvModal.show("till_panel");
    },

    Ask_Open_Till() {
      this.$bvModal.hide("till_panel");
      this.tillForm = { terminal: "MAIN", opening_float: 0, notes: "" };
      this.$bvModal.show("till_open");
    },

    Open_Till() {
      this.tillBusy = true;
      axios
        .post("till/open", {
          warehouse_id: this.warehouse_id,
          opening_float: this.tillForm.opening_float || 0,
          terminal: this.tillForm.terminal || "MAIN",
          notes: this.tillForm.notes
        })
        .then(response => {
          this.tillBusy = false;
          this.till = response.data.session;
          this.tillReading = response.data.reading;
          this.$bvModal.hide("till_open");
          this.makeToast("success", this.$t("TillOpened"), this.$t("Success"));
        })
        .catch(error => {
          this.tillBusy = false;
          const data = error.response && error.response.data;
          this.makeToast("danger", (data && data.message) || this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Ask_Cash(type) {
      this.cashForm = { type: type, amount: 0, reason: "" };
      this.$bvModal.hide("till_panel");
      this.$bvModal.show("till_cash");
    },

    Till_Cash() {
      this.tillBusy = true;
      axios
        .post("till/cash", {
          till_session_id: this.till.id,
          type: this.cashForm.type,
          amount: this.cashForm.amount,
          reason: this.cashForm.reason
        })
        .then(response => {
          this.tillBusy = false;
          this.tillReading = response.data.reading;
          this.$bvModal.hide("till_cash");
          this.makeToast("success", this.$t("Successfully_Created"), this.$t("Success"));
        })
        .catch(error => {
          this.tillBusy = false;
          const data = error.response && error.response.data;
          this.makeToast("danger", (data && data.message) || this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Ask_Close_Till() {
      this.closeForm = { counted_cash: "", notes: "" };
      this.$bvModal.hide("till_panel");
      this.$bvModal.show("till_close");
    },

    Close_Till() {
      this.tillBusy = true;
      axios
        .post("till/sessions/" + this.till.id + "/close", {
          counted_cash: this.closeForm.counted_cash,
          notes: this.closeForm.notes
        })
        .then(response => {
          this.tillBusy = false;
          this.tillReading = response.data.reading;
          this.readingKind = "Z";
          this.till = null;
          this.$bvModal.hide("till_close");
          // The Z is a document, not a message. Print it before the figures
          // leave the screen.
          this.$nextTick(() => this.printElement("till-reading-print"));
          this.makeToast("success", this.$t("TillClosed"), this.$t("Success"));
        })
        .catch(error => {
          this.tillBusy = false;
          const data = error.response && error.response.data;
          this.makeToast("danger", (data && data.message) || this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Print_Reading(kind) {
      this.readingKind = kind;
      this.$nextTick(() => this.printElement("till-reading-print"));
    },
    ...mapActions(["changeSidebarProperties", "changeThemeMode", "logout"]),
    ...mapGetters(["currentUser"]),
    onStatutoryChange() {
      // Clear the details when the discount is switched off, so a
      // stale ID number cannot ride along on the next sale.
      if (this.sale.statutory_type === "none") {
        this.sale.statutory_id_number = "";
        this.sale.statutory_holder_name = "";
        this.sale.statutory_beneficiaries = 1;
        this.sale.statutory_total_persons = 1;
      }
      this.CaclulTotal();
    },
    logoutUser() {
      this.$store.dispatch("logout");
    },

     handleFocus() {
      this.focused = true
    },

    handleBlur() {
      this.focused = false
    },

    //---------------------- Event Select Payment Method ------------------------------\\

    Selected_PaymentMethod(value) {
      // Nothing to load: "credit card" is the shop's own card terminal.
    },

    SetLocal(locale) {
      this.$i18n.locale = locale;
      this.$store.dispatch("language/setLanguage", locale);
      Fire.$emit("ChangeLanguage");
    },

    handleFullScreen() {
      Util.toggleFullScreen();
    },
    logoutUser() {
      this.logout();
    },

    // ------------------------ Paginate Products --------------------\\
    Product_paginatePerPage() {
      this.paginate_products(this.product_perPage, 0);
    },

    paginate_products(pageSize, pageNumber) {
      let itemsToParse = this.products;
      this.paginated_Products = itemsToParse.slice(
        pageNumber * pageSize,
        (pageNumber + 1) * pageSize
      );
    },

    Product_onPageChanged(page) {
      this.paginate_products(this.product_perPage, page - 1);
      this.getProducts(page);
    },

    // ------------------------ Paginate Brands --------------------\\

    BrandpaginatePerPage() {
      this.paginate_Brands(this.brand_perPage, 0);
    },

    paginate_Brands(pageSize, pageNumber) {
      let itemsToParse = this.brands;
      this.paginated_Brands = itemsToParse.slice(
        pageNumber * pageSize,
        (pageNumber + 1) * pageSize
      );
    },

    BrandonPageChanged(page) {
      this.paginate_Brands(this.brand_perPage, page - 1);
    },

    // ------------------------ Paginate Categories --------------------\\

    Category_paginatePerPage() {
      this.paginate_Category(this.category_perPage, 0);
    },

    paginate_Category(pageSize, pageNumber) {
      let itemsToParse = this.categories;
      this.paginated_Category = itemsToParse.slice(
        pageNumber * pageSize,
        (pageNumber + 1) * pageSize
      );
    },

    Category_onPageChanged(page) {
      this.paginate_Category(this.category_perPage, page - 1);
    },

    //--- Submit Validate Create Sale
    Submit_Pos() {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      this.$refs.create_pos.validate().then(success => {
        if (!success) {
          NProgress.done();
          if (this.sale.client_id == "" || this.sale.client_id === null) {
            this.makeToast(
              "danger",
              this.$t("Choose_Customer"),
              this.$t("Failed")
            );
          } else if (
            this.sale.warehouse_id == "" ||
            this.sale.warehouse_id === null
          ) {
            this.makeToast(
              "danger",
              this.$t("Choose_Warehouse"),
              this.$t("Failed")
            );
          } else {
            this.makeToast(
              "danger",
              this.$t("Please_fill_the_form_correctly"),
              this.$t("Failed")
            );
          }
        } else {
          if (this.verifiedForm()) {
            Fire.$emit("pay_now");
          } else {
            NProgress.done();
          }
        }
      });
    },
    //---Submit Validation Update Detail
    submit_Update_Detail() {
      this.$refs.Update_Detail.validate().then(success => {
        if (!success) {
          return;
        } else {
          this.Update_Detail();
        }
      });
    },

    //------ Validate Form Submit_Payment
    Submit_Payment() {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      this.$refs.Add_payment.validate().then(success => {
        if (!success) {
          // Complete the animation of theprogress bar.
          NProgress.done();
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
            if (this.payment.amount > this.payment.received_amount) {
              this.makeToast(
                "warning",
                this.$t("Paying_amount_is_greater_than_Received_amount"),
                this.$t("Warning")
              );
              this.payment.received_amount = 0;
               NProgress.done();
            }
            else if (this.payment.amount > this.GrandTotal) {
              this.makeToast(
                "warning",
                this.$t("Paying_amount_is_greater_than_Grand_Total"),
                this.$t("Warning")
              );
              this.payment.amount = 0;
               NProgress.done();
            }else{
              this.CreatePOS();
            }
       
        }
      });
    },

    //------------- Submit Validation Create & Edit Customer
    Submit_Customer() {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      this.$refs.Create_Customer.validate().then(success => {
        if (!success) {
          NProgress.done();
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Create_Client();
        }
      });
    },

    //---------------------------------------- Create new Customer -------------------------------\\
    Create_Client() {
      axios
        .post("clients", {
          name: this.client.name,
          email: this.client.email,
          phone: this.client.phone,
          country: this.client.country,
          city: this.client.city,
          adresse: this.client.adresse
        })
        .then(response => {
          NProgress.done();
          this.makeToast(
            "success",
            this.$t("Create.TitleCustomer"),
            this.$t("Success")
          );
          this.Get_Client_Without_Paginate();
          this.$bvModal.hide("New_Customer");
        })
        .catch(error => {
          NProgress.done();
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    //------------------------------ New Model (create Customer) -------------------------------\\
    New_Client() {
      this.reset_Form_client();
      this.$bvModal.show("New_Customer");
    },

    //-------------------------------- reset Form -------------------------------\\
    reset_Form_client() {
      this.client = {
        id: "",
        name: "",
        email: "",
        phone: "",
        country: "",
        city: "",
        adresse: ""
      };
    },

    //------------------------------------ Get Clients Without Paginate -------------------------\\
    Get_Client_Without_Paginate() {
      axios
        .get("Get_Clients_Without_Paginate")
        .then(({ data }) => (this.clients = data));
    },

    //---Validate State Fields
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },

    //---------------------- Event Select Warehouse ------------------------------\\
    Selected_Warehouse(value) {
      this.search_input= '';
      this.product_filter = [];
      this.Get_Products_By_Warehouse(value);
      this.getProducts(1);
      this.Get_Holds();
      // The drawer belongs to a warehouse, so the one on screen changes with it.
      // Without this the chip keeps showing the state of whatever warehouse was
      // selected when the page loaded -- which, on a fresh till, is none.
      this.Get_Till();
      if (value) this.$nextTick(() => { if (this.$refs.posSearch) this.$refs.posSearch.focus(); });
    },

    //------------------------------------ Get Products By Warehouse -------------------------\\

    Get_Products_By_Warehouse(id) {
      // Start the progress bar.
        NProgress.start();
        NProgress.set(0.1);
      axios
        .get("Products/Warehouse/" + id + "?stock=" + 1)
         .then(response => {
            this.products_pos = response.data;
             NProgress.done();

            })
          .catch(error => {
          });
    },

    //----------------------------------------- Add Detail of Sale -------------------------\\
    add_product(code) {
      this.audio.play();
      if (this.details.some(detail => detail.code === code)) {
        this.increment_qty_scanner(code);
        // this.makeToast("warning", this.$t("AlreadyAdd"), this.$t("Warning"));
        // Complete the animation of theprogress bar.
        NProgress.done();
      } else {
        if (this.details.length > 0) {
          this.order_detail_id();
        } else if (this.details.length === 0) {
          this.product.detail_id = 1;
        }
        this.details.push(this.product);
      }
    },

    //-------------------------------- order detail id -------------------------\\
    order_detail_id() {
      this.product.detail_id = 0;
      var len = this.details.length;
      this.product.detail_id = this.details[len - 1].detail_id + 1;
    },

    //---------------------- Get_sales_units ------------------------------\\
    Get_sales_units(value) {
      axios
        .get("Get_sales_units?id=" + value)
        .then(({ data }) => (this.units = data));
    },

    //------ Show Modal Update Detail Product
    Modal_Update_Detail(detail) {
      this.detail = {};
      this.Get_sales_units(detail.product_id);
      this.detail.detail_id = detail.detail_id;
      this.detail.sale_unit_id = detail.sale_unit_id;
      this.detail.priced_unit_id = detail.sale_unit_id;
      this.detail.name = detail.name;
      this.detail.Unit_price = detail.Unit_price;
      this.detail.fix_price = detail.fix_price;
      this.detail.fix_stock = detail.fix_stock;
      this.detail.current = detail.current;
      this.detail.tax_method = detail.tax_method;
      this.detail.discount_Method = detail.discount_Method;
      this.detail.discount = detail.discount;
      this.detail.quantity = detail.quantity;
      this.detail.tax_percent = detail.tax_percent;
      this.$bvModal.show("form_Update_Detail");
    },

    //------ Submit Update Detail Product

    Update_Detail() {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id === this.detail.detail_id) {
          // this.convert_unit();
          for (var k = 0; k < this.units.length; k++) {
            if (this.units[k].id == this.detail.sale_unit_id) {
              // Serials are counted in base units: a box of 2 needs 2.
              this.details[i].unit_factor = unitFactor(this.units[k]);
              if (this.units[k].operator == "/") {
                this.details[i].current =
                  this.detail.fix_stock * this.units[k].operator_value;
                this.details[i].unitSale = this.units[k].ShortName;
              } else {
                this.details[i].current =
                  this.detail.fix_stock / this.units[k].operator_value;
                this.details[i].unitSale = this.units[k].ShortName;
              }
            }
          }

          if (this.details[i].current < this.details[i].quantity) {
            this.details[i].quantity = this.details[i].current;
          } else {
            this.details[i].quantity = 1;
          }

          this.details[i].Unit_price = this.detail.Unit_price;
          this.details[i].tax_percent = this.detail.tax_percent;
          this.details[i].tax_method = this.detail.tax_method;
          this.details[i].discount_Method = this.detail.discount_Method;
          this.details[i].discount = this.detail.discount;
          this.details[i].sale_unit_id = this.detail.sale_unit_id;

          if (this.details[i].discount_Method == "2") {
            //Fixed
            this.details[i].DiscountNet = this.details[i].discount;
          } else {
            //Percentage %
            this.details[i].DiscountNet = parseFloat(
              (this.details[i].Unit_price * this.details[i].discount) / 100
            );
          }

          if (this.details[i].tax_method == "1") {
            //Exclusive
            this.details[i].Net_price = parseFloat(
              this.details[i].Unit_price - this.details[i].DiscountNet
            );

            this.details[i].taxe = parseFloat(
              (this.details[i].tax_percent *
                (this.details[i].Unit_price - this.details[i].DiscountNet)) /
                100
            );
            this.details[i].Total_price = parseFloat(
              this.details[i].Net_price + this.details[i].taxe
            );
          } else {
            //Inclusive
            this.details[i].Net_price = parseFloat(
              (this.details[i].Unit_price - this.details[i].DiscountNet) /
                (this.details[i].tax_percent / 100 + 1)
            );

            this.details[i].taxe = parseFloat(
              this.details[i].Unit_price -
                this.details[i].Net_price -
                this.details[i].DiscountNet
            );
            this.details[i].Total_price = parseFloat(
              this.details[i].Net_price + this.details[i].taxe
            );
          }

          this.$forceUpdate();
        }
      }
      this.CaclulTotal();
      this.$bvModal.hide("form_Update_Detail");
    },

    //-- check Qty of  details order if Null or zero
    verifiedForm() {
      if (this.details.length <= 0) {
        this.makeToast(
          "warning",
          this.$t("AddProductToList"),
          this.$t("Warning")
        );
        return false;
      } else {
        var count = 0;
        for (var i = 0; i < this.details.length; i++) {
          if (
            this.details[i].quantity == "" ||
            this.details[i].quantity === 0
          ) {
            count += 1;
          }
        }

        if (count > 0) {
          this.makeToast("warning", this.$t("AddQuantity"), this.$t("Warning"));

          return false;
        }
        // Serial-tracked lines must name their units before payment.
        const serialProblem = firstSerialProblem(this.details);
        if (serialProblem) {
          this.makeToast("warning", serialProblem, this.$t("Warning"));
          return false;
        }
        return true;
      }
    },

    //------------------------------ Print -------------------------\\
    //
    // A browser cannot choose a printer by name. That is a platform limit, not
    // something this code can work around, so there are two paths:
    //
    //   * A local print agent, if one is running. It can address a named printer
    //     and send the ESC/POS bytes a cash drawer and paper cutter need.
    //   * Otherwise the ordinary browser print, laid out for whatever roll is
    //     configured. Launching Chrome with --kiosk-printing makes that silent,
    //     going straight to the machine's default printer with no dialog.
    //
    // The slip is sized here rather than in the stylesheet because the paper is
    // a setting: an 80mm layout printed on a 58mm roll loses its right-hand
    // column, which is where all the money is.
    printPageStyle() {
      const size = (this.pos_settings && this.pos_settings.paper_size) || "80mm";

      if (size === "a4") {
        return "@page { size: A4; margin: 12mm; } body { width: auto; }";
      }

      const width = size === "58mm" ? "58mm" : "80mm";
      // Thermal rolls are continuous: fix the width, let the height run.
      return (
        "@page { size: " + width + " auto; margin: 0; }" +
        "body { width: " + width + "; margin: 0; padding: 2mm; }" +
        "#invoice-POS { max-width: 100%; }" +
        "* { font-size: 11px; line-height: 15px; }"
      );
    },

    print_pos() {
      this.printElement("invoice-POS");
    },

    // The receipt is not the only thing this till prints -- a Z-reading is a
    // document too, and has to go down the same path so it reaches the same
    // named printer through the agent.
    printElement(id) {
      const el = document.getElementById(id);
      if (!el) {
        return;
      }
      const contents = el.innerHTML;

      // Hand it to the agent when one is there; it is the only way to reach a
      // named printer or kick the drawer.
      if (this.printViaAgent(contents)) {
        return;
      }

      // Print from a hidden frame inside this page, not a pop-up window. Auto
      // print runs after the sale's reply, which is not a click, so the
      // pop-up blocker refused the window and no receipt came out. A frame is
      // part of the page and is never blocked.
      const old = document.getElementById("pos-print-frame");
      if (old) {
        old.remove();
      }
      const frame = document.createElement("iframe");
      frame.id = "pos-print-frame";
      frame.setAttribute("aria-hidden", "true");
      frame.style.cssText = "position:fixed;right:0;bottom:0;width:0;height:0;border:0;";
      document.body.appendChild(frame);

      const doc = frame.contentWindow.document;
      doc.open();
      doc.write(
        '<html><head><link rel="stylesheet" href="/css/pos_print.css">' +
          "<style>" + this.printPageStyle() + "</style></head>" +
          "<body>" + contents + "</body></html>"
      );
      doc.close();

      // Wait for the stylesheet, or the first slip of the day prints
      // unstyled; the timer covers a stylesheet that was already cached.
      let printed = false;
      const go = () => {
        if (printed) {
          return;
        }
        printed = true;
        try {
          frame.contentWindow.focus();
          frame.contentWindow.print();
        } catch (e) {
          this.makeToast(
            "warning",
            "The receipt did not print. Press Print on the receipt to try again.",
            this.$t("Warning")
          );
        }
      };
      const link = doc.querySelector("link");
      if (link) {
        link.onload = go;
        link.onerror = go;
      }
      setTimeout(go, 1000);
    },

    //------------------------- QZ Tray (local agent) -----------------------\\
    //
    // A browser cannot address a printer by name, kick a cash drawer or cut
    // paper -- all three need raw bytes on a device handle. QZ Tray is a small
    // desktop service that does have those handles; the page talks to it over a
    // websocket on localhost.
    //
    // The client library is vendored at /js/vendor/qz-tray.js and loaded only
    // when a printer has actually been named, so a till that does not use it
    // never pays for the download.
    loadQz() {
      if (typeof window.qz !== "undefined") {
        return Promise.resolve(window.qz);
      }
      if (this._qzLoading) {
        return this._qzLoading;
      }

      this._qzLoading = new Promise((resolve, reject) => {
        const el = document.createElement("script");
        el.src = "/js/vendor/qz-tray.js";
        el.onload = () => resolve(window.qz);
        el.onerror = () => reject(new Error("qz-tray.js could not be loaded"));
        document.head.appendChild(el);
      });

      return this._qzLoading;
    },

    // Everything QZ needs before it will talk to us.
    //
    // Unsigned, QZ asks the operator to allow the site on every run -- fine to
    // try it with, unusable at a till. Signing removes the prompt: the server
    // holds a certificate and signs each request. Both endpoints are optional;
    // when they are not configured the promises reject and QZ falls back to
    // prompting, which is the honest behaviour rather than a silent failure.
    prepareQz(qz) {
      if (this._qzPrepared) {
        return;
      }
      this._qzPrepared = true;

      // axios, not fetch: both endpoints sit behind the API guard -- the signing
      // one must, or anyone could have arbitrary strings signed with the private
      // key -- and axios is the client already carrying the session credentials
      // this application authenticates with.
      qz.security.setCertificatePromise((resolve, reject) => {
        axios.get("pos/qz/certificate")
          .then(r => (typeof r.data === "string" && r.data.indexOf("BEGIN CERTIFICATE") !== -1
            ? resolve(r.data) : reject(new Error("no certificate configured"))))
          .catch(() => reject(new Error("no certificate configured")));
      });

      qz.security.setSignatureAlgorithm("SHA512");
      qz.security.setSignaturePromise(toSign => (resolve, reject) => {
        axios.get("pos/qz/sign", { params: { request: toSign } })
          .then(r => (r.data ? resolve(r.data) : reject(new Error("no signature"))))
          .catch(() => reject(new Error("signing is not configured")));
      });
    },

    qzConnect(qz) {
      this.prepareQz(qz);

      return qz.websocket.isActive()
        ? Promise.resolve()
        : qz.websocket.connect({ retries: 2, delay: 1 });
    },

    // ESC/POS as hex, deliberately.
    //
    // Written as JavaScript escapes ("\x1B\x70...") these are one editing
    // mistake away from becoming the literal text backslash-x-1-B, which prints
    // as gibberish and never opens anything -- a mistake that is invisible until
    // a drawer stays shut in front of a customer. Hex has no escape layer to get
    // wrong, and QZ decodes it itself.
    drawerCommand() {
      // ESC p m t1 t2 -- m selects the pin, then on/off pulse widths.
      const pin = Number(this.pos_settings && this.pos_settings.drawer_pin) === 1 ? "01" : "00";

      return "1B70" + pin + "19FA";
    },

    cutCommand() {
      return "1D5601";   // GS V 1 -- partial cut, leaves the slip attached by a tab
    },

    // Returns true when the agent took the job, false to let the browser print.
    printViaAgent(contents) {
      const s = this.pos_settings || {};

      if (!s.printer_name) {
        return false;
      }

      this.loadQz()
        .then(qz => this.qzConnect(qz).then(() => qz))
        .then(qz => qz.printers.find(s.printer_name).then(found => ({ qz, found })))
        .then(({ qz, found }) => {
          const cfg = qz.configs.create(found);
          const jobs = [{
            type: "html", format: "plain",
            data: "<style>" + this.printPageStyle() + "</style>" + contents
          }];

          if (s.open_cash_drawer) {
            jobs.push({ type: "raw", format: "hex", data: this.drawerCommand() });
          }
          if (s.auto_cut !== false) {
            jobs.push({ type: "raw", format: "hex", data: this.cutCommand() });
          }

          return qz.print(cfg, jobs);
        })
        .catch(err => {
          this.makeToast("warning",
            this.$t("PrintAgentFailed") + " " + (err && err.message ? err.message : err),
            this.$t("Warning"));
        });

      return true;
    },

    // Fire the drawer on its own, so the hardware can be proved without ringing
    // up a sale -- and so a cashier can open it to give change on a card sale.
    openCashDrawer() {
      const s = this.pos_settings || {};

      if (!s.printer_name) {
        this.makeToast("warning", this.$t("PrinterNameRequired"), this.$t("Warning"));

        return;
      }

      this.loadQz()
        .then(qz => this.qzConnect(qz).then(() => qz))
        .then(qz => qz.printers.find(s.printer_name).then(found => ({ qz, found })))
        .then(({ qz, found }) => qz.print(qz.configs.create(found), [
          { type: "raw", format: "hex", data: this.drawerCommand() }
        ]))
        .then(() => this.makeToast("success", this.$t("CashDrawerOpened"), this.$t("Success")))
        .catch(err => {
          this.makeToast("warning",
            this.$t("PrintAgentFailed") + " " + (err && err.message ? err.message : err),
            this.$t("Warning"));
        });
    },

    //-------------------------------- Invoice POS ------------------------------\\
    Invoice_POS(id) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("Sales/Print_Invoice/" + id)
        .then(response => {
          this.invoice_pos = response.data;
          this.payments = response.data.payments;
          this.pos_settings = response.data.pos_settings;
          setTimeout(() => {
            // Complete the animation of the  progress bar.
            NProgress.done();
            this.$bvModal.show("Show_invoice");
          }, 500);
          // Printing the moment the sale is banked is what a till should do, but
          // it is now a setting -- a shop that prints only on request should not
          // have a window opening after every transaction.
          if (this.pos_settings && this.pos_settings.auto_print) {
            setTimeout(() => this.print_pos(), 1000);
          }
        })
        .catch(() => {
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
        });
    },

    //----------------------------------Create POS ------------------------------\\
    CreatePOS() {
      NProgress.start();
      NProgress.set(0.1);
      {
        this.paymentProcessing = true;

        const posPayload = {
          client_id: this.sale.client_id,
          warehouse_id: this.sale.warehouse_id,
          tax_rate: this.sale.tax_rate,
          TaxNet: this.sale.TaxNet,
          discount: this.sale.discount,
          shipping: this.sale.shipping,
          statutory_type: this.sale.statutory_type,
          statutory_id_number: this.sale.statutory_id_number,
          statutory_holder_name: this.sale.statutory_holder_name,
          statutory_beneficiaries: this.sale.statutory_beneficiaries,
          statutory_total_persons: this.sale.statutory_total_persons,
          details: this.details,
          GrandTotal: this.GrandTotal,
          payment: this.payment,
          payments: this.splitPayment
            ? this.paymentParts.filter(p => parseFloat(p.amount) > 0)
            : undefined,
          amount : parseFloat(this.payment.amount).toFixed(2),
          received_amount : parseFloat(this.payment.received_amount).toFixed(2),
          change: parseFloat(this.payment.received_amount - this.payment.amount).toFixed(2),
        };

        // No connection: hold the sale rather than losing it. A card payment
        // cannot be taken offline, so only this cash path queues.
        if (!offlineQueue.isOnline()) {
          offlineQueue
            .enqueue(posPayload)
            .then(() => {
              NProgress.done();
              this.paymentProcessing = false;
              this.$bvModal.hide("Add_Payment");
              this.Reset_Pos();
              this.refreshPendingCount();
              this.makeToast("warning", this.$t("SaleHeldOffline"), this.$t("Offline"));
            })
            .catch(() => {
              NProgress.done();
              this.paymentProcessing = false;
              this.makeToast("danger", this.$t("CouldNotHoldSale"), this.$t("Failed"));
            });
          return;
        }

        axios
          .post("pos/CreatePOS", posPayload)
          .then(response => {
            if (response.data.success === true) {
              // Complete the animation of theprogress bar.
              NProgress.done();
              this.paymentProcessing = false;
              this.Invoice_POS(response.data.id);
              this.$bvModal.hide("Add_Payment");
              this.Reset_Pos();
            }
          })
          .catch(error => {
            NProgress.done();
            this.paymentProcessing = false;
            const response = error.response && error.response.data;
            // The first reason the server gives, whatever field it is on: a
            // batch-tracked medicine whose remaining stock has expired answers
            // on "batch", and showing only "stock" left the cashier with
            // "The given data was invalid."
            const errors = (response && response.errors) || {};
            const first = Object.keys(errors).map(k => errors[k]).find(list => list && list.length);
            const message = first
              ? first[0]
              : (response && response.message ? response.message : this.$t("InvalidData"));
            this.makeToast("danger", message, this.$t("Failed"));
          });
      }
    },

    //-------------------------- Offline queue --------------------------\\

    refreshPendingCount() {
      offlineQueue.count().then(c => { this.pendingOffline = c; });
    },

    /**
     * Push whatever was captured offline. The server keys every sale, so a
     * retry after a lost reply cannot bank the same sale twice.
     */
    syncOffline() {
      if (this.syncingOffline || !offlineQueue.isOnline()) {
        return;
      }

      this.syncingOffline = true;
      offlineQueue
        .sync(axios)
        .then(result => {
          this.syncingOffline = false;
          this.refreshPendingCount();

          if (!result.attempted) {
            return;
          }

          if (result.accepted || result.duplicates) {
            this.makeToast(
              "success",
              this.$t("OfflineSalesBanked") + ": " + (result.accepted + result.duplicates),
              this.$t("Success")
            );
          }

          // A sale the server refused is not retried forever; say why.
          result.problems.forEach(p => {
            this.makeToast("danger", p.message, this.$t("OfflineSaleRejected"));
          });
        })
        .catch(() => {
          this.syncingOffline = false;
        });
    },

    //------------------------------Formetted Numbers -------------------------\\
    formatNumber(number, dec) {
      // A null here used to throw inside the render and blank the rest of the
      // page -- the API returns one for, among other things, a warehouse the
      // product has no stock row in. Nothing is a better answer than a crash.
      if (number === null || number === undefined || number === "") number = 0;
      const value = (typeof number === "string"
        ? number
        : number.toString()
      ).split(".");
      if (dec <= 0) return value[0];
      let formated = value[1] || "";
      if (formated.length > dec)
        return `${value[0]}.${formated.substr(0, dec)}`;
      while (formated.length < dec) formated += "0";
      return `${value[0]}.${formated}`;
    },

    //---------------------------------Get Product Details ------------------------\\

    Get_Product_Details(product, product_id) {
      axios.get("Products/" + product_id).then(response => {
        this.product.discount = 0;
        this.product.DiscountNet = 0;
        this.product.discount_Method = "2";
        this.product.product_id = response.data.id;
        this.product.name = response.data.name;
        this.product.Net_price = response.data.Net_price;
        this.product.Total_price = response.data.Total_price;
        this.product.Unit_price = response.data.Unit_price;
        this.product.taxe = response.data.tax_price;
        this.product.tax_method = response.data.tax_method;
        this.product.tax_percent = response.data.tax_percent;
        this.product.unitSale = response.data.unitSale;
        this.product.product_variant_id = product.product_variant_id;
        this.product.code = product.code;
        this.product.fix_price = response.data.fix_price;
        this.product.sale_unit_id = response.data.sale_unit_id;
        this.product.unit_factor = response.data.sale_unit_factor || 1;
        this.product.vat_class = response.data.vat_class || "vatable";
        this.product.sc_pwd_eligible = response.data.sc_pwd_eligible !== false;
        this.add_product(product.code);
        this.CaclulTotal();
        // Complete the animation of theprogress bar.
        NProgress.done();
      });
    },

    //----------- Calcul Total
    CaclulTotal() {
      this.total = 0;
      for (var i = 0; i < this.details.length; i++) {
        var tax = this.details[i].taxe * this.details[i].quantity;
        this.details[i].subtotal = parseFloat(
          this.details[i].quantity * this.details[i].Net_price + tax
        );

        this.total = parseFloat(this.total + this.details[i].subtotal);
      }
      const total_without_discount = parseFloat(
        this.total - this.sale.discount
      );
      this.sale.TaxNet = parseFloat(
        (total_without_discount * this.sale.tax_rate) / 100
      );

      // The VAT breakdown and the statutory discount, mirrored from the server
      // so the cashier can tell the customer what they owe before taking the
      // money. The server recomputes all of it on submit and its answer is the
      // one that is stored -- this only has to agree with it on screen.
      this.vat = this.Calcul_Vat();

      this.GrandTotal = parseFloat(
        (this.vat.total_due + this.sale.TaxNet).toFixed(2)
      );
    },

    //----------- VAT classification and the SC/PWD discount, for display.
    //  Mirrors app/utils/VatService.php. Any change there belongs here too.
    Calcul_Vat() {
      const rate = parseFloat(this.currentUser.vat_rate || 12) / 100;
      const inclusive = this.currentUser.prices_include_vat !== false;
      // Rounds the way PHP's round() does -- to 15 significant digits first,
      // then half away from zero -- so 18.90 / 1.12 (16.874999999999996 in
      // binary) gives the server's 16.88, not 16.87 (DATATEST K85).
      const r2 = v => {
        const cents = Number((Number(Number(v).toPrecision(15)) * 100).toPrecision(15));
        return (cents < 0 ? -Math.round(-cents) : Math.round(cents)) / 100;
      };

      const type = this.sale.statutory_type;
      const claims = type === "senior" || type === "pwd";
      const heads = Math.max(1, parseInt(this.sale.statutory_total_persons) || 1);
      const benef = Math.max(0, parseInt(this.sale.statutory_beneficiaries) || 0);
      const share = claims ? Math.min(1, benef / heads) : 0;

      // An order discount reduces the taxable base, so it is spread across the
      // lines in proportion before anything else happens.
      const amounts = this.details.map(d => parseFloat(d.subtotal) || 0);
      const sum = amounts.reduce((a, b) => a + b, 0);
      const disc = Math.max(0, Math.min(parseFloat(this.sale.discount) || 0, sum));
      let spread = amounts.map(() => 0);
      if (disc > 0 && sum > 0) {
        let running = 0;
        let last = -1;
        amounts.forEach((v, i) => { if (v > 0) last = i; });
        amounts.forEach((v, i) => {
          if (v <= 0 || i === last) return;
          spread[i] = r2(disc * (v / sum));
          running += spread[i];
        });
        if (last >= 0) spread[last] = r2(disc - running);
      }

      const out = { vatable_sales: 0, vat_amount: 0, vat_exempt_sales: 0,
                    zero_rated_sales: 0, statutory_discount: 0, total_due: 0 };

      const lines = this.details.map((d, i) => ({
        amount: r2((parseFloat(d.subtotal) || 0) - spread[i]),
        vat_class: d.vat_class || "vatable",
        eligible: d.sc_pwd_eligible !== false
      }));

      // Shipping is vatable and never carries the statutory discount.
      const ship = parseFloat(this.sale.shipping) || 0;
      if (ship > 0) lines.push({ amount: ship, vat_class: "vatable", eligible: false });

      lines.forEach(l => {
        let net = l.amount;
        let vat = 0;
        if (l.vat_class === "vatable") {
          if (inclusive) { net = l.amount / (1 + rate); vat = l.amount - net; }
          else { vat = l.amount * rate; }
        }

        const s = (share > 0 && l.eligible) ? share : 0;
        const exemptBase = net * s;
        const vatCharged = vat * (1 - s);
        const d = r2(exemptBase * 0.2);

        const netR = r2(net);
        const vatR = r2(vatCharged);
        const total = r2(net + vatCharged - d);

        let exempt = 0, zero = 0;
        if (l.vat_class === "zero_rated") zero = netR;
        else if (l.vat_class === "vat_exempt") exempt = netR;
        else exempt = r2(exemptBase);

        out.vat_exempt_sales += exempt;
        out.zero_rated_sales += zero;
        out.vat_amount += vatR;
        out.statutory_discount += d;
        out.total_due += total;
        out.vatable_sales += r2(total - exempt - zero - vatR + d);
      });

      Object.keys(out).forEach(k => { out[k] = r2(out[k]); });
      return out;
    },

    //-------Verified QTY
    Verified_Qty(detail, id) {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id === id) {
          if (isNaN(detail.quantity)) {
            this.details[i].quantity = detail.current;
          }
          if (detail.quantity > detail.current) {
            this.makeToast("warning", this.$t("LowStock"), this.$t("Warning"));
            this.details[i].quantity = detail.current;
          } else {
            this.details[i].quantity = detail.quantity;
          }
        }
      }
      this.$forceUpdate();
      this.CaclulTotal();
    },

    //----------------------------------- Increment QTY with barcode scanner ------------------------------\\
    increment_qty_scanner(code) {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].code === code) {
          if (this.details[i].quantity + 1 > this.details[i].current) {
            this.makeToast("warning", this.$t("LowStock"), this.$t("Warning"));
          } else {
            this.details[i].quantity++;
          }
        }
      }
      this.CaclulTotal();
      this.$forceUpdate();
    },

    //----------------------------------- Increment QTY ------------------------------\\
    increment(id) {
      console.log(id);
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id == id) {
          if (this.details[i].quantity + 1 > this.details[i].current) {
            this.makeToast("warning", this.$t("LowStock"), this.$t("Warning"));
          } else {
            this.details[i].quantity++;
          }
        }
      }
      this.CaclulTotal();
      this.$forceUpdate();
    },

    //----------------------------------- decrement QTY ------------------------------\\
    decrement(detail, id) {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id == id) {
          if (detail.quantity - 1 > detail.current || detail.quantity - 1 < 1) {
            this.makeToast("warning", this.$t("LowStock"), this.$t("Warning"));
          } else {
            this.details[i].quantity--;
          }
        }
      }
      this.CaclulTotal();
      this.$forceUpdate();
    },

    //---------- keyup OrderTax

    keyup_OrderTax() {
      if (isNaN(this.sale.tax_rate)) {
        this.sale.tax_rate = 0;
      } else {
        this.CaclulTotal();
      }
    },

    //---------- keyup Discount

    keyup_Discount() {
      if (isNaN(this.sale.discount)) {
        this.sale.discount = 0;
      } else {
        this.CaclulTotal();
      }
    },

    //---------- keyup Shipping

    keyup_Shipping() {
      if (isNaN(this.sale.shipping)) {
        this.sale.shipping = 0;
      } else {
        this.CaclulTotal();
      }
    },

    //---------- keyup paid Amount

    Verified_paidAmount() {
      if (isNaN(this.payment.amount)) {
        this.payment.amount = 0;
      } else {
        if (this.payment.amount > this.payment.received_amount) {
          this.makeToast(
            "warning",
            this.$t("Paying_amount_is_greater_than_Received_amount"),
            this.$t("Warning")
          );
          this.payment.amount = 0;
        } 
        else if (this.payment.amount > this.GrandTotal) {
          this.makeToast(
            "warning",
            this.$t("Paying_amount_is_greater_than_Grand_Total"),
            this.$t("Warning")
          );
          this.payment.amount = 0;
        }
      }
    },

    //---------- keyup Received Amount

    Verified_Received_Amount() {
      if (isNaN(this.payment.received_amount)) {
        this.payment.received_amount = 0;
      } 
    },

    //-----------------------------------Delete Detail Product ------------------------------\\
    delete_Product_Detail(id) {
      for (var i = 0; i < this.details.length; i++) {
        if (id === this.details[i].detail_id) {
          this.details.splice(i, 1);
          this.CaclulTotal();
        }
      }
    },

    //----------Reset Pos
    Reset_Pos() {
      // Every completed sale comes through here, so this is the one place the
      // drawer needs re-reading -- the chip in the header would otherwise still
      // show what the till held before the sale.
      this.Get_Till();
      this.splitPayment = false;
      this.paymentParts = [];
      this.Stop_Wallet_Poll();
      this.walletPayment = null;
      this.details = [];
      this.product = {};
      this.payment = {
        amount: "",
        received_amount: "",
        Reglement: "",
        notes: "",
      };
      this.sale.tax_rate = 0;
      this.sale.TaxNet = 0;
      this.sale.shipping = 0;
      this.sale.discount = 0;
      this.GrandTotal = 0;
      this.total = 0;
      this.category_id = "";
      this.brand_id = "";
      this.getProducts(1);
    },

    //------------------------- get Result Value Search Product

    getResultValue(result) {
      return result.code + " " + "(" + result.name + ")";
    },

    //------------------------- Submit Search Product

    SearchProduct(result) {
      this.product = {};

      this.product.code = result.code;
      this.product.is_service = !!result.is_service;
      this.product.has_serials = !!result.has_serials;
      this.product.serials = [];
      this.product.current = result.qte_sale;
      this.product.fix_stock = result.qte;
      if (result.qte_sale < 1) {
        this.product.quantity = result.qte_sale;
      } else {
        this.product.quantity = 1;
      }
      this.product.product_variant_id = result.product_variant_id;
      this.Get_Product_Details(result, result.id);

      this.search_input= '';
      this.product_filter = [];
    },


      // Search Products
    // A product without a picture used to show a broken-image frame taking up
    // half the card, while the name it pushed aside got clipped. Track the
    // failures so the frame is dropped and the name gets the room.
    hasImage(product) {
      if (!product.image || product.image === 'no-image.png') {
        return false;
      }

      return !(this.failedImages && this.failedImages[product.id]);
    },

    imageFailed(product) {
      this.$set(this.failedImages, product.id, true);
    },

    // Reset empties the basket and sits beside Pay Now. Losing a half-rung sale
    // to a mis-tap is worth one question.
    Confirm_Reset() {
      if (this.details.length < 1) {
        this.Reset_Pos();

        return;
      }

      this.$bvModal
        .msgBoxConfirm(this.$t('ClearBasketConfirm', { n: this.details.length }), {
          title: this.$t('Reset'),
          okVariant: 'danger',
          okTitle: this.$t('ClearBasket'),
          cancelTitle: this.$t('Cancel'),
          centered: true
        })
        .then(yes => {
          if (yes) {
            this.Reset_Pos();
          }
        })
        .catch(() => {});
    },

    //--------------------------- Barcode scanner ---------------------------\\
    //
    // A USB scanner presents itself as a keyboard: it types the barcode in a
    // burst of keystroke events a few milliseconds apart, then sends Enter.
    //
    // The search box used to rely on an 800ms timer alone, which broke as soon
    // as a cashier scanned at speed -- the second barcode arrived before the
    // timer fired, the two ran together into one meaningless string, and BOTH
    // items were silently lost. Two rules fix it:
    //
    //   1. Enter resolves the buffer immediately. Scanners send it; waiting out
    //      a timer after the terminator has arrived serves no purpose.
    //   2. A burst that begins after a pause is a NEW barcode, so whatever is
    //      already in the box is resolved first rather than being typed onto.
    //
    // Someone typing by hand never triggers either -- their keystrokes are far
    // apart, so scanBurst stays false and the ordinary search runs as before.
    onScanKey(e) {
      const now = Date.now();
      const gap = now - (this.lastKeyAt || 0);
      this.lastKeyAt = now;

      if (e.key === "Enter" || e.key === "Tab") {
        e.preventDefault();
        this.scanBurst = false;
        this.resolveScan();

        return;
      }

      // Ignore keys that do not produce a character.
      if (e.key && e.key.length > 1) {
        return;
      }

      // A fresh burst arriving after a gap means the previous one is complete
      // and still sitting unresolved in the box.
      if (this.scanBurst && gap > this.SCAN_GAP_MS) {
        this.resolveScan();
        this.scanBurst = false;
      }

      if (gap <= this.SCAN_GAP_MS) {
        this.scanBurst = true;
      }
    },

    // Take whatever is in the box as a barcode and act on it now.
    resolveScan() {
      if (this.timer) {
        clearTimeout(this.timer);
        this.timer = null;
      }

      const code = (this.search_input || "").trim();
      if (code.length < 1) {
        return;
      }

      if (!this.sale.warehouse_id) {
        this.makeToast("warning", this.$t("SelectWarehouse"), this.$t("Warning"));

        return;
      }

      const hit = this.products_pos.find(
        p => p.code === code || p.barcode === code
      );

      if (hit) {
        this.Check_Product_Exist(hit, hit.id);   // clears the box itself

        return;
      }

      // An unrecognised scan used to do nothing at all, which looks exactly like
      // a scan that worked. Say so instead.
      this.makeToast("warning", this.$t("NoProductForBarcode") + " " + code, this.$t("Warning"));
      this.search_input = "";
      this.product_filter = [];
    },

    search(){

      if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
      }

      if (this.search_input.length < 1) {

        return this.product_filter= [];
      }
      if (this.sale.warehouse_id != "" &&  this.sale.warehouse_id != null) {
        this.timer = setTimeout(() => {
          const product_filter = this.products_pos.filter(product => product.code === this.search_input || product.barcode.includes(this.search_input));
            if(product_filter.length === 1){
                this.Check_Product_Exist(product_filter[0], product_filter[0].id);
            }else{
                this.product_filter=  this.products_pos.filter(product => {
                  return (
                    product.name.toLowerCase().includes(this.search_input.toLowerCase()) ||
                    product.code.toLowerCase().startsWith(this.search_input.toLowerCase()) ||
                    product.barcode.toLowerCase().startsWith(this.search_input.toLowerCase())
                    );
                });
            }
        }, 800);
      } else {
        this.makeToast(
          "warning",
          this.$t("SelectWarehouse"),
          this.$t("Warning")
        );
      }

    },

   

    //---------------------------------- Check if Product Exist in Order List ---------------------\\

    Check_Product_Exist(product, id) {
      // this.audio.play();
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      this.product = {};
      this.product.is_service = !!product.is_service;
      this.product.has_serials = !!product.has_serials;
      this.product.serials = [];
      this.product.current = product.qte_sale;
      this.product.fix_stock = product.qte;
      if (product.qte_sale < 1) {
        this.product.quantity = product.qte_sale;
      } else {
        this.product.quantity = 1;
      }
      this.Get_Product_Details(product, id);
      NProgress.done();

      this.search_input= '';
      this.product_filter = [];
    },

    //--- Get Products by Category
    Products_by_Category(id) {
      this.category_id = id;
      this.getProducts(1);
    },

    //--- Get Products by Brand
    Products_by_Brands(id) {
      this.brand_id = id;
      this.getProducts(1);
    },

    //--- Get All Category
    getAllCategory() {
      this.category_id = "";
      this.getProducts(1);
    },

    //--- Get All Brands
    GetAllBrands() {
      this.brand_id = "";
      this.getProducts(1);
    },

    //------------------------------- Get Products with Filters ------------------------------\\
    getProducts(page = 1) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "GetProductsByParametre?page=" +
            page +
            "&category_id=" +
            this.category_id +
            "&brand_id=" +
            this.brand_id +
            "&warehouse_id=" +
            this.sale.warehouse_id +
            "&stock=" +
            1
        )
        .then(response => {
          this.products = response.data.products;
          this.product_totalRows = response.data.totalRows;
          this.Product_paginatePerPage();

          // Complete the animation of theprogress bar.
          NProgress.done();
        })
        .catch(response => {
          // Complete the animation of theprogress bar.
          NProgress.done();
        });
    },

    //---------------------------------------Get Elements ------------------------------\\
    GetElementsPos() {
      axios
        .get("pos/GetELementPos")
        .then(response => {
          this.clients = response.data.clients;
          this.warehouses = response.data.warehouses;
          this.categories = response.data.categories;
          this.brands = response.data.brands;
          // this.pos_settings = response.data.pos_settings;
          this.sale.client_id = response.data.defaultClient;
          // A cashier who works in one warehouse starts in it, rather than
          // picking it every time. The list is already only their warehouses.
          const start = startingWarehouse(this.warehouses, "", response.data.defaultWarehouse) || "";
          this.sale.warehouse_id = start;
          if (start !== "") {
            // As if chosen by hand: products, held baskets and the till chip.
            this.Selected_Warehouse(start);
          } else {
            this.getProducts();
          }
          this.paginate_Brands(this.brand_perPage, 0);
          this.paginate_Category(this.category_perPage, 0);
          this.isLoading = false;
          if (start !== "") this.$nextTick(() => { if (this.$refs.posSearch) this.$refs.posSearch.focus(); });
        })
        .catch(response => {
          this.isLoading = false;
        });
    }
  },

  //-------------------- Created Function -----\\

  created() {
    this.GetElementsPos();
    this.Get_Till();
    this.Get_Holds();
    this.Get_Wallet_Channels();
    Fire.$on("pay_now", () => {
      setTimeout(() => {
        this.payment.amount = this.formatNumber(this.GrandTotal, 2);
        this.payment.received_amount = this.formatNumber(this.GrandTotal, 2);

        this.payment.Reglement = "Cash";
        this.$bvModal.show("Add_Payment");
        // Complete the animation of theprogress bar.
        NProgress.done();
      }, 500);
    });
  }
};
</script>

<style>
/* The change owed to the customer, which a cashier reads out. The inherited
   grey measured 3.02:1 on the dark modal -- under AA for text this size, and a
   poor number to have to squint at with a queue waiting. */
.dark-theme .change_amount {
  color: #e4ede9;
}

/* The wallet panel: a code to scan, and the state of the charge beside it. */
.wallet-panel {
  border: 1px solid rgba(128, 138, 152, 0.35);
  border-radius: 6px;
  padding: 14px 16px;
  margin-top: 10px;
}

.wallet-live {
  display: flex;
  gap: 16px;
  align-items: flex-start;
  flex-wrap: wrap;
}

.wallet-qr img {
  width: 190px;
  height: 190px;
  display: block;
  background: #fff;
  padding: 8px;
  border-radius: 4px;
}

/* A code that could not be drawn falls back to the link, never to a gap. */
.wallet-qr-missing {
  max-width: 260px;
  word-break: break-all;
  font-size: 12px;
}

.wallet-state {
  flex: 1 1 180px;
  min-width: 0;
}

/* Measured tones: the theme own success and danger sit near 2.5:1 as small
   text on the modal ground. */
.wallet-paid {
  color: #087453;
  font-weight: 600;
}

.wallet-failed {
  color: #c62828;
  font-weight: 600;
}

.wallet-pending {
  color: #8a5300;
  font-weight: 600;
}

.dark-theme .wallet-paid {
  color: #10b981;
}

.dark-theme .wallet-failed {
  color: #f87171;
}

.dark-theme .wallet-pending {
  color: #f0bd63;
}

/* A quiet pulse while waiting, so the till reads as working rather than stuck.
   Stilled for anyone who asked for less motion. */
.wallet-dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: currentColor;
  margin-right: 7px;
  animation: wallet-pulse 1.4s ease-in-out infinite;
}

@keyframes wallet-pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.25; }
}

@media (prefers-reduced-motion: reduce) {
  .wallet-dot { animation: none; }
}

/* A row of the split: method, amount, and a way to drop it. */
.split-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
}

.split-method {
  flex: 1 1 55%;
  min-width: 0;
}

.split-amount {
  flex: 1 1 35%;
  min-width: 0;
}

.split-drop {
  flex: 0 0 auto;
  line-height: 1;
}

/* Measured tones: the theme's own success and danger are around 2.5:1 as
   small text on the card. */
.split-owing,
.split-over {
  color: #c62828;
  font-weight: 600;
}

.split-settled {
  color: #087453;
  font-weight: 600;
}

.dark-theme .split-owing,
.dark-theme .split-over {
  color: #f87171;
}

.dark-theme .split-settled {
  color: #10b981;
}

/* The drawer chip. Shut reads as a warning because selling into a closed
   till is the thing that cannot be reconciled afterwards. */
.till-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 12px;
  margin-right: 10px;
  border-radius: 16px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}

.till-is-open {
  background: #e6f4ee;
  color: #087453;
}

.till-is-shut {
  background: #fdf1ed;
  color: #a8320f;
}

.dark-theme .till-is-open {
  background: rgba(16, 185, 129, 0.16);
  color: #10b981;
}

.dark-theme .till-is-shut {
  background: rgba(248, 113, 113, 0.16);
  color: #f87171;
}

.till-short {
  color: #c62828;
}

.till-over {
  color: #087453;
}

.dark-theme .till-short {
  color: #f87171;
}

.dark-theme .till-over {
  color: #10b981;
}

.total {
  font-weight: bold;
  font-size: 14px;
  /* text-transform: uppercase; */
  /* height: 50px; */
}

/* ---------------------------------------------------------------------------
   Till layout.

   The basket panel is about 390px wide. The old five-column table needed 515,
   so the Subtotal -- the number a customer queries -- sat off-screen behind a
   horizontal scrollbar. Each line is now two rows inside two columns, which
   fits, and leaves room for controls a finger can actually hit.
   --------------------------------------------------------------------------- */
.pos-detail .table-responsive { overflow-x: hidden; }

.pos-detail table { table-layout: fixed; width: 100%; }
.pos-detail th:last-child,
.pos-detail td.pos-line-total { width: 34%; text-align: right; }

td.pos-line { padding: 10px 8px !important; }

.pos-line-name {
  font-weight: 600;
  line-height: 1.25;
  word-break: break-word;      /* the full name, not "Coffee 3-in-..." */
}

.pos-line-meta {
  font-size: 0.78rem;
  opacity: 0.7;
  margin-top: 1px;
}

td.pos-line-total {
  font-weight: 700;
  white-space: nowrap;
  vertical-align: middle !important;
}

/* Quantity controls. These were 10x21px; anything under about 40px is a
   coin-flip with a fingertip, and this is the control used most. */
.pos-qty { display: flex; align-items: center; margin-top: 8px; }

.pos-qty-btn {
  width: 40px;
  height: 40px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  font-weight: 600;
  line-height: 1;
  border-radius: 6px;
  cursor: pointer;
  user-select: none;
  background: #0c855d;
  color: #fff;
}
.pos-qty-btn:active { transform: translateY(1px); }

.pos-qty-input {
  width: 56px;
  height: 40px;
  margin: 0 6px;
  text-align: center;
  font-size: 16px;
  font-weight: 600;
  border: 1px solid rgba(0, 0, 0, 0.18);
  border-radius: 6px;
  background: transparent;
  color: inherit;
}

.pos-line-actions { margin-left: auto; display: flex; gap: 4px; }
.pos-line-actions i {
  width: 40px;
  height: 40px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  cursor: pointer;
  border-radius: 6px;
}
.pos-line-actions i:hover { background: rgba(0, 0, 0, 0.06); }

/* ---- product cards ----
   Denser, and the name gets the space the empty image frame used to waste. */
.list-item .card { width: 150px; }
.pos-card-body { padding: 10px !important; }

.pos-card-name {
  font-weight: 600;
  font-size: 0.86rem;
  line-height: 1.25;
  display: -webkit-box;
  -webkit-line-clamp: 3;        /* three lines: "Rice Sinandomeng 25kg" needs
                                   all of them, and the pack size is the half a
                                   cashier is checking */
  -webkit-box-orient: vertical;
  overflow: hidden;
  min-height: 3.3em;
}

.pos-card-code { font-size: 0.72rem; opacity: 0.65; margin: 2px 0 6px; }

.pos-card-foot {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  align-items: center;
}

.dark-theme .pos-qty-input { border-color: rgba(228, 237, 233, 0.25); }
.dark-theme .pos-line-actions i:hover { background: rgba(228, 237, 233, 0.1); }

/* ---- Fitting the till to the screen (responsive audit, phone to desktop) ---- */

/* The wrapper drops Bootstrap's padding (p-0) but its row kept the -15px
   gutters, so the till was 15px wider than the screen on each side. */
#pos.container-fluid > .row {
  margin-left: 0;
  margin-right: 0;
}

/* The header shares the app top bar's fixed 80px height; when its chip and
   icons wrapped in a narrow column they spilled over the customer picker. */
#pos.container-fluid .card-order .main-header {
  height: auto;
  min-height: 80px;
  padding: 6px 12px;
  gap: 4px;
}

/* Scrollbars only when there is something to scroll. */
#pos.container-fluid .list-grid {
  overflow-x: hidden;
  overflow-y: auto;
}

/* Below 992px the basket and the products stack (the b-col lg breakpoints).
   The basket then takes the room its lines need instead of a fixed 42% of the
   screen, so the products are not a long scroll past an empty box. */
@media (max-width: 991.98px) {
  #pos.container-fluid .pos-detail,
  #pos.container-fluid .pos-detail .table-responsive {
    height: auto !important;
  }
  #pos.container-fluid .pos-detail .table-responsive {
    max-height: 45vh !important;
  }
  #pos.container-fluid .card-order {
    min-height: 0;
    margin-bottom: 1rem;
  }
  #pos.container-fluid .list-grid {
    height: auto;
    min-height: 0;
  }
}
</style>
<style scoped>
.pos-warehouse-empty { min-height: 350px; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; padding: 2rem; background: #f5faf8; border: 1px dashed #a8cec0; border-radius: .6rem; color: #194d3d; }
.pos-warehouse-empty i { font-size: 2rem; margin-bottom: .7rem; }
.pos-warehouse-empty h2 { font-size: 1.3rem; font-weight: 700; }
.pos-warehouse-empty p { max-width: 25rem; margin-bottom: 0; }
.list-item [role="button"]:focus-visible { outline: 3px solid #087a56; outline-offset: 3px; }
.offline-bar {
  background: rgba(245, 158, 11, 0.15);
  border-bottom: 1px solid rgba(245, 158, 11, 0.4);
  padding: 8px 16px;
  font-size: 0.9rem;
}
</style>
