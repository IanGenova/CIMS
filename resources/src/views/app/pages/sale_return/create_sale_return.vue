<template>
  <div class="main-content">
    <breadcumb :page="$t('CreateSaleReturn')" :folder="$t('ListReturns')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <validation-observer ref="create_sale_return" v-if="!isLoading">
      <b-form @submit.prevent="Submit_sale_return">
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
                        v-model="sale_return.date"
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
                        v-model="sale_return.client_id"
                        @input="Load_Client_Sales"
                        :reduce="label => label.value"
                        :placeholder="$t('Choose_Customer')"
                        :options="clients.map(clients => ({label: clients.name, value: clients.id}))"
                      />
                      <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <!-- The sale the goods come back from: the server caps each
                     line at what that sale sold less what already came back. -->
                <b-col lg="4" md="4" sm="12" class="mb-3">
                  <validation-provider name="Sale" :rules="{ required: true}">
                    <b-form-group slot-scope="{ valid, errors }" :label="$t('ReturnFromSale')">
                      <v-select
                        :class="{'is-invalid': !!errors.length}"
                        :state="errors[0] ? false : (valid ? true : null)"
                        v-model="sale_return.sale_id"
                        @input="Selected_Sale"
                        :disabled="!sale_return.client_id"
                        :reduce="label => label.value"
                        :placeholder="$t('ChooseSaleToReturn')"
                        :options="client_sales.map(s => ({label: s.Ref + ' · ' + s.date + ' · ' + formatNumber(s.GrandTotal, 2), value: s.id}))"
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
                        v-model="sale_return.warehouse_id"
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

                <!-- details product  -->
                <b-col md="12">
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

                          <th scope="col">{{$t('BatchNumber')}}</th>
                          <th scope="col">{{$t('SubTotal')}}</th>
                          <th scope="col" class="text-center">
                            <i class="fa fa-trash"></i>
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
                            <br v-if="detail.has_serials && sale_return.statut == 'received'">
                            <serial-entry
                              v-if="detail.has_serials && sale_return.statut == 'received'"
                              v-model="detail.serials"
                              mode="enter"
                              :product-id="detail.product_id"
                              :variant-id="detail.product_variant_id"
                              :warehouse-id="sale_return.warehouse_id"
                              :units="detail.quantity * (detail.unit_factor || 1)"
                              :name="detail.name"
                            ></serial-entry>
                          </td>
                          <td>{{currentUser.currency}} {{formatNumber(detail.Net_price, 3)}}</td>
                          <td>
                            <span
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

                          <td>
                            <b-form-input
                              v-if="detail.has_batches"
                              size="sm"
                              v-model="detail.batch_number"
                              :placeholder="$t('BatchNumber') + ' *'"
                            />
                            <span v-else class="text-muted">—</span>
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
                        v-model="sale_return.statut"
                        :reduce="label => label.value"
                        :placeholder="$t('Choose_Status')"
                        :options="
                                [
                                  {label: 'Received', value: 'received'},
                                  {label: 'Pending', value: 'pending'}
                                ]"
                      ></v-select>
                      <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col md="12">
                  <b-form-group :label="$t('Note')">
                    <textarea
                      v-model="sale_return.notes"
                      rows="4"
                      class="form-control"
                      :placeholder="$t('Afewwords')"
                    ></textarea>
                  </b-form-group>
                </b-col>
                <b-col md="12">
                  <b-form-group>
                    <b-button variant="primary" @click="Submit_sale_return" :disabled="SubmitProcessing">{{$t('submit')}}</b-button>
                     <div v-once class="typo__p" v-if="SubmitProcessing">
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

    <!-- Modal Update Detail Product -->
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
                    v-model.number="detail.Unit_price"
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
                <b-button variant="primary" type="submit">{{$t('submit')}}</b-button>
              </b-form-group>
            </b-col>
          </b-row>
        </b-form>
      </b-modal>
    </validation-observer>
  </div>
</template>

<script>
import { mapActions, mapGetters } from "vuex";
import { repriceForUnit } from "../../../../utils/unitPrice";
import { startingWarehouse } from "../../../../utils/defaultWarehouse";
import { firstSerialProblem, unitFactor } from "../../../../utils/serials";
import NProgress from "nprogress";

export default {
  metaInfo: {
    title: "Create Sale Return"
  },
  data() {
    return {
      focused: false,
      timer:null,
      search_input:'',
      product_filter:[],
      isLoading: true,
      SubmitProcessing:false,
      warehouses: [],
      units: [],
      clients: [],
      products: [],
      details: [],
      detail: {},
      taxes: [],
      sale_return: {
        id: "",
        date: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10),
        statut: "received",
        notes: "",
        client_id: "",
        sale_id: "",
        warehouse_id: "",
        tax_rate: 0,
        TaxNet: 0,
        shipping: 0,
        discount: 0
      },
      client_sales: [],
      total: 0,
      GrandTotal: 0,
      product: {
        id: "",
        code: "",
        stock: "",
        quantity: 1,
        discount: "",
        DiscountNet: "",
        discount_Method: "",
        sale_unit_id:"",
        fix_stock:"",
        fix_price:"",
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
        product_variant_id: "",
        has_batches: false,
        batch_number: ""
      }
    };
  },

  computed: {
    ...mapGetters(["currentUser"])
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

    //--- Submit Validate Create Sale Return
    Submit_sale_return() {
      this.$refs.create_sale_return.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.create_sale_return();
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


          if (this.detail.stock < this.detail.quantity) {
            this.details[i].quantity = this.detail.stock;
            } else {
              this.details[i].quantity =1;
            }

            this.details[i].sale_unit_id = this.detail.sale_unit_id;

          // this.convert_unit();
           for(var k=0; k<this.units.length; k++){
              if (this.units[k].id === this.detail.sale_unit_id) {
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

          this.details[i].Unit_price = this.detail.Unit_price;
          this.details[i].tax_percent = this.detail.tax_percent;
          this.details[i].tax_method = this.detail.tax_method;
          this.details[i].discount_Method = this.detail.discount_Method;
          this.details[i].discount = this.detail.discount;
          // this.details[i].sale_unit_id = this.detail.sale_unit_id;

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
      if (this.sale_return.warehouse_id != "" &&  this.sale_return.warehouse_id != null) {
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

    //------ get Result Value Search Product

    getResultValue(result) {
      return result.code + " " + "(" + result.name + ")";
    },

    //------ Submit Search Product

    SearchProduct(result) {
      this.product = {};
      if (
        this.details.length > 0 &&
        this.details.some(detail => detail.code === result.code)
      ) {
        this.makeToast("warning", this.$t("AlreadyAdd"), this.$t("Warning"));
      } else {
        this.product.code = result.code;
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
        this.product.has_batches = !!result.has_batches;
        this.product.batch_number = "";
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
        .get("Products/Warehouse/" + id + "?stock=" + 0)
         .then(response => {
            this.products = response.data;
             NProgress.done();

            })
          .catch(error => {
          });
    },

    //----------------------------------------- Add Product -------------------------\\
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
        if (this.details[i].detail_id == id) {
          if (isNaN(detail.quantity)) {
            this.details[i].quantity = 1;
          }
          this.Calcul_Total();
          this.$forceUpdate();
        }
      }
    },

    //-----------------------------------increment QTY ------------------------------\\

    increment(detail, id) {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id == id) {
          this.formatNumber(this.details[i].quantity++, 2);
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
            this.formatNumber(this.details[i].quantity--, 2);
          }
        }
      }
      this.$forceUpdate();
      this.Calcul_Total();
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
        this.total - this.sale_return.discount
      );
      this.sale_return.TaxNet = parseFloat(
        (total_without_discount * this.sale_return.tax_rate) / 100
      );
      this.GrandTotal = parseFloat(
        total_without_discount +
          this.sale_return.TaxNet +
          this.sale_return.shipping
      );

      var grand_total =  this.GrandTotal.toFixed(2);
      this.GrandTotal = parseFloat(grand_total);
    },

    //---------- keyup OrderTax
    keyup_OrderTax() {
      if (isNaN(this.sale_return.tax_rate)) {
        this.sale_return.tax_rate = 0;
      } else {
        this.Calcul_Total();
      }
    },

    //---------- keyup Discount

    keyup_Discount() {
      if (isNaN(this.sale_return.discount)) {
        this.sale_return.discount = 0;
      } else {
        this.Calcul_Total();
      }
    },

    //---------- keyup Shipping

    keyup_Shipping() {
      if (isNaN(this.sale_return.shipping)) {
        this.sale_return.shipping = 0;
      } else {
        this.Calcul_Total();
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

    //-----------------------------------verified Qty If Null || 0 ------------------------------\\

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
        // Units coming back are named.
        const serialProblem = this.sale_return.statut == 'received' ? firstSerialProblem(this.details) : null;
        if (serialProblem) {
          this.makeToast("warning", serialProblem, this.$t("Warning"));
          return false;
        }
        return true;
      }
    },

    //------ The customer's completed sales, to pick the one goods come back from
    Load_Client_Sales(clientId) {
      this.sale_return.sale_id = "";
      this.client_sales = [];
      if (!clientId) {
        return;
      }
      axios
        .get("returns/sale/for_client", { params: { client_id: clientId } })
        .then(({ data }) => (this.client_sales = data.sales || []))
        .catch(() => (this.client_sales = []));
    },

    //------ Goods come back to the branch that sold them, unless lines are already added
    Selected_Sale(saleId) {
      const sale = this.client_sales.find(s => s.id === saleId);
      if (sale && !this.details.length && sale.warehouse_id && sale.warehouse_id !== this.sale_return.warehouse_id) {
        this.sale_return.warehouse_id = sale.warehouse_id;
        this.Selected_Warehouse(sale.warehouse_id);
      }
    },

    //--------------------------------- Create Sale Return -------------------------\\
    create_sale_return() {
      if (this.verifiedForm()) {
        this.SubmitProcessing = true;
        NProgress.start();
        NProgress.set(0.1);
        axios
          .post("returns/sale", {
            date: this.sale_return.date,
            client_id: this.sale_return.client_id,
            sale_id: this.sale_return.sale_id,
            warehouse_id: this.sale_return.warehouse_id,
            statut: this.sale_return.statut,
            notes: this.sale_return.notes,
            tax_rate: this.sale_return.tax_rate,
            TaxNet: this.sale_return.TaxNet,
            discount: this.sale_return.discount,
            shipping: this.sale_return.shipping,
            GrandTotal: this.GrandTotal,
            details: this.details
          })
          .then(response => {
            NProgress.done();
            this.makeToast(
              "success",
              this.$t("Create.TitleReturn"),
              this.$t("Success")
            );

             this.SubmitProcessing = false;
            this.$router.push({ name: "index_sale_return" });
          })
          .catch(error => {
            NProgress.done();
            // The server's own reason (a serial not on this shelf, stock too low).
            const data = error.response && error.response.data;
            const first = data && data.errors ? Object.values(data.errors)[0] : null;
            this.makeToast("danger", (first && first[0]) || (data && data.message) || this.$t("InvalidData"), this.$t("Failed"));
             this.SubmitProcessing = false;
          });
      }
    },

    //-------------------------------- Get Last Detail Id -------------------------\\
    Last_Detail_id() {
      this.product.detail_id = 0;
      var len = this.details.length;
      this.product.detail_id = this.details[len - 1].detail_id + 1;
    },

    //--------------------------------- Get Product Details ------------------------\\

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

    //--------------------------------------- Get Elements ------------------------------\\
    GetElements() {
      axios
        .get("returns/sale/create")
        .then(response => {
          this.clients = response.data.clients;
          this.warehouses = response.data.warehouses;
          // The user's only warehouse (or the shop default they may use) is preselected.
          const start = startingWarehouse(this.warehouses, this.sale_return.warehouse_id);
          if (start) {
            this.sale_return.warehouse_id = start;
            this.Selected_Warehouse(start);
          }
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
