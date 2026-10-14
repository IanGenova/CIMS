<template>
  <div class="main-content">
    <breadcumb :page="$t('AddSale')" :folder="$t('ListSales')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <validation-observer ref="create_sale" v-if="!isLoading">
      <b-form @submit.prevent="Submit_Sale">
        <b-row>
          <b-col lg="12" md="12" sm="12">
            <b-card>
              <b-row>
                <!-- date  -->
                <b-col lg="4" md="4" sm="12" class="mb-3">
                  <validation-provider
                    name="date"
                    :rules="{ required: true}"
                    v-slot="validationContext"
                  >
                    <b-form-group :label="$t('date')">
                      <b-form-input
                        :state="getValidationState(validationContext)"
                        aria-describedby="date-feedback"
                        type="date"
                        v-model="sale.date"
                      ></b-form-input>
                      <b-form-invalid-feedback
                        id="OrderTax-feedback"
                      >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>
                <!-- Customer -->
                <b-col lg="4" md="4" sm="12" class="mb-3">
                  <validation-provider name="Customer" :rules="{ required: true}">
                    <b-form-group slot-scope="{ valid, errors }" :label="$t('Customer')">
                      <v-select
                        :class="{'is-invalid': !!errors.length}"
                        :state="errors[0] ? false : (valid ? true : null)"
                        v-model="sale.client_id"
                        :reduce="label => label.value"
                        :placeholder="$t('Choose_Customer')"
                        :options="clients.map(clients => ({label: clients.name, value: clients.id}))"
                      />
                      <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <!-- warehouse -->
                <b-col lg="4" md="4" sm="12" class="mb-3">
                  <validation-provider name="warehouse" :rules="{ required: true}">
                    <b-form-group slot-scope="{ valid, errors }" :label="$t('warehouse')">
                      <v-select
                        :class="{'is-invalid': !!errors.length}"
                        :state="errors[0] ? false : (valid ? true : null)"
                        :disabled="details.length > 0"
                        @input="Selected_Warehouse"
                        v-model="sale.warehouse_id"
                        :reduce="label => label.value"
                        :placeholder="$t('Choose_Warehouse')"
                        :options="warehouses.map(warehouses => ({label: warehouses.name, value: warehouses.id}))"
                      />
                      <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <!-- Product -->
                <b-col md="12" class="mb-5">
                  <h6>{{$t('ProductName')}}</h6>

                  <div id="autocomplete" class="autocomplete">
                    <input
                     :placeholder="$t('Scan_Search_Product_by_Code_Name')"
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

                <!-- order products  -->
                <b-col md="12" class="mb-4">
                  <h5>{{$t('order_products')}} *</h5>
                  <div class="table-responsive">
                    <table class="table table-hover">
                      <thead class="bg-gray-300">
                        <tr>
                          <th scope="col">#</th>
                          <th scope="col">{{$t('ProductName')}}</th>
                          <th scope="col">{{$t('Net_Unit_Price')}}</th>
                          <th scope="col">{{$t('CurrentStock')}}</th>
                          <th scope="col">{{$t('Qty')}}</th>

                          <th scope="col">{{$t('SubTotal')}}</th>
                          <th scope="col" class="text-center">
                            <i class="i-Close-Window text-25"></i>
                          </th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-if="details.length <=0">
                          <td colspan="9">{{$t('NodataAvailable')}}</td>
                        </tr>
                        <tr v-for="detail  in details">
                          <td>{{detail.detail_id}}</td>
                          <td>
                            <span>{{detail.code}}</span>
                            <br>
                            <span class="badge badge-success">{{detail.name}}</span>
                            <i @click="Modal_Updat_Detail(detail)" class="i-Edit"></i>
                            <br v-if="detail.has_serials && sale.statut == 'completed'">
                            <serial-entry
                              v-if="detail.has_serials && sale.statut == 'completed'"
                              v-model="detail.serials"
                              mode="pick"
                              :product-id="detail.product_id"
                              :variant-id="detail.product_variant_id"
                              :warehouse-id="sale.warehouse_id"
                              :units="detail.quantity * (detail.unit_factor || 1)"
                              :name="detail.name"
                            ></serial-entry>
                          </td>
                          <td>{{currentUser.currency}} {{formatNumber(detail.Net_price, 3)}}</td>
                          <td>
                            <span v-if="detail.is_service" class="badge badge-outline-secondary">{{$t('Service')}}</span>
                            <span
                              v-else
                              class="badge badge-outline-warning"
                            >{{detail.stock}} {{detail.unitSale}}</span>
                          </td>
                          <td>
                            <div class="quantity">
                              <b-input-group>
                                <b-input-group-prepend>
                                  <span
                                    class="btn btn-primary btn-sm"
                                    @click="decrement(detail ,detail.detail_id)"
                                  >-</span>
                                </b-input-group-prepend>
                                <input
                                  class="form-control"
                                  @input="Verified_Qty(detail,detail.detail_id)"
                                  :min="0.00"
                                  :max="detail.stock"
                                  v-model.number="detail.quantity"
                                >
                                <b-input-group-append>
                                  <span
                                    class="btn btn-primary btn-sm"
                                    @click="increment(detail ,detail.detail_id)"
                                  >+</span>
                                </b-input-group-append>
                              </b-input-group>
                            </div>
                          </td>

                          <td>{{currentUser.currency}} {{detail.subtotal.toFixed(2)}}</td>
                          <td>
                            <a
                              @click="delete_Product_Detail(detail.detail_id)"
                              class="btn btn-icon btn-sm"
                              title="Delete"
                            >
                              <i class="i-Close-Window text-25 text-danger"></i>
                            </a>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </b-col>

                <div class="offset-md-9 col-md-3 mt-4">
                  <table class="table table-striped table-sm">
                    <tbody>

                      <tr>
                        <td>
                          <span class="font-weight-bold">{{$t('Total')}}</span>
                        </td>
                        <td>
                          <span
                            class="font-weight-bold"
                          >{{currentUser.currency}} {{GrandTotal.toFixed(2)}}</span>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>



                <!-- Status  -->
                <b-col lg="4" md="4" sm="12" class="mb-3">
                  <validation-provider name="Status" :rules="{ required: true}">
                    <b-form-group slot-scope="{ valid, errors }" :label="$t('Status')">
                      <v-select
                        :class="{'is-invalid': !!errors.length}"
                        :state="errors[0] ? false : (valid ? true : null)"
                        v-model="sale.statut"
                        :reduce="label => label.value"
                        :placeholder="$t('Choose_Status')"
                        :options="
                                [
                                  {label: 'completed', value: 'completed'},
                                  {label: 'Pending', value: 'pending'},
                                  {label: 'ordered', value: 'ordered'}
                                ]"
                      ></v-select>
                      <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <!-- PaymentStatus  -->
                <b-col md="4">
                  <validation-provider name="PaymentStatus">
                    <b-form-group :label="$t('PaymentStatus')">
                      <v-select
                        @input="Selected_PaymentStatus"
                        :reduce="label => label.value"
                        v-model="payment.status"
                        :placeholder="$t('Choose_Status')"
                        :options="
                                [
                                  {label: 'Paid', value: 'paid'},
                                  {label: 'partial', value: 'partial'},
                                  {label: 'Pending', value: 'pending'},
                                ]"
                      ></v-select>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <!-- Payment choice -->
                <b-col md="4" v-if="payment.status != 'pending'">
                  <validation-provider name="Payment choice" :rules="{ required: true}">
                    <b-form-group slot-scope="{ valid, errors }" :label="$t('Paymentchoice')">
                      <v-select
                        :class="{'is-invalid': !!errors.length}"
                        :state="errors[0] ? false : (valid ? true : null)"
                        :reduce="label => label.value"
                        v-model="payment.Reglement"
                        :placeholder="$t('PleaseSelect')"
                        :options="paymentMethodOptions"
                      ></v-select>
                      <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                  <!-- Received  Amount (not for Xendit: the customer pays the exact amount online) -->
                  <b-col md="4" v-if="payment.status != 'pending' && payment.Reglement != 'Xendit'">
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


                <!-- Amount  -->
                <b-col md="4" v-if="payment.status != 'pending'">
                  <validation-provider
                    name="Amount"
                    :rules="{ required: true , regex: /^\d*\.?\d*$/}"
                    v-slot="validationContext"
                  >
                    <b-form-group :label="$t('Paying_Amount')">
                      <b-form-input
                        :disabled="payment.status == 'paid'"
                        label="Amount"
                        :placeholder="$t('Paying_Amount')"
                        v-model.number="payment.amount"
                        @input="Verified_paidAmount(payment.amount)"
                        :state="getValidationState(validationContext)"
                        aria-describedby="Amount-feedback"
                      ></b-form-input>
                      <b-form-invalid-feedback
                        id="Amount-feedback"
                      >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <!-- change  Amount  -->
                <b-col md="4" v-if="payment.status != 'pending' && payment.Reglement != 'Xendit'">
                  <label>{{$t('Change')}} :</label>
                  <p
                    class="change_amount"
                  >{{parseFloat(payment.received_amount - payment.amount).toFixed(2)}}</p>
                </b-col>

                <!-- Xendit: the sale is saved first, then the customer pays on their phone. -->
                <b-col
                  md="12"
                  class="mt-3"
                  v-if="payment.status != 'pending' && payment.Reglement == 'Xendit'"
                >
                  <b-alert show variant="info" class="mb-0">
                    <i class="i-Smartphone-4 mr-1"></i>{{$t('XenditCreateSaleExplain')}}
                  </b-alert>
                </b-col>

                <b-col md="12" class="mt-3">
                  <b-form-group :label="$t('Note')">
                    <textarea
                      v-model="sale.notes"
                      rows="4"
                      class="form-control"
                      :placeholder="$t('Afewwords')"
                    ></textarea>
                  </b-form-group>
                </b-col>

                <b-col md="12">
                  <b-form-group>
                    <b-button variant="primary" :disabled="paymentProcessing" @click="Submit_Sale">{{$t('submit')}}</b-button>
                    <div v-once class="typo__p" v-if="paymentProcessing">
                    <div class="spinner sm spinner-primary mt-3"></div>
                  </div>
                  </b-form-group>
                </b-col>
              </b-row>
            </b-card>
          </b-col>
        </b-row>
      </b-form>
    </validation-observer>

    <!-- Modal Update detail Product -->
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
                  <b-form-invalid-feedback id="Price-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
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
                <b-button
                  variant="primary"
                  type="submit"
                  :disabled="paymentProcessing"
                >{{$t('submit')}}</b-button>
                <div v-once class="typo__p" v-if="paymentProcessing">
                  <div class="spinner sm spinner-primary mt-3"></div>
                </div>
              </b-form-group>
            </b-col>
          </b-row>
        </b-form>
      </b-modal>
    </validation-observer>

    <xendit-checkout ref="xenditCheckout" @closed="onXenditDone"></xendit-checkout>
  </div>
</template>


<script>
import { mapActions, mapGetters } from "vuex";
import { repriceForUnit } from "../../../../utils/unitPrice";
import { startingWarehouse } from "../../../../utils/defaultWarehouse";
import { firstSerialProblem, unitFactor } from "../../../../utils/serials";
import NProgress from "nprogress";
import XenditCheckout from "../../../../components/XenditCheckout.vue";

export default {
  components: { XenditCheckout },
  metaInfo: {
    title: "Create Sale"
  },
  data() {
    return {
      focused: false,
      timer:null,
      search_input:'',
      product_filter:[],
      xendit_ready: false,
      paymentProcessing: false,
      isLoading: true,
      warehouses: [],
      clients: [],
      client: {},
      products: [],
      details: [],
      detail: {},
      sales: [],
      payment: {
        status: "pending",
        Reglement: "Cash",
        // 0, not "". Calcul_Total only fills these on the paid branch, so on
        // any other payment status they stayed empty and reached the server as
        // the string "NaN" -- which it refused, leaving no way to record a sale
        // a customer had not yet paid for.
        amount: 0,
        received_amount: 0,
      },
      sale: {
        id: "",
        date: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10),
        statut: "completed",
        notes: "",
        client_id: "",
        warehouse_id: "",
        tax_rate: 0,
        TaxNet: 0,
        shipping: 0,
        discount: 0
      },
      timer:null,
      total: 0,
      GrandTotal: 0,
      units:[],
      product: {
        id: "",
        code: "",
        stock: "",
        quantity: 1,
        discount: "",
        DiscountNet: "",
        discount_Method: "",
        name: "",
        sale_unit_id:"",
        fix_stock:"",
        fix_price:"",
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
      }
    };
  },

  computed: {
    // Only a user allowed to override prices may change a line's price or
    // discount; the server uses the list price for everyone else (DATATEST K3).
    canOverridePrice() {
      const p = this.$store.getters.currentUserPermissions;
      return !!(p && p.includes("price_override"));
    },
    ...mapGetters(["currentUser"]),
    // Xendit only once it is set up on System Settings.
    paymentMethodOptions() {
      const methods = [
        { label: "Cash", value: "Cash" },
        { label: this.$t("CardTerminal"), value: "credit card" },
        { label: "cheque", value: "cheque" },
        { label: "Western Union", value: "Western Union" },
        { label: "bank transfer", value: "bank transfer" },
        { label: "other", value: "other" }
      ];
      if (this.xendit_ready) {
        methods.splice(1, 0, { label: this.$t("XenditMethod"), value: "Xendit" });
      }
      return methods;
    }
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



     handleFocus() {
      this.focused = true
    },

    handleBlur() {
      this.focused = false
    },


    // The Xendit window is done: paid (the sale shows it) or not (it stays unpaid).
    onXenditDone(checkout) {
      if (!checkout || checkout.status !== "paid") {
        this.makeToast("warning", this.$t("XenditSaleSavedUnpaid"), "Xendit");
      }
      this.$router.push({ name: "index_sales" });
    },

    //---------------------- Event Select Payment Status ------------------------------\\

    Selected_PaymentStatus(value){
      if (value == "paid") {
        var payment_amount = this.GrandTotal.toFixed(2);
        this.payment.amount = this.formatNumber(payment_amount, 2);
        this.payment.received_amount = this.formatNumber(payment_amount, 2);
      }else{
        this.payment.amount = 0;
        this.payment.received_amount = 0;
      }
    },

    //---------- keyup paid Amount

    Verified_paidAmount() {
      if (isNaN(this.payment.amount)) {
        this.payment.amount = 0;
      } else if (this.payment.amount > this.payment.received_amount) {
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
    },

    //---------- keyup Received Amount

    Verified_Received_Amount() {
      if (isNaN(this.payment.received_amount)) {
        this.payment.received_amount = 0;
      }
    },



    //--- Submit Validate Create Sale
    Submit_Sale() {
      this.$refs.create_sale.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else if (this.payment.Reglement !== "Xendit" && this.payment.amount > this.payment.received_amount) {
          this.makeToast(
            "warning",
            this.$t("Paying_amount_is_greater_than_Received_amount"),
            this.$t("Warning")
          );
          this.payment.received_amount = 0;
        }
          else if (this.payment.amount > this.GrandTotal) {
            this.makeToast(
              "warning",
              this.$t("Paying_amount_is_greater_than_Grand_Total"),
              this.$t("Warning")
            );
            this.payment.amount = 0;
          }else{
            this.Create_Sale();
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
    //---Validate State Fields
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
         toaster: "b-toaster-top-center",
        solid: true
      });
    },

    //---------------------- Get_sales_units ------------------------------\\
    Get_sales_units(value) {
      axios
        .get("Get_sales_units?id=" + value)
        .then(({ data }) => (this.units = data));
    },

    //------ Show Modal Update Detail Product
    Modal_Updat_Detail(detail) {
      this.detail = {};
      this.Get_sales_units(detail.product_id);
      this.detail.detail_id = detail.detail_id;
      this.detail.sale_unit_id = detail.sale_unit_id;
      this.detail.priced_unit_id = detail.sale_unit_id;
      this.detail.name = detail.name;
      this.detail.Unit_price = detail.Unit_price;
      this.detail.fix_price = detail.fix_price;
      this.detail.fix_stock = detail.fix_stock;
      this.detail.stock = detail.stock;
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
           for(var k=0; k<this.units.length; k++){
              if (this.units[k].id == this.detail.sale_unit_id) {
                // Serials are counted in base units: a box of 2 needs 2.
                this.details[i].unit_factor = unitFactor(this.units[k]);
                if(this.units[k].operator == '/'){
                  this.details[i].stock       = this.detail.fix_stock  * this.units[k].operator_value;
                  this.details[i].unitSale    = this.units[k].ShortName;

                }else{
                  this.details[i].stock       = this.detail.fix_stock  / this.units[k].operator_value;
                  this.details[i].unitSale    = this.units[k].ShortName;
                }
              }
            }

            if (this.details[i].stock < this.details[i].quantity) {
              this.details[i].quantity = this.details[i].stock;
            } else {
              this.details[i].quantity =1;
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
          }

          this.$forceUpdate();
        }
      }
      this.Calcul_Total();
      this.$bvModal.hide("form_Update_Detail");
    },



    // Search Products
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
          const product_filter = this.products.filter(product => product.code === this.search_input || product.barcode.includes(this.search_input));
            if(product_filter.length === 1){
                this.SearchProduct(product_filter[0])
            }else{
                this.product_filter=  this.products.filter(product => {
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

    //------------------------- get Result Value Search Product

    getResultValue(result) {
      return result.code + " " + "(" + result.name + ")";
    },

    //------------------------- Submit Search Product

    SearchProduct(result) {
      this.product = {};
      if (
        this.details.length > 0 &&
        this.details.some(detail => detail.code === result.code)
      ) {
        this.makeToast("warning", this.$t("AlreadyAdd"), this.$t("Warning"));
      } else {
        this.product.code = result.code;
        this.product.is_service = !!result.is_service;
        this.product.has_serials = !!result.has_serials;
        this.product.serials = [];
        this.product.stock = result.qte_sale;
        this.product.fix_stock = result.qte;
        if (result.qte_sale < 1) {
          this.product.quantity = result.qte_sale;
        } else {
          this.product.quantity = 1;
        }
        this.product.product_variant_id = result.product_variant_id;
        this.Get_Product_Details(result.id);
      }

      this.search_input= '';
      this.product_filter = [];
    },

    //---------------------- Event Select Warehouse ------------------------------\\
    Selected_Warehouse(value) {
      this.search_input= '';
      this.product_filter = [];
      this.Get_Products_By_Warehouse(value);
    },

     //------------------------------------ Get Products By Warehouse -------------------------\\

    Get_Products_By_Warehouse(id) {
      // Start the progress bar.
        NProgress.start();
        NProgress.set(0.1);
      axios
        .get("Products/Warehouse/" + id + "?stock=" + 1)
         .then(response => {
            this.products = response.data;
             NProgress.done();

            })
          .catch(error => {
          });
    },

    //----------------------------------------- Add Product to order list -------------------------\\
    add_product() {
      if (this.details.length > 0) {
        this.Last_Detail_id();
      } else if (this.details.length === 0) {
        this.product.detail_id = 1;
      }

      this.details.push(this.product);
    },

    //-----------------------------------Verified QTY ------------------------------\\
    Verified_Qty(detail, id) {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id === id) {
          if (isNaN(detail.quantity)) {
            this.details[i].quantity = detail.stock;
          }

          if (detail.quantity > detail.stock) {
            this.makeToast("warning", this.$t("LowStock"), this.$t("Warning"));
            this.details[i].quantity = detail.stock;
          } else {
            this.details[i].quantity = detail.quantity;
          }
        }
      }
      this.$forceUpdate();
      this.Calcul_Total();
    },

    //-----------------------------------increment QTY ------------------------------\\

    increment(detail, id) {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id == id) {
          if (detail.quantity + 1 > detail.stock) {
            this.makeToast("warning", this.$t("LowStock"), this.$t("Warning"));
          } else {
            this.formatNumber(this.details[i].quantity++, 2);
          }
        }
      }
      this.$forceUpdate();
      this.Calcul_Total();
    },

    //-----------------------------------decrement QTY ------------------------------\\

    decrement(detail, id) {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id == id) {
          if (detail.quantity - 1 > 0) {
            if (detail.quantity - 1 > detail.stock) {
              this.makeToast(
                "warning",
                this.$t("LowStock"),
                this.$t("Warning")
              );
            } else {
              this.formatNumber(this.details[i].quantity--, 2);
            }
          }
        }
      }
      this.$forceUpdate();
      this.Calcul_Total();
    },

    //--------------------------- Why the server refused ----------------------\\
    // Laravel answers a refusal with either a plain message or a field-keyed
    // errors object. Both used to be discarded in favour of one generic line,
    // so a sale blocked by a credit limit and a sale blocked by a typo read
    // identically at the counter.
    serverMessage(error) {
      // The global interceptor in main.js rejects with error.response.data --
      // the body itself, not the axios error -- so error.response is undefined
      // here. Both shapes are accepted so this keeps working if that changes,
      // and a plain string rejection (the interceptor's no-response branch) is
      // passed straight through.
      if (typeof error === "string") return error;
      const data = (error && error.response && error.response.data) || error;
      if (!data || typeof data !== "object") return this.$t("InvalidData");
      if (data.errors) {
        const first = Object.keys(data.errors)[0];
        const msg = first && data.errors[first];
        if (Array.isArray(msg) && msg.length) return msg[0];
        if (typeof msg === "string") return msg;
      }
      if (typeof data.message === "string" && data.message) return data.message;
      return this.$t("InvalidData");
    },

    //--------------------------- Amount for the server -----------------------\\
    // Always a number. Calcul_Total fills payment.amount only on the paid
    // branch, so on every other payment status it reached the server as the
    // string "NaN" and was refused with validation.numeric -- reported on
    // screen only as "Invalid Data !!". That made it impossible to record a
    // sale the customer had not yet paid for, which is most of the contractor
    // trade.
    safeAmount(value) {
      const n = parseFloat(value);
      return (isNaN(n) ? 0 : n).toFixed(2);
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

    //-----------------------------------------Calcul Total ------------------------------\\
    Calcul_Total() {
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
      this.GrandTotal = parseFloat(
        total_without_discount + this.sale.TaxNet + this.sale.shipping
      );

      var grand_total =  this.GrandTotal.toFixed(2);
      this.GrandTotal = parseFloat(grand_total);

      if(this.payment.status == 'paid'){
          this.payment.amount = this.formatNumber(this.GrandTotal, 2);
      }

    },

    //-----------------------------------Delete Detail Product ------------------------------\\
    delete_Product_Detail(id) {
      for (var i = 0; i < this.details.length; i++) {
        if (id === this.details[i].detail_id) {
          this.details.splice(i, 1);
          this.Calcul_Total();
        }
      }
    },

    //-----------------------------------verified Order List ------------------------------\\

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
        // A completed sale takes named units off the shelf.
        const serialProblem = this.sale.statut == "completed" ? firstSerialProblem(this.details) : null;
        if (serialProblem) {
          this.makeToast("warning", serialProblem, this.$t("Warning"));
          return false;
        }
        return true;
      }
    },

    //---------- keyup OrderTax
    keyup_OrderTax() {
      if (isNaN(this.sale.tax_rate)) {
        this.sale.tax_rate = 0;
      } else {
        this.Calcul_Total();
      }
    },

    //---------- keyup Discount

    keyup_Discount() {
      if (isNaN(this.sale.discount)) {
        this.sale.discount = 0;
      } else {
        this.Calcul_Total();
      }
    },

    //---------- keyup Shipping

    keyup_Shipping() {
      if (isNaN(this.sale.shipping)) {
        this.sale.shipping = 0;
      } else {
        this.Calcul_Total();
      }
    },

    //--------------------------------- Create Sale -------------------------\\
    Create_Sale() {
      if (this.verifiedForm()) {
        // Start the progress bar.
        NProgress.start();
        NProgress.set(0.1);
        {
          this.paymentProcessing = true;
          axios
            .post("sales", {
              date: this.sale.date,
              client_id: this.sale.client_id,
              warehouse_id: this.sale.warehouse_id,
              statut: this.sale.statut,
              notes: this.sale.notes,
              tax_rate: this.sale.tax_rate,
              TaxNet: this.sale.TaxNet,
              discount: this.sale.discount,
              shipping: this.sale.shipping,
              GrandTotal: this.GrandTotal,
              details: this.details,
              payment: this.payment,
              amount: this.safeAmount(this.payment.amount),
              received_amount: this.safeAmount(this.payment.received_amount),
              change: parseFloat(this.payment.received_amount - this.payment.amount).toFixed(2),
            })
            .then(response => {
              this.makeToast(
                "success",
                this.$t("Create.TitleSale"),
                this.$t("Success")
              );
              NProgress.done();
              this.paymentProcessing = false;
              // Xendit: the sale is saved unpaid; now ask the customer for the money.
              if (this.payment.status != "pending" && this.payment.Reglement === "Xendit" && this.safeAmount(this.payment.amount) > 0) {
                this.$refs.xenditCheckout.open(response.data.id, this.safeAmount(this.payment.amount));
                return;
              }
              this.$router.push({ name: "index_sales" });
            })
            .catch(error => {
              NProgress.done();
              this.paymentProcessing = false;
              this.makeToast(
                "danger",
                this.serverMessage(error),
                this.$t("Failed")
              );
            });
        }
      }
    },

    //-------------------------------- Get Last Detail Id -------------------------\\
    Last_Detail_id() {
      this.product.detail_id = 0;
      var len = this.details.length;
      this.product.detail_id = this.details[len - 1].detail_id + 1;
    },

    //---------------------------------Get Product Details ------------------------\\

    Get_Product_Details(product_id) {
      axios.get("Products/" + product_id).then(response => {
        this.product.discount = 0;
        this.product.DiscountNet = 0;
        this.product.discount_Method = "2";
        this.product.product_id = response.data.id;
        this.product.name = response.data.name;
        this.product.Net_price = response.data.Net_price;
        this.product.Unit_price = response.data.Unit_price;
        this.product.taxe = response.data.tax_price;
        this.product.tax_method = response.data.tax_method;
        this.product.tax_percent = response.data.tax_percent;
        this.product.unitSale = response.data.unitSale;
        this.product.fix_price = response.data.fix_price;
        this.product.sale_unit_id = response.data.sale_unit_id;
        this.product.unit_factor = response.data.sale_unit_factor || 1;
        this.add_product();
        this.Calcul_Total();
      });
    },

    //---------------------------------------Get Elements ------------------------------\\
    GetElements() {
      axios
        .get("sales/create")
        .then(response => {
          this.clients = response.data.clients;
          this.warehouses = response.data.warehouses;
          // The user's only warehouse (or the shop default they may use) is preselected.
          const start = startingWarehouse(this.warehouses, this.sale.warehouse_id);
          if (start) {
            this.sale.warehouse_id = start;
            this.Selected_Warehouse(start);
          }
          this.xendit_ready = !!response.data.xendit_ready;
          this.isLoading = false;
        })
        .catch(response => {
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    }
  },

  //----------------------------- Created function-------------------
  created() {
    this.GetElements();
  }
};
</script>
