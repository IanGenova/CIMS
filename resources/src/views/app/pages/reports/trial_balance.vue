<template>
  <div class="main-content">
    <breadcumb :page="$t('TrialBalance')" :folder="$t('Reports')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-card class="mb-3">
        <b-row class="align-items-end">
          <b-col md="3">
            <b-form-group :label="$t('From')" class="mb-0">
              <b-form-input type="date" v-model="from" @change="Get_Report"/>
            </b-form-group>
          </b-col>
          <b-col md="3">
            <b-form-group :label="$t('To')" class="mb-0">
              <b-form-input type="date" v-model="to" @change="Get_Report"/>
            </b-form-group>
          </b-col>
          <b-col md="6" class="text-right">
            <b-button variant="outline-primary" size="sm" @click="Export">
              <i class="i-Download"></i> {{ $t('ExportCSV') }}
            </b-button>
          </b-col>
        </b-row>
      </b-card>

      <!-- If this ever says out of balance the journal has been edited directly. -->
      <b-alert :variant="balanced ? 'success' : 'danger'" show class="mb-3">
        <strong>{{ balanced ? $t('LedgerBalances') : $t('LedgerOutOfBalance') }}</strong>
        — {{ $t('Debit') }} {{ money(total_debit) }} · {{ $t('Credit') }} {{ money(total_credit) }}
      </b-alert>

      <b-card>
        <div v-if="!rows.length" class="text-center text-muted py-4">
          {{ $t('NothingPostedYet') }}
        </div>

        <div v-else class="table-responsive">
          <table class="table table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('AccountCode') }}</th>
                <th>{{ $t('AccountName') }}</th>
                <th>{{ $t('AccountType') }}</th>
                <th class="text-right">{{ $t('Debit') }}</th>
                <th class="text-right">{{ $t('Credit') }}</th>
                <th class="text-right">{{ $t('Balance') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in rows" :key="r.account_id">
                <td><code>{{ r.code }}</code></td>
                <td>{{ r.name }}</td>
                <td><span class="badge badge-outline-secondary">{{ $t('AccountType_' + r.type) }}</span></td>
                <td class="text-right">{{ money(r.debit) }}</td>
                <td class="text-right">{{ money(r.credit) }}</td>
                <td class="text-right"><strong>{{ money(r.balance) }}</strong></td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="bg-gray-100">
                <th colspan="3">{{ $t('TotalDue') }}</th>
                <th class="text-right">{{ money(total_debit) }}</th>
                <th class="text-right">{{ money(total_credit) }}</th>
                <th></th>
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
  metaInfo: { title: "Trial Balance" },

  data() {
    return {
      isLoading: true,
      rows: [],
      total_debit: 0,
      total_credit: 0,
      balanced: true,
      from: "",
      to: ""
    };
  },

  computed: {
    ...mapGetters(["currentUser"])
  },

  methods: {
    money(v) {
      const symbol = (this.currentUser && this.currentUser.currency) || "";
      return (symbol ? symbol + " " : "") + Number(v || 0).toFixed(2);
    },

    Get_Report() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("accounting/trial_balance", { params: { from: this.from, to: this.to } })
        .then(r => {
          this.rows = r.data.rows;
          this.total_debit = r.data.total_debit;
          this.total_credit = r.data.total_credit;
          this.balanced = r.data.balanced;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    Export() {
      // Fetch as a blob so the request carries the session, then hand it over.
      axios
        .get("accounting/trial_balance/export", {
          params: { from: this.from, to: this.to },
          responseType: "blob"
        })
        .then(r => {
          const url = window.URL.createObjectURL(new Blob([r.data]));
          const link = document.createElement("a");
          link.href = url;
          link.setAttribute("download", "trial-balance.csv");
          document.body.appendChild(link);
          link.click();
          link.remove();
          window.URL.revokeObjectURL(url);
        });
    }
  },

  created() {
    this.Get_Report();
  }
};
</script>
