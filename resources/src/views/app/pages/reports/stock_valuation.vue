<template>
  <div class="main-content">
    <breadcumb :page="$t('StockValuation')" :folder="$t('Reports')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-row class="mb-3">
        <b-col md="3">
          <b-card class="mb-2">
            <p class="text-muted mb-0">{{ $t('Products') }}</p>
            <p class="text-24 line-height-1 mb-0">{{ totalRows }}</p>
          </b-card>
        </b-col>
        <b-col md="3">
          <b-card class="mb-2">
            <p class="text-muted mb-0">{{ $t('TotalQuantity') }}</p>
            <p class="text-24 line-height-1 mb-0">{{ number(total_quantity) }}</p>
          </b-card>
        </b-col>
        <b-col md="3">
          <b-card class="mb-2">
            <p class="text-muted mb-0">{{ $t('StockValue') }}</p>
            <p class="text-24 line-height-1 mb-0 text-primary">
              {{ currentUser.currency }} {{ money(total_value) }}
            </p>
          </b-card>
        </b-col>
        <b-col md="3">
          <b-card class="mb-2">
            <b-form-group :label="$t('warehouse')" class="mb-0">
              <v-select
                @input="Get_Valuation"
                v-model="warehouse_id"
                :reduce="label => label.value"
                :placeholder="$t('AllWarehouses')"
                :options="warehouses.map(w => ({label: w.name, value: w.id}))"
              />
            </b-form-group>
          </b-card>
        </b-col>
      </b-row>

      <b-card class="mb-3">
        <b-form-input
          v-model="search"
          debounce="500"
          @update="Get_Valuation"
          :placeholder="$t('SearchByNameOrCode')"
        />
      </b-card>

      <b-card v-if="!rows.length">
        <p class="text-center text-muted mb-0 py-4">{{ $t('NoStockOnHand') }}</p>
      </b-card>

      <b-card v-else>
        <div class="table-responsive">
          <table class="table table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('ProductCode') }}</th>
                <th>{{ $t('ProductName') }}</th>
                <th>{{ $t('warehouse') }}</th>
                <th class="text-right">{{ $t('InStock') }}</th>
                <th class="text-right">{{ $t('AverageCost') }}</th>
                <th class="text-right">{{ $t('StockValue') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, i) in rows" :key="i">
                <td>{{ row.code }}</td>
                <td>{{ row.product }}</td>
                <td>{{ row.warehouse }}</td>
                <td class="text-right">{{ number(row.quantity) }}</td>
                <td class="text-right">{{ currentUser.currency }} {{ money(row.average_cost) }}</td>
                <td class="text-right">{{ currentUser.currency }} {{ money(row.value) }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr>
                <th colspan="3">{{ $t('Total') }}</th>
                <th class="text-right">{{ number(total_quantity) }}</th>
                <th></th>
                <th class="text-right">{{ currentUser.currency }} {{ money(total_value) }}</th>
              </tr>
            </tfoot>
          </table>
        </div>
      </b-card>
    </div>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";

export default {
  metaInfo: {
    title: "Stock Valuation"
  },

  data() {
    return {
      isLoading: true,
      rows: [],
      warehouses: [],
      warehouse_id: "",
      search: "",
      totalRows: 0,
      total_quantity: 0,
      total_value: 0
    };
  },

  computed: {
    ...mapGetters(["currentUser"])
  },

  methods: {
    // Grouped, because a stock value runs to six figures and 1229134.50 is
    // not a number anyone can read at a glance.
    money(value) {
      return Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    },

    number(value) {
      return Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 4 });
    },

    Get_Valuation() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("stock/valuation", {
          params: {
            warehouse_id: this.warehouse_id || "",
            search: this.search || ""
          }
        })
        .then(response => {
          this.rows = response.data.rows;
          this.warehouses = response.data.warehouses;
          this.totalRows = response.data.totalRows;
          this.total_quantity = response.data.total_quantity;
          this.total_value = response.data.total_value;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    }
  },

  created() {
    this.Get_Valuation();
  }
};
</script>
