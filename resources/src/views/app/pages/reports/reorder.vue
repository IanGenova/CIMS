<template>
  <div class="main-content">
    <breadcumb :page="$t('ReorderSuggestions')" :folder="$t('Reports')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-row class="mb-3">
        <b-col md="4">
          <b-card class="mb-2">
            <p class="text-muted mb-0">{{ $t('ProductsToReorder') }}</p>
            <p class="text-24 line-height-1 mb-0 text-warning">{{ totalRows }}</p>
          </b-card>
        </b-col>
        <b-col md="4">
          <b-card class="mb-2">
            <p class="text-muted mb-0">{{ $t('EstimatedCost') }}</p>
            <p class="text-24 line-height-1 mb-0">{{ currentUser.currency }} {{ money(estimated_cost) }}</p>
          </b-card>
        </b-col>
        <b-col md="4">
          <b-card class="mb-2">
            <b-form-group :label="$t('warehouse')" class="mb-0">
              <v-select
                @input="Get_Suggestions"
                v-model="warehouse_id"
                :reduce="label => label.value"
                :placeholder="$t('AllWarehouses')"
                :options="warehouses.map(w => ({label: w.name, value: w.id}))"
              />
            </b-form-group>
          </b-card>
        </b-col>
      </b-row>

      <b-card v-if="!suggestions.length">
        <p class="text-center text-muted mb-0 py-4">{{ $t('NothingNeedsReordering') }}</p>
      </b-card>

      <b-card v-else>
        <div class="table-responsive">
          <table class="table table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('ProductCode') }}</th>
                <th>{{ $t('ProductName') }}</th>
                <th class="text-right">{{ $t('InStock') }}</th>
                <th class="text-right">{{ $t('AlertQuantity') }}</th>
                <th class="text-right">{{ $t('Shortfall') }}</th>
                <th class="text-right">{{ $t('SuggestedQty') }}</th>
                <th>{{ $t('PreferredSupplier') }}</th>
                <th class="text-right">{{ $t('EstimatedCost') }}</th>
                <th class="text-center">{{ $t('Action') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in suggestions" :key="row.product_id">
                <td>{{ row.code }}</td>
                <td>{{ row.name }}</td>
                <td class="text-right text-danger">{{ row.current_stock }}</td>
                <td class="text-right">{{ row.stock_alert }}</td>
                <td class="text-right">{{ Math.abs(row.shortfall) }}</td>
                <td class="text-right"><strong>{{ row.suggested_qte }}</strong></td>
                <td>{{ row.preferred_provider || '—' }}</td>
                <td class="text-right">{{ currentUser.currency }} {{ money(row.estimated_cost) }}</td>
                <td class="text-center">
                  <a
                    @click="Open_Policy(row)"
                    class="cursor-pointer"
                    :title="$t('ReorderPolicy')"
                  >
                    <i class="i-Pen-2 text-25 text-success"></i>
                  </a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </b-card>
    </div>

    <!-- Tuning the suggestion without opening the whole product record -->
    <b-modal id="reorder_policy" :title="$t('ReorderPolicy')" hide-footer>
      <div v-if="selected">
        <p class="mb-3">{{ selected.code }} — {{ selected.name }}</p>

        <b-form-group :label="$t('ReorderQuantity')">
          <b-form-input type="number" min="0" step="any" v-model.number="policy.reorder_qte"/>
          <small class="text-muted">{{ $t('LeaveZeroToTopUp') }}</small>
        </b-form-group>

        <b-form-group :label="$t('PreferredSupplier')">
          <v-select
            v-model="policy.preferred_provider_id"
            :reduce="label => label.value"
            :placeholder="$t('Choose_Supplier')"
            :options="suppliers.map(s => ({label: s.name, value: s.id}))"
          />
        </b-form-group>

        <b-button variant="primary" :disabled="Processing" @click="Save_Policy">
          {{ $t('Submit') }}
        </b-button>
      </div>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";

export default {
  metaInfo: {
    title: "Reorder Suggestions"
  },

  data() {
    return {
      isLoading: true,
      Processing: false,
      suggestions: [],
      warehouses: [],
      suppliers: [],
      warehouse_id: "",
      totalRows: 0,
      estimated_cost: 0,
      selected: null,
      policy: { reorder_qte: 0, preferred_provider_id: null }
    };
  },

  computed: {
    ...mapGetters(["currentUser"])
  },

  methods: {
    money(value) {
      return Number(value || 0).toFixed(2);
    },

    Get_Suggestions() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("reorder/suggestions?warehouse_id=" + (this.warehouse_id || ""))
        .then(response => {
          this.suggestions = response.data.suggestions;
          this.warehouses = response.data.warehouses;
          this.totalRows = response.data.totalRows;
          this.estimated_cost = response.data.estimated_cost;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    Get_Suppliers() {
      axios.get("providers?page=1&limit=500&SortField=id&SortType=asc").then(response => {
        this.suppliers = response.data.providers || [];
      });
    },

    Open_Policy(row) {
      this.selected = row;
      this.policy = {
        reorder_qte: row.suggested_qte,
        preferred_provider_id: row.preferred_provider_id
      };
      this.$bvModal.show("reorder_policy");
    },

    Save_Policy() {
      this.Processing = true;
      axios
        .put("reorder/product/" + this.selected.product_id, {
          reorder_qte: this.policy.reorder_qte,
          preferred_provider_id: this.policy.preferred_provider_id
        })
        .then(() => {
          this.Processing = false;
          this.$bvModal.hide("reorder_policy");
          this.Get_Suggestions();
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        })
        .catch(error => {
          this.Processing = false;
          const data = error.response && error.response.data;
          this.makeToast("danger", (data && data.message) || this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    }
  },

  created() {
    this.Get_Suggestions();
    this.Get_Suppliers();
  }
};
</script>
