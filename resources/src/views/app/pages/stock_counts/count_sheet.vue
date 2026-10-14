<template>
  <div class="main-content">
    <breadcumb :page="$t('CountSheet')" :folder="$t('StockCounts')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-card class="mb-3">
        <b-row>
          <b-col md="8">
            <p class="mb-1"><strong>{{ $t('Reference') }}:</strong> {{ count.Ref }}</p>
            <p class="mb-1"><strong>{{ $t('warehouse') }}:</strong> {{ count.warehouse }}</p>
            <p class="mb-1"><strong>{{ $t('date') }}:</strong> {{ count.date }}</p>
            <p class="mb-0">
              <strong>{{ $t('Status') }}:</strong>
              <span
                class="badge"
                :class="{
                  'badge-outline-secondary': count.status === 'draft',
                  'badge-outline-warning':   count.status === 'in_progress',
                  'badge-outline-success':   count.status === 'completed',
                  'badge-outline-danger':    count.status === 'cancelled'
                }"
              >{{ count.status }}</span>
            </p>
          </b-col>
          <b-col md="4" class="text-right">
            <template v-if="!frozen">
              <b-button variant="primary" class="mb-2" :disabled="Processing" @click="Save">
                {{ $t('SaveCounts') }}
              </b-button>
              <br>
              <b-button variant="success" class="mb-2" :disabled="Processing || !anyCounted" @click="Complete">
                {{ $t('CompleteCount') }}
              </b-button>
              <br>
              <b-button variant="outline-danger" size="sm" :disabled="Processing" @click="Cancel">
                {{ $t('CancelCount') }}
              </b-button>
            </template>
            <b-alert v-else variant="secondary" show class="mb-0">
              {{ count.status === 'completed' ? $t('CountCompletedFrozen') : $t('CountCancelled') }}
            </b-alert>
          </b-col>
        </b-row>
      </b-card>

      <!-- Variance is the whole point of the sheet, so it is summarised
           before the rows rather than buried at the bottom. -->
      <b-row class="mb-3" v-if="anyCounted">
        <b-col md="4">
          <b-card class="mb-2">
            <p class="text-muted mb-0">{{ $t('LinesCounted') }}</p>
            <p class="text-24 line-height-1 mb-0">{{ countedLines }} / {{ details.length }}</p>
          </b-card>
        </b-col>
        <b-col md="4">
          <b-card class="mb-2">
            <p class="text-muted mb-0">{{ $t('LinesWithVariance') }}</p>
            <p class="text-24 line-height-1 mb-0 text-warning">{{ varianceLines }}</p>
          </b-card>
        </b-col>
        <b-col md="4">
          <b-card class="mb-2">
            <p class="text-muted mb-0">{{ $t('NetVariance') }}</p>
            <p
              class="text-24 line-height-1 mb-0"
              :class="netVariance < 0 ? 'text-danger' : (netVariance > 0 ? 'text-success' : '')"
            >{{ netVariance > 0 ? '+' : '' }}{{ netVariance }}</p>
          </b-card>
        </b-col>
      </b-row>

      <b-card>
        <b-form-input
          v-model="searchQuery"
          type="search"
          class="mb-3"
          style="max-width: 420px"
          :placeholder="$t('Search') + ' ' + $t('ProductName') + ' / ' + $t('ProductCode') + ' / ' + $t('BatchNumber')"
          :aria-label="$t('Search') + ' ' + $t('ProductName')"
        />
        <div class="table-responsive">
          <table class="table table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('ProductName') }}</th>
                <th>{{ $t('BatchNumber') }}</th>
                <th class="text-right">{{ $t('SystemQty') }}</th>
                <th style="width:140px">{{ $t('CountedQty') }}</th>
                <th class="text-right">{{ $t('Variance') }}</th>
                <th style="width:200px">{{ $t('Note') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!filteredDetails.length">
                <td colspan="6">{{ $t('NoDataAvailable') }}</td>
              </tr>
              <tr v-for="line in filteredDetails" :key="line.id">
                <td>
                  <span class="text-muted">{{ line.code }}</span><br>{{ line.name }}
                </td>
                <td>
                  <!-- A batch-tracked product gets one row per lot, so the lot
                       and its expiry belong on the row being counted. -->
                  <template v-if="line.batch_number">
                    {{ line.batch_number }}<br>
                    <small
                      class="badge"
                      :class="{
                        'badge-outline-danger':  line.expiry_status === 'expired',
                        'badge-outline-warning': line.expiry_status === 'expiring',
                        'badge-outline-success': line.expiry_status === 'ok'
                      }"
                    >{{ line.expiry_date }}</small>
                  </template>
                  <span v-else class="text-muted">—</span>
                </td>
                <td class="text-right">{{ line.system_qte }}</td>
                <td>
                  <b-form-input
                    type="number"
                    min="0"
                    step="any"
                    :disabled="frozen"
                    v-model.number="line.counted_qte"
                    :placeholder="$t('NotCounted')"
                  />
                </td>
                <td class="text-right">
                  <span
                    v-if="variance(line) !== null"
                    :class="variance(line) < 0 ? 'text-danger' : (variance(line) > 0 ? 'text-success' : '')"
                  >{{ variance(line) > 0 ? '+' : '' }}{{ variance(line) }}</span>
                  <span v-else class="text-muted">—</span>
                </td>
                <td>
                  <b-form-input size="sm" :disabled="frozen" v-model="line.notes"/>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </b-card>
    </div>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: {
    title: "Count Sheet"
  },

  data() {
    return {
      isLoading: true,
      Processing: false,
      count: {},
      searchQuery: "",
      details: []
    };
  },

  computed: {
    filteredDetails() {
      const query = this.searchQuery.trim().toLowerCase();
      if (!query) return this.details;

      return this.details.filter(line =>
        [line.code, line.name, line.batch_number].some(value =>
          String(value == null ? "" : value).toLowerCase().includes(query)
        )
      );
    },

    /** A completed or cancelled count is history and cannot be edited. */
    frozen() {
      return this.count.status === "completed" || this.count.status === "cancelled";
    },

    countedLines() {
      return this.details.filter(l => l.counted_qte !== null && l.counted_qte !== "").length;
    },

    anyCounted() {
      return this.countedLines > 0;
    },

    varianceLines() {
      return this.details.filter(l => {
        const v = this.variance(l);
        return v !== null && v !== 0;
      }).length;
    },

    netVariance() {
      const total = this.details.reduce((sum, l) => {
        const v = this.variance(l);
        return sum + (v === null ? 0 : v);
      }, 0);
      return Math.round(total * 1000000) / 1000000;
    }
  },

  methods: {
    /** Null while a line is uncounted — "nobody counted this" is not zero. */
    variance(line) {
      if (line.counted_qte === null || line.counted_qte === "") {
        return null;
      }
      return Math.round((Number(line.counted_qte) - Number(line.system_qte)) * 1000000) / 1000000;
    },

    Get_Sheet() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("stock_counts/" + this.$route.params.id)
        .then(response => {
          this.count = response.data.stock_count;
          this.details = response.data.details;
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

    /** Only send lines someone actually touched. */
    countedPayload() {
      return this.details
        .filter(l => l.counted_qte !== null && l.counted_qte !== "")
        .map(l => ({
          id: l.id,
          counted_qte: l.counted_qte,
          notes: l.notes || null
        }));
    },

    Save(silent) {
      const details = this.countedPayload();

      if (!details.length) {
        this.makeToast("warning", this.$t("NothingCountedYet"), this.$t("Warning"));
        return Promise.reject();
      }

      this.Processing = true;
      return axios
        .put("stock_counts/" + this.$route.params.id, { details: details })
        .then(() => {
          this.Processing = false;
          if (silent !== true) {
            this.count.status = "in_progress";
            this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
          }
        })
        .catch(error => {
          this.Processing = false;
          const data = error.response && error.response.data;
          this.makeToast("danger", (data && data.message) || this.$t("InvalidData"), this.$t("Failed"));
          throw error;
        });
    },

    /**
     * Completing applies the variances to stock, so the counts are saved first
     * and the user is told what is about to change.
     */
    Complete() {
      this.$swal({
        title: this.$t("Are_you_sure"),
        text: this.$t("CompletingAppliesVariances", {
          lines: this.varianceLines,
          net: this.netVariance
        }),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: this.$t("Yes")
      }).then(result => {
        if (!result.value) return;

        this.Save(true)
          .then(() => {
            this.Processing = true;
            return axios.post("stock_counts/" + this.$route.params.id + "/complete", {});
          })
          .then(response => {
            this.Processing = false;
            this.makeToast(
              "success",
              this.$t("AdjustedLines") + ": " + response.data.adjusted_lines,
              this.$t("Success")
            );
            this.Get_Sheet();
          })
          .catch(error => {
            this.Processing = false;
            if (!error || !error.response) return;
            const data = error.response.data;
            this.makeToast("danger", (data && data.message) || this.$t("InvalidData"), this.$t("Failed"));
          });
      });
    },

    Cancel() {
      this.$swal({
        title: this.$t("Are_you_sure"),
        text: this.$t("CancellingLeavesStockUntouched"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: this.$t("Yes")
      }).then(result => {
        if (!result.value) return;

        this.Processing = true;
        axios
          .post("stock_counts/" + this.$route.params.id + "/cancel", {})
          .then(() => {
            this.Processing = false;
            this.Get_Sheet();
            this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
          })
          .catch(error => {
            this.Processing = false;
            const data = error.response && error.response.data;
            this.makeToast("danger", (data && data.message) || this.$t("InvalidData"), this.$t("Failed"));
          });
      });
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    }
  },

  created() {
    this.Get_Sheet();
  }
};
</script>
