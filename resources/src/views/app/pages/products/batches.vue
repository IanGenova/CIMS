<template>
  <div class="main-content">
    <breadcumb :page="$t('Batches')" :folder="$t('Products')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-row v-if="!isLoading" class="mb-3">
      <b-col cols="12" sm="6" lg="3">
        <b-form-group :label="$t('warehouse')">
          <v-select
            @input="Get_Batches(1)"
            v-model="warehouse_id"
            :reduce="label => label.value"
            :placeholder="$t('Choose_Warehouse')"
            :options="warehouses.map(w => ({label: w.name, value: w.id}))"
          />
        </b-form-group>
      </b-col>
      <b-col cols="12" sm="6" lg="3">
        <b-form-group :label="$t('ExpiryStatus')">
          <v-select
            @input="Get_Batches(1)"
            v-model="status"
            :reduce="label => label.value"
            :placeholder="$t('All')"
            :options="[
              {label: $t('Expired'),  value: 'expired'},
              {label: $t('Expiring'), value: 'expiring'},
              {label: $t('Ok'),       value: 'ok'},
            ]"
          />
        </b-form-group>
      </b-col>
    </b-row>

    <vue-good-table
      v-if="!isLoading"
      mode="remote"
      :columns="columns"
      :totalRows="totalRows"
      :rows="batches"
      @on-page-change="onPageChange"
      @on-per-page-change="onPerPageChange"
      :search-options="{ enabled: true, placeholder: $t('Search_this_table') }"
      @on-search="onSearch"
      :pagination-options="{
        enabled: true,
        mode: 'records',
        nextLabel: 'next',
        prevLabel: 'prev',
      }"
      styleClass="table-hover tableOne vgt-table"
    >
      <template slot="table-row" slot-scope="props">
        <span v-if="props.column.field == 'status'">
          <span
            class="badge"
            :class="{
              'badge-outline-danger':  props.row.status === 'expired',
              'badge-outline-warning': props.row.status === 'expiring',
              'badge-outline-success': props.row.status === 'ok',
              'badge-outline-secondary': props.row.status === 'none'
            }"
          >{{ statusLabel(props.row) }}</span>
        </span>

        <span v-else-if="props.column.field == 'expiry_date'">
          {{ props.row.expiry_date || '—' }}
        </span>

        <span v-else-if="props.column.field == 'actions'">
          <a
            @click="Trace(props.row)"
            class="cursor-pointer"
            :title="$t('TraceBatch')"
          >
            <i class="i-Eye text-25 text-info"></i>
          </a>
        </span>
      </template>
    </vue-good-table>

    <!-- Traceability: where a lot came from and where it went -->
    <b-modal hide-footer size="lg" id="batch_trace" :title="$t('TraceBatch')">
      <div v-if="trace">
        <b-row class="mb-3">
          <b-col md="6">
            <p class="mb-1"><strong>{{ $t('BatchNumber') }}:</strong> {{ trace.batch.batch_number }}</p>
            <p class="mb-1"><strong>{{ $t('ProductName') }}:</strong> {{ trace.batch.product }}</p>
            <p class="mb-1"><strong>{{ $t('Supplier') }}:</strong> {{ trace.batch.supplier || '—' }}</p>
          </b-col>
          <b-col md="6">
            <p class="mb-1"><strong>{{ $t('ExpiryDate') }}:</strong> {{ trace.batch.expiry_date || '—' }}</p>
            <p class="mb-1"><strong>{{ $t('ManufactureDate') }}:</strong> {{ trace.batch.manufacture_date || '—' }}</p>
            <p class="mb-1">
              <strong>{{ $t('ExpiryStatus') }}:</strong>
              <span
                class="badge"
                :class="{
                  'badge-outline-danger':  trace.batch.status === 'expired',
                  'badge-outline-warning': trace.batch.status === 'expiring',
                  'badge-outline-success': trace.batch.status === 'ok',
                  'badge-outline-secondary': trace.batch.status === 'none'
                }"
              >{{ statusLabel(trace.batch) }}</span>
            </p>
          </b-col>
        </b-row>

        <h6 class="mt-3">{{ $t('StockOnHand') }}</h6>
        <table class="table table-sm">
          <thead><tr><th>{{ $t('warehouse') }}</th><th class="text-right">{{ $t('Quantity') }}</th></tr></thead>
          <tbody>
            <tr v-for="s in trace.stock_on_hand" :key="'s'+s.warehouse_id">
              <td>{{ s.warehouse }}</td>
              <td class="text-right">{{ s.qte }}</td>
            </tr>
            <tr v-if="!trace.stock_on_hand.length"><td colspan="2">{{ $t('NoDataAvailable') }}</td></tr>
          </tbody>
        </table>

        <h6 class="mt-4">{{ $t('MovementHistory') }}</h6>
        <table class="table table-sm">
          <thead>
            <tr>
              <th>{{ $t('date') }}</th>
              <th>{{ $t('Type') }}</th>
              <th class="text-right">{{ $t('Quantity') }}</th>
              <th>{{ $t('Reference') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in trace.movements" :key="'m'+m.id">
              <td>{{ m.date }}</td>
              <td>{{ m.type }}</td>
              <td class="text-right" :class="m.qte < 0 ? 'text-danger' : 'text-success'">{{ m.qte }}</td>
              <td>{{ m.reference || '—' }}</td>
            </tr>
            <tr v-if="!trace.movements.length"><td colspan="4">{{ $t('NoDataAvailable') }}</td></tr>
          </tbody>
        </table>
      </div>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: {
    title: "Batches"
  },

  data() {
    return {
      isLoading: true,
      serverParams: { page: 1, perPage: 10 },
      limit: "10",
      totalRows: "",
      search: "",
      batches: [],
      warehouses: [],
      warehouse_id: "",
      status: "",
      trace: null
    };
  },

  computed: {
    columns() {
      return [
        { label: this.$t("BatchNumber"), field: "batch_number", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("ProductCode"), field: "code", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("ProductName"), field: "product", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("ExpiryDate"), field: "expiry_date", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("ExpiryStatus"), field: "status", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Quantity"), field: "qte", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Action"), field: "actions", html: false, tdClass: "text-center", thClass: "text-center", sortable: false }
      ];
    }
  },

  methods: {
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) {
        this.updateParams({ page: currentPage });
        this.Get_Batches(currentPage);
      }
    },

    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) {
        this.limit = currentPerPage;
        this.updateParams({ page: 1, perPage: currentPerPage });
        this.Get_Batches(1);
      }
    },

    onSearch(value) {
      this.search = value.searchTerm;
      this.Get_Batches(1);
    },

    /** "Expires in 43 days" reads better than a bare status word. */
    statusLabel(row) {
      if (row.status === "none") return this.$t("NoExpiry");
      if (row.status === "expired") {
        return this.$t("Expired") + " (" + Math.abs(row.days_to_expiry) + "d)";
      }
      return this.$t(row.status === "expiring" ? "Expiring" : "Ok") + " (" + row.days_to_expiry + "d)";
    },

    Trace(row) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("batches/" + row.id + "/trace")
        .then(response => {
          this.trace = response.data;
          this.$bvModal.show("batch_trace");
          NProgress.done();
        })
        .catch(() => {
          NProgress.done();
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Get_Batches(page) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "batches?page=" + page +
          "&limit=" + this.limit +
          "&warehouse_id=" + (this.warehouse_id || "") +
          "&status=" + (this.status || "") +
          "&search=" + this.search
        )
        .then(response => {
          this.batches = response.data.batches;
          this.warehouses = response.data.warehouses;
          this.totalRows = response.data.totalRows;
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

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    }
  },

  created() {
    this.Get_Batches(1);
  }
};
</script>
