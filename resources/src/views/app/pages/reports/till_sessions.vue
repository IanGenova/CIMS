<template>
  <div class="main-content">
    <breadcumb :page="$t('TillSessions')" :folder="$t('Reports')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-card class="mb-3">
        <b-row>
          <b-col md="3">
            <b-form-group :label="$t('warehouse')" class="mb-0">
              <v-select
                @input="Reset_Page"
                v-model="warehouse_id"
                :reduce="label => label.value"
                :placeholder="$t('AllWarehouses')"
                :options="warehouses.map(w => ({label: w.name, value: w.id}))"
              />
            </b-form-group>
          </b-col>
          <b-col md="3">
            <b-form-group :label="$t('Status')" class="mb-0">
              <v-select
                @input="Reset_Page"
                v-model="status"
                :reduce="label => label.value"
                :placeholder="$t('AllStatuses')"
                :options="[
                  {label: $t('Open'), value: 'open'},
                  {label: $t('Closed'), value: 'closed'}
                ]"
              />
            </b-form-group>
          </b-col>
          <b-col md="3">
            <b-form-group :label="$t('From')" class="mb-0">
              <b-form-input type="date" v-model="from" @change="Reset_Page"/>
            </b-form-group>
          </b-col>
          <b-col md="3">
            <b-form-group :label="$t('To')" class="mb-0">
              <b-form-input type="date" v-model="to" @change="Reset_Page"/>
            </b-form-group>
          </b-col>
        </b-row>
      </b-card>

      <b-card v-if="!sessions.length">
        <p class="text-center text-muted mb-0 py-4">{{ $t('NoTillSessions') }}</p>
      </b-card>

      <b-card v-else>
        <div class="table-responsive">
          <table class="table table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('ZNumber') }}</th>
                <th>{{ $t('warehouse') }}</th>
                <th>{{ $t('Terminal') }}</th>
                <th>{{ $t('Cashier') }}</th>
                <th>{{ $t('OpenedAt') }}</th>
                <th>{{ $t('ClosedAt') }}</th>
                <th class="text-right">{{ $t('OpeningFloat') }}</th>
                <th class="text-right">{{ $t('ExpectedCash') }}</th>
                <th class="text-right">{{ $t('CountedCash') }}</th>
                <th class="text-right">{{ $t('ShortOver') }}</th>
                <th class="text-center">{{ $t('Action') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in sessions" :key="row.id">
                <td>
                  <span v-if="row.z_counter">Z{{ row.z_counter }}</span>
                  <span v-else class="badge badge-warning">{{ $t('Open') }}</span>
                </td>
                <td>{{ row.warehouse }}</td>
                <td>{{ row.terminal }}</td>
                <td>{{ row.cashier }}</td>
                <td class="text-nowrap">{{ row.opened_at }}</td>
                <td class="text-nowrap">{{ row.closed_at || '—' }}</td>
                <td class="text-right">{{ money(row.opening_float) }}</td>
                <td class="text-right">{{ row.expected_cash === null ? '—' : money(row.expected_cash) }}</td>
                <td class="text-right">{{ row.counted_cash === null ? '—' : money(row.counted_cash) }}</td>
                <td class="text-right" :class="varianceClass(row.variance)">
                  {{ row.variance === null ? '—' : signed(row.variance) }}
                </td>
                <td class="text-center">
                  <a class="cursor-pointer" :title="$t('Reprint')" @click="Open_Reading(row)">
                    <i class="i-File-Copy text-25 text-success"></i>
                  </a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <b-pagination
          v-model="page"
          :total-rows="totalRows"
          :per-page="limit"
          @change="Change_Page"
          align="right"
          class="mt-3 mb-0"
        />
      </b-card>
    </div>

    <!-- The reading, as it was signed off. -->
    <b-modal id="till_reading" size="lg" :title="readingTitle" hide-footer>
      <div v-if="reading">
        <div id="till-session-print">
          <div class="text-center mb-3">
            <h4 class="mb-0">{{ readingTitle }}</h4>
            <p class="mb-0" v-if="reading.session.z_counter">Z# {{ reading.session.z_counter }}</p>
            <p class="mb-0 text-muted">
              {{ $t('Terminal') }}: {{ reading.session.terminal }} &nbsp;|&nbsp;
              {{ reading.session.opened_at }} &ndash; {{ reading.session.closed_at || $t('Open') }}
            </p>
          </div>

          <table class="table table-sm">
            <tbody>
              <tr><td>{{ $t('Sales') }}</td><td class="text-right">{{ reading.sales.count }}</td></tr>
              <tr><td>{{ $t('FirstInvoice') }}</td><td class="text-right">{{ reading.sales.first_invoice || '—' }}</td></tr>
              <tr><td>{{ $t('LastInvoice') }}</td><td class="text-right">{{ reading.sales.last_invoice || '—' }}</td></tr>
              <tr><td>{{ $t('GrossSales') }}</td><td class="text-right">{{ money(reading.sales.gross) }}</td></tr>
              <tr><td>{{ $t('Discount') }}</td><td class="text-right">{{ less(reading.discounts.statutory + reading.discounts.regular) }}</td></tr>
              <tr v-if="reading.discounts.vat_exemption > 0"><td>{{ $t('VatExemptScPwd') }}</td><td class="text-right">{{ less(reading.discounts.vat_exemption) }}</td></tr>
              <tr><th>{{ $t('NetSales') }}</th><th class="text-right">{{ money(reading.sales.net) }}</th></tr>
            </tbody>
          </table>

          <table class="table table-sm">
            <tbody>
              <tr><td>{{ $t('VatableSales') }}</td><td class="text-right">{{ money(reading.vat.vatable_sales) }}</td></tr>
              <tr><td>{{ $t('VAT') }}</td><td class="text-right">{{ money(reading.vat.vat_amount) }}</td></tr>
              <tr><td>{{ $t('VatExemptSales') }}</td><td class="text-right">{{ money(reading.vat.vat_exempt_sales) }}</td></tr>
              <tr><td>{{ $t('ZeroRatedSales') }}</td><td class="text-right">{{ money(reading.vat.zero_rated_sales) }}</td></tr>
            </tbody>
          </table>

          <table class="table table-sm" v-if="reading.payments.length">
            <tbody>
              <tr v-for="(m, i) in reading.payments" :key="'m' + i">
                <td>{{ m.method }} ({{ m.count }})</td>
                <td class="text-right">{{ money(m.amount) }}</td>
              </tr>
            </tbody>
          </table>

          <table class="table table-sm">
            <tbody>
              <tr><td>{{ $t('OpeningFloat') }}</td><td class="text-right">{{ money(reading.cash.opening_float) }}</td></tr>
              <tr><td>{{ $t('CashSales') }}</td><td class="text-right">{{ money(reading.cash.cash_sales) }}</td></tr>
              <tr><td>{{ $t('CashIn') }}</td><td class="text-right">{{ money(reading.cash.cash_in) }}</td></tr>
              <tr><td>{{ $t('CashOut') }}</td><td class="text-right">{{ less(reading.cash.cash_out) }}</td></tr>
              <tr v-if="reading.cash.refunds > 0"><td>{{ $t('Refunds') }}</td><td class="text-right">{{ less(reading.cash.refunds) }}</td></tr>
              <tr><th>{{ $t('ExpectedCash') }}</th><th class="text-right">{{ money(reading.cash.expected) }}</th></tr>
              <tr v-if="reading.cash.counted !== null"><td>{{ $t('CountedCash') }}</td><td class="text-right">{{ money(reading.cash.counted) }}</td></tr>
              <tr v-if="reading.cash.variance !== null">
                <th>{{ $t('Variance') }}</th>
                <th class="text-right" :class="varianceClass(reading.cash.variance)">{{ signed(reading.cash.variance) }}</th>
              </tr>
            </tbody>
          </table>

          <table class="table table-sm" v-if="reading.accumulated.after !== null">
            <tbody>
              <tr><td>{{ $t('AccumulatedBefore') }}</td><td class="text-right">{{ money(reading.accumulated.before) }}</td></tr>
              <tr><td>{{ $t('AccumulatedAfter') }}</td><td class="text-right">{{ money(reading.accumulated.after) }}</td></tr>
            </tbody>
          </table>

          <p class="text-muted small mb-0" v-if="reading.returns && reading.returns.count > 0">
            {{ reading.returns.basis }}
          </p>
        </div>

        <b-button variant="primary" class="mt-2" @click="Print_Reading">{{ $t('Print') }}</b-button>
      </div>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";

export default {
  metaInfo: {
    title: "Till Sessions"
  },

  data() {
    return {
      isLoading: true,
      sessions: [],
      warehouses: [],
      warehouse_id: "",
      status: "",
      from: "",
      to: "",
      page: 1,
      limit: 25,
      totalRows: 0,
      reading: null,
      kind: "Z"
    };
  },

  computed: {
    ...mapGetters(["currentUser"]),

    readingTitle() {
      return this.kind === "Z" ? this.$t("ZReading") : this.$t("XReading");
    }
  },

  methods: {
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

    signed(value) {
      const n = Number(value || 0);
      return (n > 0 ? "+" : "") + this.money(n);
    },

    // A drawer that is short is the one worth spotting across a page of shifts.
    varianceClass(v) {
      if (v === null || Number(v) === 0) return "";
      return Number(v) < 0 ? "till-short" : "till-over";
    },

    Reset_Page() {
      this.page = 1;
      this.Get_Sessions();
    },

    Change_Page(page) {
      this.page = page;
      this.$nextTick(this.Get_Sessions);
    },

    Get_Sessions() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("till/sessions", {
          params: {
            page: this.page,
            limit: this.limit,
            warehouse_id: this.warehouse_id || "",
            status: this.status || "",
            from: this.from || "",
            to: this.to || ""
          }
        })
        .then(response => {
          this.sessions = response.data.sessions;
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

    Open_Reading(row) {
      this.reading = null;
      this.$bvModal.show("till_reading");
      axios
        .get("till/sessions/" + row.id)
        .then(response => {
          this.reading = response.data.reading;
          this.kind = response.data.kind;
        })
        .catch(() => {
          this.$bvModal.hide("till_reading");
          this.$root.$bvToast.toast(this.$t("InvalidData"), {
            title: this.$t("Failed"),
            variant: "danger",
            solid: true
          });
        });
    },

    Print_Reading() {
      const el = document.getElementById("till-session-print");
      if (!el) {
        return;
      }
      const w = window.open("", "", "height=700, width=500");
      w.document.write(
        '<html><head><link rel="stylesheet" href="/css/pos_print.css">' +
          "<style>@page { margin: 10mm } body { font-family: sans-serif; font-size: 12px }" +
          "table { width: 100%; border-collapse: collapse }" +
          "td, th { padding: 3px 0 }" +
          ".text-right { text-align: right }" +
          ".text-center { text-align: center }</style></head><body>"
      );
      w.document.write(el.innerHTML);
      w.document.write("</body></html>");
      w.document.close();
      w.onload = () => { w.focus(); w.print(); };
      setTimeout(() => { try { w.focus(); w.print(); } catch (e) { /* already printed */ } }, 250);
    }
  },

  created() {
    this.Get_Sessions();
  }
};
</script>

<style scoped>
/* Measured against both themes' card grounds: the theme's own success and
   danger tones sit near 2.5:1 as small text. */
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
</style>
