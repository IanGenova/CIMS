<template>
  <div class="main-content">
    <breadcumb :page="$t('ExpiryReport')" :folder="$t('Reports')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <!-- What is at stake, before the detail -->
      <b-row class="mb-4">
        <b-col md="3" sm="6">
          <b-card class="card-icon-bg card-icon-bg-danger o-hidden mb-3">
            <div class="card-body text-center">
              <i class="i-Clock-Back"></i>
              <div class="content">
                <p class="text-muted mt-2 mb-0">{{ $t('ExpiredBatches') }}</p>
                <p class="text-danger text-24 line-height-1 mb-2">{{ summary.expired_batches }}</p>
              </div>
            </div>
          </b-card>
        </b-col>
        <b-col md="3" sm="6">
          <b-card class="card-icon-bg card-icon-bg-danger o-hidden mb-3">
            <div class="card-body text-center">
              <i class="i-Financial"></i>
              <div class="content">
                <p class="text-muted mt-2 mb-0">{{ $t('ValueExpired') }}</p>
                <p class="text-danger text-24 line-height-1 mb-2">
                  {{ currentUser.currency }} {{ money(summary.value_expired) }}
                </p>
              </div>
            </div>
          </b-card>
        </b-col>
        <b-col md="3" sm="6">
          <b-card class="card-icon-bg card-icon-bg-warning o-hidden mb-3">
            <div class="card-body text-center">
              <i class="i-Bell"></i>
              <div class="content">
                <p class="text-muted mt-2 mb-0">{{ $t('ExpiringBatches') }}</p>
                <p class="text-warning text-24 line-height-1 mb-2">{{ summary.expiring_batches }}</p>
              </div>
            </div>
          </b-card>
        </b-col>
        <b-col md="3" sm="6">
          <b-card class="card-icon-bg card-icon-bg-warning o-hidden mb-3">
            <div class="card-body text-center">
              <i class="i-Financial"></i>
              <div class="content">
                <p class="text-muted mt-2 mb-0">{{ $t('ValueExpiring') }}</p>
                <p class="text-warning text-24 line-height-1 mb-2">
                  {{ currentUser.currency }} {{ money(summary.value_expiring) }}
                </p>
              </div>
            </div>
          </b-card>
        </b-col>
      </b-row>

      <b-card class="mb-4">
        <b-row>
          <b-col md="4">
            <b-form-group :label="$t('warehouse')">
              <v-select
                @input="Get_Report"
                v-model="warehouse_id"
                :reduce="label => label.value"
                :placeholder="$t('Choose_Warehouse')"
                :options="warehouses.map(w => ({label: w.name, value: w.id}))"
              />
            </b-form-group>
          </b-col>
          <b-col md="4">
            <b-form-group :label="$t('ExpiringWithinDays')">
              <b-form-input
                type="number"
                min="0"
                v-model="days"
                @change="Get_Report"
                :placeholder="$t('UseProductDefault')"
              />
            </b-form-group>
          </b-col>
        </b-row>
      </b-card>

      <!-- Expired first: it is the money already lost -->
      <b-card class="mb-4" v-if="expired.length">
        <h5 class="text-danger mb-3">{{ $t('Expired') }}</h5>
        <div class="table-responsive">
          <table class="table table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('BatchNumber') }}</th>
                <th>{{ $t('ProductCode') }}</th>
                <th>{{ $t('ProductName') }}</th>
                <th>{{ $t('ExpiryDate') }}</th>
                <th class="text-right">{{ $t('DaysOverdue') }}</th>
                <th class="text-right">{{ $t('Quantity') }}</th>
                <th class="text-right">{{ $t('Value') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in expired" :key="'e'+row.id">
                <td>{{ row.batch_number }}</td>
                <td>{{ row.code }}</td>
                <td>{{ row.product }}</td>
                <td>{{ row.expiry_date }}</td>
                <td class="text-right text-danger">{{ Math.abs(row.days_to_expiry) }}</td>
                <td class="text-right">{{ row.qte }}</td>
                <td class="text-right">{{ currentUser.currency }} {{ money(row.value) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </b-card>

      <b-card class="mb-4" v-if="expiring.length">
        <h5 class="text-warning mb-3">{{ $t('ExpiringSoon') }}</h5>
        <div class="table-responsive">
          <table class="table table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('BatchNumber') }}</th>
                <th>{{ $t('ProductCode') }}</th>
                <th>{{ $t('ProductName') }}</th>
                <th>{{ $t('ExpiryDate') }}</th>
                <th class="text-right">{{ $t('DaysLeft') }}</th>
                <th class="text-right">{{ $t('Quantity') }}</th>
                <th class="text-right">{{ $t('Value') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in expiring" :key="'x'+row.id">
                <td>{{ row.batch_number }}</td>
                <td>{{ row.code }}</td>
                <td>{{ row.product }}</td>
                <td>{{ row.expiry_date }}</td>
                <td class="text-right text-warning">{{ row.days_to_expiry }}</td>
                <td class="text-right">{{ row.qte }}</td>
                <td class="text-right">{{ currentUser.currency }} {{ money(row.value) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </b-card>

      <b-card v-if="!expired.length && !expiring.length">
        <p class="text-center text-muted mb-0 py-4">{{ $t('NothingExpiringSoon') }}</p>
      </b-card>
    </div>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";

export default {
  metaInfo: {
    title: "Expiry Report"
  },

  data() {
    return {
      isLoading: true,
      warehouses: [],
      warehouse_id: "",
      days: "",
      expired: [],
      expiring: [],
      summary: {
        expired_batches: 0,
        expiring_batches: 0,
        value_expired: 0,
        value_expiring: 0
      }
    };
  },

  computed: {
    ...mapGetters(["currentUser"])
  },

  methods: {
    money(value) {
      return Number(value || 0).toFixed(2);
    },

    Get_Report() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "batches/expiring?warehouse_id=" + (this.warehouse_id || "") +
          (this.days !== "" && this.days !== null ? "&days=" + this.days : "")
        )
        .then(response => {
          this.expired = response.data.expired;
          this.expiring = response.data.expiring;
          this.summary = response.data.summary;
          this.warehouses = response.data.warehouses;
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
    this.Get_Report();
  }
};
</script>
