<template>
  <div class="main-content">
    <breadcumb :page="'Update Product'" :folder="$t('Products')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <validation-observer ref="Edit_Product" v-if="!isLoading">
      <b-form @submit.prevent="Submit_Product" enctype="multipart/form-data">
        <b-alert v-if="validationError" show variant="danger" role="alert">Check the highlighted product fields below, then save again.</b-alert>
        <b-row>
          <b-col md="8">
            <b-card>
              <b-card-body>
                <b-row>
                  <!-- name -->
                  <b-col cols="12"><h5 class="product-section-heading">Product identity</h5></b-col>
                  <b-col md="6" class="mb-2">
                    <validation-provider
                      name="name"
                      :rules="{required:true , min:3 , max:55}"
                      v-slot="validationContext"
                    >
                      <b-form-group :label="$t('Name_product')" label-for="edit-product-name">
                        <b-form-input
                          id="edit-product-name"
                          :state="getValidationState(validationContext)"
                          aria-describedby="name-feedback"
                          label="name"
                          :placeholder="$t('Enter_Name_Product')"
                          v-model="product.name"
                        ></b-form-input>
                        <b-form-invalid-feedback
                          id="name-feedback"
                        >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                  <!-- Code Product"-->
                  <b-col md="6" class="mb-2">
                    <validation-provider
                      name="Code Product"
                      :rules="{ required: true}"
                      v-slot="validationContext"
                    >
                      <b-form-group label="Product code" label-for="edit-product-code">
                        <div class="input-group">
                          <input
                            id="edit-product-code"
                            :state="getValidationState(validationContext)"
                            aria-describedby="CodeProduct-feedback"
                            type="text"
                            class="form-control"
                            v-model="product.code"
                          >

                          <b-form-invalid-feedback
                            id="CodeProduct-feedback"
                          >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                        </div>
                        <span>{{$t('Scan_your_barcode_and_select_the_correct_symbology_below')}}</span>
                          <b-alert
                            show
                            variant="danger"
                            class="error mt-1"
                            v-if="code_exist !=''"
                          >{{code_exist}}</b-alert>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                  <!-- Category -->
                  <b-col md="6" class="mb-2">
                    <validation-provider name="category" :rules="{ required: true}">
                      <b-form-group slot-scope="{ valid, errors }" :label="$t('Categorie')" label-for="edit-product-category">
                        <v-select
                          input-id="edit-product-category" :aria-label="$t('Categorie')"
                          :class="{'is-invalid': !!errors.length}"
                          :state="errors[0] ? false : (valid ? true : null)"
                          :reduce="label => label.value"
                          :placeholder="$t('Choose_Category')"
                          v-model="product.category_id"
                          :options="categories.map(categories => ({label: categories.name, value: categories.id}))"
                        />
                        <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                  <!-- Brand  -->
                  <b-col md="6" class="mb-2">
                    <b-form-group :label="$t('Brand')" label-for="edit-product-brand">
                      <v-select
                        input-id="edit-product-brand" :aria-label="$t('Brand')"
                        :placeholder="$t('Choose_Brand')"
                        :reduce="label => label.value"
                        v-model="product.brand_id"
                        :options="brands.map(brands => ({label: brands.name, value: brands.id}))"
                      />
                    </b-form-group>
                  </b-col>

                  <!-- Barcode Symbology  -->
                  <b-col md="6" class="mb-2">
                    <validation-provider name="Barcode Symbology" :rules="{ required: true}">
                      <b-form-group slot-scope="{ valid, errors }" :label="$t('BarcodeSymbology')" label-for="edit-product-barcode-type">
                        <v-select
                          input-id="edit-product-barcode-type" :aria-label="$t('BarcodeSymbology')"
                          :class="{'is-invalid': !!errors.length}"
                          :state="errors[0] ? false : (valid ? true : null)"
                          v-model="product.Type_barcode"
                          :reduce="label => label.value"
                          :placeholder="$t('Choose_Symbology')"
                          :options="
                              [
                                {label: 'Code 128', value: 'CODE128'},

                              ]"
                        ></v-select>
                        <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                  <!-- Product Cost -->
                  <b-col cols="12"><h5 class="product-section-heading">Pricing</h5><p class="text-muted small">Purchase cost and selling price are separate values.</p></b-col>
                  <b-col md="6" class="mb-2">
                    <validation-provider
                      name="Product Cost"
                      :rules="{ required: true , regex: /^\d*\.?\d*$/}"
                      v-slot="validationContext"
                    >
                      <b-form-group label="Purchase cost" label-for="edit-product-cost">
                        <b-form-input
                          id="edit-product-cost"
                          :state="getValidationState(validationContext)"
                          aria-describedby="ProductCost-feedback"
                          label="Cost"
                          :placeholder="$t('Enter_Product_Cost')"
                          v-model="product.cost"
                        ></b-form-input>
                        <b-form-invalid-feedback
                          id="ProductCost-feedback"
                        >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                  <!-- Product Price -->
                  <b-col md="6" class="mb-2">
                    <validation-provider
                      name="Product Price"
                      :rules="{ required: true , regex: /^\d*\.?\d*$/}"
                      v-slot="validationContext"
                    >
                      <b-form-group label="Selling price" label-for="edit-product-price">
                        <b-form-input
                          id="edit-product-price"
                          :state="getValidationState(validationContext)"
                          aria-describedby="ProductPrice-feedback"
                          label="Price"
                          :placeholder="$t('Enter_Product_Price')"
                          v-model="product.price"
                        ></b-form-input>

                        <b-form-invalid-feedback
                          id="ProductPrice-feedback"
                        >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                  <!-- Unit Product -->
                  <b-col cols="12"><h5 class="product-section-heading">Units and stock</h5></b-col>
                  <b-col md="6" class="mb-2">
                    <validation-provider name="Unit Product" :rules="{ required: true}">
                      <b-form-group slot-scope="{ valid, errors }" :label="$t('UnitProduct')" label-for="edit-product-unit">
                        <v-select
                          input-id="edit-product-unit" :aria-label="$t('UnitProduct')"
                          :class="{'is-invalid': !!errors.length}"
                          :state="errors[0] ? false : (valid ? true : null)"
                          v-model="product.unit_id"
                          class="required"
                          required
                          @input="Selected_Unit"
                          :placeholder="$t('Choose_Unit_Product')"
                          :reduce="label => label.value"
                          :options="units.map(units => ({label: units.name, value: units.id}))"
                        />
                        <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                  <!-- Unit Sale -->
                  <b-col md="6" class="mb-2">
                    <validation-provider name="Unit Sale" :rules="{ required: true}">
                      <b-form-group slot-scope="{ valid, errors }" :label="$t('UnitSale')" label-for="edit-product-sale-unit">
                        <v-select
                          input-id="edit-product-sale-unit" :aria-label="$t('UnitSale')"
                          :class="{'is-invalid': !!errors.length}"
                          :state="errors[0] ? false : (valid ? true : null)"
                          v-model="product.unit_sale_id"
                          :placeholder="$t('Choose_Unit_Sale')"
                          :reduce="label => label.value"
                          :options="units_sub.map(units_sub => ({label: units_sub.name, value: units_sub.id}))"
                        />
                        <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                  <!-- Unit Purchase -->
                  <b-col md="6" class="mb-2">
                    <validation-provider name="Unit Purchase" :rules="{ required: true}">
                      <b-form-group slot-scope="{ valid, errors }" :label="$t('UnitPurchase')" label-for="edit-product-purchase-unit">
                        <v-select
                          input-id="edit-product-purchase-unit" :aria-label="$t('UnitPurchase')"
                          :class="{'is-invalid': !!errors.length}"
                          :state="errors[0] ? false : (valid ? true : null)"
                          v-model="product.unit_purchase_id"
                          :placeholder="$t('Choose_Unit_Purchase')"
                          :reduce="label => label.value"
                          :options="units_sub.map(units_sub => ({label: units_sub.name, value: units_sub.id}))"
                        />
                        <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                  <!-- Stock Alert -->
                  <b-col md="6" class="mb-2">
                    <validation-provider
                      name="Stock Alert"
                      :rules="{ regex: /^\d*\.?\d*$/}"
                      v-slot="validationContext"
                    >
                      <b-form-group :label="$t('StockAlert')" label-for="edit-product-stock-alert">
                        <b-form-input
                          id="edit-product-stock-alert"
                          :state="getValidationState(validationContext)"
                          aria-describedby="StockAlert-feedback"
                          label="Stock alert"
                          :placeholder="$t('Enter_Stock_alert')"
                          v-model="product.stock_alert"
                        ></b-form-input>
                        <b-form-invalid-feedback
                          id="StockAlert-feedback"
                        >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                  <!-- Batch tracking. It cannot be switched on while untracked
                       stock is already on the shelf; the server says why. -->
                  <b-col cols="12">
                    <details class="product-advanced-options" :open="advancedOpen" @toggle="advancedOpen = $event.target.open">
                      <summary>Advanced options <small class="text-muted">Tax, tracking, online shop and notes</small></summary>
                      <b-row class="mt-3">
                  <b-col cols="12"><h5 class="product-section-heading">Tax and discounts</h5></b-col>
                  <!-- Philippine VAT classification. A 0% rate and a VAT-exempt
                       sale are the same number but different filings, so this is
                       a classification rather than another rate. -->
                  <b-col md="6">
                    <b-form-group :label="$t('VatClassification')" label-for="edit-product-vat-class">
                      <b-form-select id="edit-product-vat-class" v-model="product.vat_class">
                        <option value="vatable">{{ $t('Vatable') }}</option>
                        <option value="vat_exempt">{{ $t('VatExempt') }}</option>
                        <option value="zero_rated">{{ $t('ZeroRated') }}</option>
                      </b-form-select>
                    </b-form-group>
                  </b-col>

                  <!-- Alcohol and tobacco are excluded from the statutory
                       discount even when a senior buys them. -->
                  <b-col md="6">
                    <b-form-group :label="$t('StatutoryDiscount')">
                      <b-form-checkbox v-model="product.sc_pwd_eligible" switch>
                        {{ $t('EligibleForScPwdDiscount') }}
                      </b-form-checkbox>
                    </b-form-group>
                  </b-col>

                  <b-col cols="12"><h5 class="product-section-heading">Tracking and availability</h5></b-col>
                  <b-col md="6" v-if="!product.is_service">
                    <b-form-group :label="$t('BatchTracking')">
                      <b-form-checkbox v-model="product.has_batches" switch :disabled="product.has_serials">
                        {{ $t('TrackBatchesAndExpiry') }}
                      </b-form-checkbox>
                    </b-form-group>
                  </b-col>

                  <b-col md="6" v-if="!product.is_service">
                    <b-form-group label="Quality inspection">
                      <b-form-checkbox v-model="product.requires_qc" switch>
                        Hold new receipts until a QC approver releases them
                      </b-form-checkbox>
                    </b-form-group>
                  </b-col>
                  <b-col md="6" v-if="!product.is_service">
                    <b-form-group label="Online storefront">
                      <b-form-checkbox v-model="product.web_published" switch>
                        Show this product on the public shop after the shop is enabled
                      </b-form-checkbox>
                    </b-form-group>
                  </b-col>

                  <!-- Goods or a service; the server refuses the change while
                       any stock is on hand. -->
                  <b-col md="6">
                    <b-form-group :label="$t('ProductKind')" label-for="edit-product-kind" :description="product.is_service ? $t('ServiceHelp') : ''">
                      <b-form-select id="edit-product-kind" v-model="product.is_service">
                        <option :value="false">{{ $t('KindGoods') }}</option>
                        <option :value="true">{{ $t('KindService') }}</option>
                      </b-form-select>
                    </b-form-group>
                  </b-col>

                  <b-col md="6" v-if="!product.is_service">
                    <b-form-group :label="$t('SerialNumbers')" :description="$t('TrackSerialsHelp')">
                      <b-form-checkbox v-model="product.has_serials" switch :disabled="product.has_batches">
                        {{ $t('TrackSerials') }}
                      </b-form-checkbox>
                    </b-form-group>
                  </b-col>

                  <b-col md="6" v-if="!product.is_service">
                    <b-form-group :label="$t('WarrantyMonths')" label-for="edit-product-warranty" :description="$t('WarrantyMonthsHelp')">
                      <b-form-input id="edit-product-warranty" type="number" min="1" max="600" v-model="product.warranty_months"></b-form-input>
                    </b-form-group>
                  </b-col>

                  <b-col md="6" v-if="product.has_batches && !product.is_service">
                    <b-form-group :label="$t('ExpiryAlertDays')" label-for="edit-product-expiry-days">
                      <b-form-input id="edit-product-expiry-days" type="number" min="0" v-model="product.expiry_alert_days"></b-form-input>
                    </b-form-group>
                  </b-col>



                  <b-col md="12" class="mb-2">
                    <h5 class="product-section-heading">Notes</h5>
                    <b-form-group :label="$t('Note')" label-for="edit-product-note">
                      <textarea
                        id="edit-product-note"
                        rows="4"
                        class="form-control"
                        :placeholder="$t('Afewwords')"
                        v-model="product.note"
                      ></textarea>
                    </b-form-group>
                  </b-col>
                      </b-row>
                    </details>
                  </b-col>
                </b-row>
              </b-card-body>
            </b-card>
          </b-col>
          <b-col md="4">
            <b-card>
              <div class="card-header">
                <h5>Product images</h5>
              </div>
              <div class="card-body">
                <b-row class="form-group">
                  <b-col md="12 mb-5">
                    <div
                      id="my-strictly-unique-vue-upload-multiple-image"
                      class="d-flex justify-content-center"
                    >
                      <vue-upload-multiple-image
                      @upload-success="uploadImageSuccess"
                      @before-remove="beforeRemove"
                      dragText="Drag & Drop Multiple images For product"
                      dropText="Drag & Drop image"
                      browseText="(or) Select"
                      accept=image/gif,image/jpeg,image/png,image/bmp,image/jpg
                      primaryText='success'
                      markIsPrimaryText='success'
                      popupText='have been successfully uploaded'
                      :data-images="images"
                      idUpload="myIdUpload"
                      :showEdit="false"
                      />
                    </div>
                  </b-col>

                </b-row>
              </div>
            </b-card>
          </b-col>
          <b-col md="12" class="mt-3 product-save-bar">
             <router-link to="/app/products/list" class="btn btn-outline-secondary mr-2">Cancel</router-link>
             <b-button variant="primary" type="submit" :disabled="SubmitProcessing">Save changes</b-button>
              <div class="typo__p" v-if="SubmitProcessing">
                <div class="spinner sm spinner-primary mt-3"></div>
              </div>
          </b-col>
        </b-row>
      </b-form>
    </validation-observer>
  </div>
</template>

<script>
import VueUploadMultipleImage from "vue-upload-multiple-image";
import VueTagsInput from "@johmun/vue-tags-input";
import NProgress from "nprogress";

export default {
  metaInfo: {
    title: "Edit Product"
  },
  data() {
    return {
      tag: "",
      len: 8,
      images: [],
      imageArray: [],
      change: false,
      isLoading: true,
      SubmitProcessing:false,
      validationError: false,
      advancedOpen: false,
      data: new FormData(),
      categories: [],
      Subcategories: [],
      units: [],
      units_sub: [],
      brands: [],
      roles: {},
      variants: [],
      product: {
        name: "",
        code: "",
        Type_barcode: "",
        cost: "",
        price: "",
        brand_id: "",
        category_id: "",
        TaxNet: "",
        tax_method: "1",
        unit_id: "",
        unit_sale_id: "",
        unit_purchase_id: "",
        stock_alert: "",
        has_batches: false,
        requires_qc: false,
        web_published: false,
        expiry_alert_days: 30,
        is_service: false,
        has_serials: false,
        warranty_months: "",
        vat_class: "vatable",
        sc_pwd_eligible: true,
        image: "",
        note: "",
        is_variant: false
      },
      code_exist: ""
    };
  },

  components: {
    VueUploadMultipleImage,
    VueTagsInput
  },

  methods: {
    //------------- Submit Validation Update Product
    Submit_Product() {
      this.validationError = false;
      this.$refs.Edit_Product.validate().then(success => {
        if (!success) {
          this.validationError = true;
          this.advancedOpen = true;
          this.$nextTick(() => {
            const invalid = this.$el.querySelector('[aria-invalid="true"], .is-invalid');
            if (invalid && invalid.focus) invalid.focus();
          });
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Update_Product();
        }
      });
    },

    //------ Validation state fields
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

    //------Show Notification If Variant is Duplicate
    showNotifDuplicate() {
      this.makeToast(
        "warning",
        this.$t("VariantDuplicate"),
        this.$t("Warning")
      );
    },



    //------ event upload Image Success
    uploadImageSuccess(formData, index, fileList, imageArray) {
      this.images = fileList;
    },

    //------ event before Remove image
    beforeRemove(index, done, fileList) {
      var remove = confirm("remove image");
      if (remove == true) {
        this.images.splice(index, 1);
        done();
      } else {
      }
    },

    //---------------------------------------Get Product Elements ------------------------------\\
    GetElements() {
      let id = this.$route.params.id;
      axios
        .get(`Products/${id}/edit`)
        .then(response => {
          this.product = response.data.product;
          this.variants = response.data.product.ProductVariant;
          this.images = response.data.product.images;
          this.categories = response.data.categories;
          this.brands = response.data.brands;
          this.units = response.data.units;
          this.units_sub = response.data.units_sub;
          this.Subcategories = response.data.Subcategories;

          this.isLoading = false;
        })
        .catch(response => {
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    //---------------------- Get Sub Units with Unit id ------------------------------\\
    Get_Units_SubBase(value) {
      axios
        .get("Get_Units_SubBase?id=" + value)
        .then(({ data }) => (this.units_sub = data));
    },

    //---------------------- Event Select Unit Product ------------------------------\\
    Selected_Unit(value) {
      this.units_sub = [];
      this.product.unit_sale_id = "";
      this.product.unit_purchase_id = "";
      this.Get_Units_SubBase(value);
    },

    //------------------------------ Update Product ------------------------------\\
    Update_Product() {
      NProgress.start();
      NProgress.set(0.1);
      var self = this;
      self.SubmitProcessing = true;
      if (self.product.is_variant && self.variants.length <= 0) {
        self.product.is_variant = false;
      }

      // append objet product
      Object.entries(self.product).forEach(([key, value]) => {
        self.data.append(key, value);
      });

      //append array variants
      if (self.variants.length) {
        for (var i = 0; i < self.variants.length; i++) {
          Object.entries(self.variants[i]).forEach(([key, value]) => {
            self.data.append("variants[" + i + "][" + key + "]", value);
          });
        }
      }

      //append array images
      if (self.images.length > 0) {
        for (var k = 0; k < self.images.length; k++) {
          Object.entries(self.images[k]).forEach(([key, value]) => {
            self.data.append("images[" + k + "][" + key + "]", value);
          });
        }
      }

      self.data.append("_method", "put");

      //send Data with axios
      axios
        .post("Products/" + this.product.id, self.data)
        .then(response => {
          NProgress.done();
          self.SubmitProcessing = false;
          this.$router.push({ name: "index_products" });
          this.makeToast(
            "success",
            this.$t("Successfully_Updated"),
            this.$t("Success")
          );
        })
        .catch(error => {
          // The interceptor in main.js rejects with the response body, which
          // only carries errors.code when the product code itself was refused.
          // Reading .code unguarded threw inside this handler, so the makeToast
          // below never ran and a failed save showed nothing at all.
          const body = (error && error.response && error.response.data) || error || {};
          const errors = body.errors || {};
          if (Array.isArray(errors.code) && errors.code.length > 0) {
            self.code_exist = errors.code[0];
          }
          NProgress.done();
          // Say what the server actually refused, rather than one fixed line.
          let message = this.$t("InvalidData");
          if (typeof body === "string") {
            message = body;
          } else {
            const firstKey = Object.keys(errors)[0];
            const first = firstKey && errors[firstKey];
            if (Array.isArray(first) && first.length) message = first[0];
            else if (typeof first === "string") message = first;
            else if (typeof body.message === "string" && body.message) message = body.message;
          }
          this.makeToast("danger", message, this.$t("Failed"));
          self.SubmitProcessing = false;
        });
    }
  }, //end Methods

  //-----------------------------Created function-------------------

  created: function() {
    this.GetElements();
    this.imageArray = [];
    this.images = [];
  }
};
</script>
<style scoped>
.product-section-heading { margin: 1.25rem 0 .75rem; padding-bottom: .45rem; border-bottom: 1px solid #e2e8e7; color: #0b6f52; font-size: 1rem; font-weight: 700; }
.product-advanced-options { margin-top: 1rem; border-top: 1px solid #e2e8e7; }
.product-advanced-options summary { padding: 1rem 0; cursor: pointer; color: #0b6f52; font-weight: 700; }
.product-advanced-options summary:focus-visible { outline: 2px solid #0b6f52; outline-offset: 2px; }
.product-advanced-options summary small { display: block; font-weight: 400; }
.product-save-bar { position: sticky; bottom: 0; z-index: 5; padding: 1rem; background: #fff; border-top: 1px solid #dce6e2; box-shadow: 0 -5px 16px rgba(0,0,0,.06); }
</style>
