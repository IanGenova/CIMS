<template>
  <div class="main-content">
    <breadcumb :page="$t('OpenStockCount')" :folder="$t('StockCounts')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-card v-if="!isLoading">
      <b-row>
        <b-col md="4">
          <b-form-group :label="$t('warehouse')">
            <v-select
              v-model="warehouse_id"
              :reduce="label => label.value"
              :placeholder="$t('Choose_Warehouse')"
              :options="warehouses.map(w => ({label: w.name, value: w.id}))"
            />
          </b-form-group>
        </b-col>

        <b-col md="4">
          <!-- Counting a whole warehouse in one go is rarely practical; a
               category narrows it to something a person can finish. -->
          <b-form-group :label="$t('Category') + ' (' + $t('Optional') + ')'">
            <v-select
              v-model="category_id"
              :reduce="label => label.value"
              :placeholder="$t('WholeWarehouse')"
              :options="categories.map(c => ({label: c.name, value: c.id}))"
            />
          </b-form-group>
        </b-col>

        <b-col md="4">
          <b-form-group :label="$t('date')">
            <b-form-input type="date" v-model="date"/>
          </b-form-group>
        </b-col>

        <b-col md="12">
          <b-form-group :label="$t('Note')">
            <b-form-textarea v-model="notes" rows="2"/>
          </b-form-group>
        </b-col>
      </b-row>

      <b-alert variant="info" show class="mt-2">
        {{ $t('OpeningACountSnapshots') }}
      </b-alert>

      <b-button
        variant="primary"
        :disabled="SubmitProcessing || !warehouse_id"
        @click="Submit"
      >{{ $t('Submit') }}</b-button>
      <div v-once class="typo__p" v-if="SubmitProcessing">
        <div class="spinner sm spinner-primary mt-3"></div>
      </div>
    </b-card>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { startingWarehouse } from "../../../../utils/defaultWarehouse";

export default {
  metaInfo: {
    title: "Open Stock Count"
  },

  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      warehouses: [],
      categories: [],
      warehouse_id: "",
      category_id: "",
      date: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10),
      notes: ""
    };
  },

  methods: {
    Get_Data() {
      NProgress.start();
      NProgress.set(0.1);
      // The count list already carries the warehouses; categories come from
      // the product endpoint that the product form uses.
      axios
        // Both lists come from the stock count endpoint, so a stockman needs
        // no product-editing permission to open a count.
        .all([axios.get("stock_counts?limit=1")])
        .then(
          axios.spread(counts => {
            this.warehouses = counts.data.warehouses;
            // The user's only warehouse (or the shop default they may use) is preselected.
            const start = startingWarehouse(this.warehouses, this.warehouse_id);
            if (start) {
              this.warehouse_id = start;
            }
            this.categories = counts.data.categories;
            NProgress.done();
            this.isLoading = false;
          })
        )
        .catch(() => {
          NProgress.done();
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    Submit() {
      this.SubmitProcessing = true;
      NProgress.start();
      NProgress.set(0.1);

      axios
        .post("stock_counts", {
          date: this.date,
          warehouse_id: this.warehouse_id,
          category_id: this.category_id || null,
          notes: this.notes
        })
        .then(response => {
          NProgress.done();
          this.SubmitProcessing = false;

          if (!response.data.lines) {
            this.makeToast("warning", this.$t("NothingToCount"), this.$t("Warning"));
          }

          // Straight into the sheet: opening a count and then counting it is
          // one task, not two.
          this.$router.push("/app/stock_counts/sheet/" + response.data.id);
        })
        .catch(error => {
          NProgress.done();
          this.SubmitProcessing = false;
          const data = error.response && error.response.data;
          this.makeToast(
            "danger",
            (data && data.message) || this.$t("InvalidData"),
            this.$t("Failed")
          );
        });
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    }
  },

  created() {
    this.Get_Data();
  }
};
</script>
