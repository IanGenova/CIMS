<template>
  <div class="main-content">
    <breadcumb :page="$t('CustomerCredit')" :folder="$t('Reports')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <!-- Exposure first: the reason to open this screen -->
      <b-row class="mb-4">
        <b-col md="4" sm="6">
          <b-card class="card-icon-bg card-icon-bg-primary o-hidden mb-3">
            <div class="card-body text-center">
              <i class="i-Financial"></i>
              <div class="content">
                <p class="text-muted mt-2 mb-0">{{ $t('TotalOutstanding') }}</p>
                <p class="text-primary text-24 line-height-1 mb-2">{{ money(total_outstanding) }}</p>
              </div>
            </div>
          </b-card>
        </b-col>
        <b-col md="4" sm="6">
          <b-card class="card-icon-bg card-icon-bg-danger o-hidden mb-3">
            <div class="card-body text-center">
              <i class="i-Danger"></i>
              <div class="content">
                <p class="text-muted mt-2 mb-0">{{ $t('OverTheirLimit') }}</p>
                <p class="text-danger text-24 line-height-1 mb-2">{{ over_limit_count }}</p>
              </div>
            </div>
          </b-card>
        </b-col>
        <b-col md="4" sm="6">
          <b-card class="card-icon-bg card-icon-bg-warning o-hidden mb-3">
            <div class="card-body text-center">
              <i class="i-Clock"></i>
              <div class="content">
                <p class="text-muted mt-2 mb-0">{{ $t('OverNinetyDays') }}</p>
                <p class="text-warning text-24 line-height-1 mb-2">{{ money(buckets.over90) }}</p>
              </div>
            </div>
          </b-card>
        </b-col>
      </b-row>

      <!-- Ageing across everyone -->
      <b-card class="mb-4">
        <h5 class="mb-3">{{ $t('Ageing') }}</h5>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('Current') }}</th>
                <th>1–30</th>
                <th>31–60</th>
                <th>61–90</th>
                <th>90+</th>
                <th class="text-right">{{ $t('TotalDue') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>{{ money(buckets.current) }}</td>
                <td>{{ money(buckets.d30) }}</td>
                <td>{{ money(buckets.d60) }}</td>
                <td class="text-warning">{{ money(buckets.d90) }}</td>
                <td class="text-danger">{{ money(buckets.over90) }}</td>
                <td class="text-right"><strong>{{ money(total_due) }}</strong></td>
              </tr>
            </tbody>
          </table>
        </div>
      </b-card>

      <b-card>
        <h5 class="mb-3">{{ $t('CustomerBalances') }}</h5>

        <div v-if="!customers.length" class="text-center text-muted py-4">
          {{ $t('NoCreditExposure') }}
        </div>

        <div v-else class="table-responsive">
          <table class="table table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('CustomerName') }}</th>
                <th class="text-right">{{ $t('Opening') }}</th>
                <th class="text-right">{{ $t('Invoiced') }}</th>
                <th class="text-right">{{ $t('Paid') }}</th>
                <th class="text-right">{{ $t('Returned') }}</th>
                <th class="text-right">{{ $t('Balance') }}</th>
                <th class="text-right">{{ $t('CreditLimit') }}</th>
                <th class="text-right">{{ $t('Available') }}</th>
                <th class="text-center">{{ $t('Action') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in customers" :key="c.client_id" :class="c.over_limit ? 'table-danger' : ''">
                <td>
                  {{ c.client }}
                  <span v-if="c.over_limit" class="badge badge-outline-danger ml-1">{{ $t('OverLimit') }}</span>
                </td>
                <td class="text-right text-muted">{{ money(c.opening) }}</td>
                <td class="text-right">{{ money(c.invoiced) }}</td>
                <td class="text-right">{{ money(c.paid) }}</td>
                <td class="text-right">{{ money(c.returned) }}</td>
                <td class="text-right"><strong>{{ money(c.balance) }}</strong></td>
                <td class="text-right">{{ c.credit_limit > 0 ? money(c.credit_limit) : $t('NoLimit') }}</td>
                <td class="text-right" :class="c.over_limit ? 'text-danger' : ''">
                  {{ c.available === null ? '—' : money(c.available) }}
                </td>
                <td class="text-center">
                  <a @click="Open_Statement(c)" class="cursor-pointer" :title="$t('Statement')">
                    <i class="i-File-Horizontal-Text text-25 text-primary"></i>
                  </a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </b-card>
    </div>

    <b-modal id="statement_modal" :title="$t('Statement')" hide-footer size="lg">
      <div v-if="statement">
        <h5>{{ statement.client.name }}</h5>
        <p class="text-muted mb-3">
          {{ $t('CreditLimit') }}:
          {{ statement.client.credit_limit > 0 ? money(statement.client.credit_limit) : $t('NoLimit') }}
        </p>

        <table class="table table-sm mb-4">
          <tbody>
            <tr><td>{{ $t('Opening') }}</td><td class="text-right">{{ money(statement.balance.opening) }}</td></tr>
            <tr><td>{{ $t('Invoiced') }}</td><td class="text-right">{{ money(statement.balance.invoiced) }}</td></tr>
            <tr><td>{{ $t('Paid') }}</td><td class="text-right">− {{ money(statement.balance.paid) }}</td></tr>
            <tr><td>{{ $t('Returned') }}</td><td class="text-right">− {{ money(statement.balance.returned) }}</td></tr>
            <tr class="bg-gray-100">
              <td><strong>{{ $t('Balance') }}</strong></td>
              <td class="text-right"><strong>{{ money(statement.balance.balance) }}</strong></td>
            </tr>
          </tbody>
        </table>

        <h6>{{ $t('UnpaidInvoices') }}</h6>
        <div v-if="!statement.rows.length" class="text-muted py-2">{{ $t('NothingOutstanding') }}</div>
        <div v-else class="table-responsive">
          <table class="table table-sm">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('date') }}</th>
                <th>{{ $t('Reference') }}</th>
                <th class="text-right">{{ $t('Total') }}</th>
                <th class="text-right">{{ $t('Paid') }}</th>
                <th class="text-right">{{ $t('Due') }}</th>
                <th class="text-right">{{ $t('AgeDays') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in statement.rows" :key="r.sale_id">
                <td>{{ r.date }}</td>
                <td>{{ r.Ref }}</td>
                <td class="text-right">{{ money(r.total) }}</td>
                <td class="text-right">{{ money(r.paid) }}</td>
                <td class="text-right"><strong>{{ money(r.due) }}</strong></td>
                <td class="text-right" :class="r.age_days > 90 ? 'text-danger' : (r.age_days > 30 ? 'text-warning' : '')">
                  {{ r.age_days }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";

export default {
  metaInfo: { title: "Customer Credit" },

  data() {
    return {
      isLoading: true,
      customers: [],
      total_outstanding: 0,
      over_limit_count: 0,
      buckets: { current: 0, d30: 0, d60: 0, d90: 0, over90: 0 },
      total_due: 0,
      statement: null
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

    Get_Data() {
      NProgress.start();
      NProgress.set(0.1);
      Promise.all([axios.get("credit/customers"), axios.get("credit/ageing")])
        .then(([c, a]) => {
          this.customers = c.data.customers;
          this.total_outstanding = c.data.total_outstanding;
          this.over_limit_count = c.data.over_limit_count;
          this.buckets = a.data.buckets;
          this.total_due = a.data.total_due;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    Open_Statement(c) {
      axios.get("credit/statement/" + c.client_id).then(r => {
        this.statement = r.data;
        this.$bvModal.show("statement_modal");
      });
    }
  },

  created() {
    this.Get_Data();
  }
};
</script>
