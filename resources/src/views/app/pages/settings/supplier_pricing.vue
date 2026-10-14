<template>
  <div class="main-content">
    <breadcumb :page="$t('SupplierPricing')" :folder="$t('Settings')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-card>
        <div class="d-flex justify-content-between align-items-center mb-3">
          <p class="text-muted mb-0">{{ $t('SupplierPricingIntro') }}</p>
          <b-button variant="primary" size="sm" @click="Open_Create">
            <i class="i-Add"></i> {{ $t('Add') }}
          </b-button>
        </div>

        <b-row class="mb-3">
          <b-col md="4">
            <b-form-input v-model="search" @input="Get_Offers" :placeholder="$t('Search')"/>
          </b-col>
          <b-col md="4">
            <v-select
              @input="Get_Offers"
              v-model="provider_id"
              :reduce="label => label.value"
              :placeholder="$t('AllSuppliers')"
              :options="suppliers.map(s => ({label: s.name, value: s.id}))"
            />
          </b-col>
        </b-row>

        <div v-if="!offers.length" class="text-center text-muted py-4">
          {{ $t('NoSupplierOffersYet') }}
        </div>

        <div v-else class="table-responsive">
          <table class="table table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('ProductCode') }}</th>
                <th>{{ $t('ProductName') }}</th>
                <th>{{ $t('Supplier') }}</th>
                <th>{{ $t('SupplierSKU') }}</th>
                <th class="text-right">{{ $t('Cost') }}</th>
                <th class="text-right">{{ $t('LeadTimeDays') }}</th>
                <th class="text-right">{{ $t('MinOrderQty') }}</th>
                <th class="text-center">{{ $t('Preferred') }}</th>
                <th class="text-center">{{ $t('Action') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="o in offers" :key="o.id">
                <td>{{ o.code }}</td>
                <td>{{ o.product }}</td>
                <td>{{ o.supplier }}</td>
                <td>{{ o.supplier_sku || '—' }}</td>
                <td class="text-right">
                  {{ money(o.cost) }}
                  <!-- The comparison a single cost field on the product could never show. -->
                  <span v-if="o.is_cheapest" class="badge badge-outline-success ml-1">{{ $t('Cheapest') }}</span>
                </td>
                <td class="text-right">{{ o.lead_time_days }}</td>
                <td class="text-right">{{ o.min_order_qty }}</td>
                <td class="text-center">
                  <i v-if="o.is_preferred" class="i-Check text-20 text-success"></i>
                  <span v-else class="text-muted">—</span>
                </td>
                <td class="text-center">
                  <a @click="Open_Edit(o)" class="cursor-pointer mr-2" title="Edit">
                    <i class="i-Pen-2 text-25 text-success"></i>
                  </a>
                  <a @click="Remove(o)" class="cursor-pointer" title="Delete">
                    <i class="i-Close-Window text-25 text-danger"></i>
                  </a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </b-card>
    </div>

    <b-modal id="sp_modal" :title="editing ? $t('EditSupplierOffer') : $t('NewSupplierOffer')" hide-footer>
      <b-form-group :label="$t('Product')" v-if="!editing">
        <v-select
          v-model="form.product_id"
          :reduce="label => label.value"
          :placeholder="$t('Choose_Product')"
          :options="products.map(p => ({label: p.code + ' — ' + p.name, value: p.id}))"
        />
      </b-form-group>

      <b-form-group :label="$t('Supplier')" v-if="!editing">
        <v-select
          v-model="form.provider_id"
          :reduce="label => label.value"
          :placeholder="$t('Choose_Supplier')"
          :options="suppliers.map(s => ({label: s.name, value: s.id}))"
        />
      </b-form-group>

      <p v-if="editing" class="mb-3">
        <strong>{{ editing.product }}</strong><br>
        <span class="text-muted">{{ editing.supplier }}</span>
      </p>

      <b-form-group :label="$t('SupplierSKU')">
        <b-form-input v-model="form.supplier_sku" :placeholder="$t('SupplierSKUPlaceholder')"/>
      </b-form-group>

      <b-row>
        <b-col md="4">
          <b-form-group :label="$t('Cost')">
            <b-form-input type="number" step="any" min="0" v-model.number="form.cost"/>
          </b-form-group>
        </b-col>
        <b-col md="4">
          <b-form-group :label="$t('LeadTimeDays')">
            <b-form-input type="number" min="0" v-model.number="form.lead_time_days"/>
          </b-form-group>
        </b-col>
        <b-col md="4">
          <b-form-group :label="$t('MinOrderQty')">
            <b-form-input type="number" step="any" min="0" v-model.number="form.min_order_qty"/>
          </b-form-group>
        </b-col>
      </b-row>

      <b-form-checkbox v-model="form.is_preferred" switch>{{ $t('PreferredSupplier') }}</b-form-checkbox>
      <small class="text-muted d-block">{{ $t('PreferredSupplierNote') }}</small>

      <b-button class="mt-3" variant="primary" :disabled="Processing || !canSave" @click="Save">
        {{ $t('Submit') }}
      </b-button>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";

export default {
  metaInfo: { title: "Supplier Pricing" },

  data() {
    return {
      isLoading: true,
      Processing: false,
      offers: [],
      suppliers: [],
      products: [],
      search: "",
      provider_id: "",
      editing: null,
      form: {
        product_id: null, provider_id: null, supplier_sku: "",
        cost: 0, lead_time_days: 0, min_order_qty: 0, is_preferred: false
      }
    };
  },

  computed: {
    ...mapGetters(["currentUser"]),

    canSave() {
      if (this.editing) return this.form.cost !== "" && this.form.cost !== null;
      return this.form.product_id && this.form.provider_id;
    }
  },

  methods: {
    money(v) {
      const symbol = (this.currentUser && this.currentUser.currency) || "";
      return (symbol ? symbol + " " : "") + Number(v || 0).toFixed(2);
    },

    Get_Offers() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("supplier_pricing", { params: { search: this.search, provider_id: this.provider_id } })
        .then(r => {
          this.offers = r.data.offers;
          this.suppliers = r.data.suppliers;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    Get_Products() {
      axios.get("Products?page=1&limit=1000&SortField=name&SortType=asc").then(r => {
        this.products = r.data.products || [];
      });
    },

    Open_Create() {
      this.editing = null;
      this.form = {
        product_id: null, provider_id: null, supplier_sku: "",
        cost: 0, lead_time_days: 0, min_order_qty: 0, is_preferred: false
      };
      this.$bvModal.show("sp_modal");
    },

    Open_Edit(o) {
      this.editing = o;
      this.form = {
        product_id: o.product_id,
        provider_id: o.provider_id,
        supplier_sku: o.supplier_sku || "",
        cost: o.cost,
        lead_time_days: o.lead_time_days,
        min_order_qty: o.min_order_qty,
        is_preferred: o.is_preferred
      };
      this.$bvModal.show("sp_modal");
    },

    Save() {
      this.Processing = true;
      const req = this.editing
        ? axios.put("supplier_pricing/" + this.editing.id, this.form)
        : axios.post("supplier_pricing", this.form);
      req
        .then(() => {
          this.Processing = false;
          this.$bvModal.hide("sp_modal");
          this.Get_Offers();
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    Remove(o) {
      this.$swal({
        title: this.$t("Are_you_sure"),
        text: o.supplier + " — " + o.product,
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: this.$t("Yes_delete")
      }).then(result => {
        if (!result.value) return;
        axios.delete("supplier_pricing/" + o.id).then(() => {
          this.Get_Offers();
          this.makeToast("success", this.$t("Successfully_Deleted"), this.$t("Success"));
        }).catch(e => this.makeToast("danger", this.errorOf(e), this.$t("Failed")));
      });
    },

    errorOf(error) {
      const d = error.response && error.response.data;
      if (d && d.errors) {
        const first = Object.keys(d.errors)[0];
        return d.errors[first][0];
      }
      return (d && d.message) || this.$t("InvalidData");
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    }
  },

  created() {
    this.Get_Offers();
    this.Get_Products();
  }
};
</script>
