<template>
  <div class="main-content">
    <breadcumb :page="$t('StockCounts')" :folder="$t('Adjustment')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <vue-good-table
      v-if="!isLoading"
      mode="remote"
      :columns="columns"
      :totalRows="totalRows"
      :rows="counts"
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
      <div slot="table-actions" class="mb-3">
        <router-link
          v-if="currentUserPermissions && currentUserPermissions.includes('stock_count_add')"
          class="btn btn-primary btn-icon m-1"
          to="/app/stock_counts/store"
        >
          <span class="ul-btn__icon"><i class="i-Add"></i></span>
          <span class="ul-btn__text ml-1">{{ $t('Add') }}</span>
        </router-link>
      </div>

      <template slot="table-row" slot-scope="props">
        <span v-if="props.column.field == 'status'">
          <span
            class="badge"
            :class="{
              'badge-outline-secondary': props.row.status === 'draft',
              'badge-outline-warning':   props.row.status === 'in_progress',
              'badge-outline-success':   props.row.status === 'completed',
              'badge-outline-danger':    props.row.status === 'cancelled'
            }"
          >{{ props.row.status }}</span>
        </span>

        <!-- How far through the count they are, at a glance -->
        <span v-else-if="props.column.field == 'progress'">
          {{ props.row.counted }} / {{ props.row.lines }}
        </span>

        <span v-else-if="props.column.field == 'actions'">
          <router-link
            :to="'/app/stock_counts/sheet/' + props.row.id"
            class="cursor-pointer"
            :title="$t('CountSheet')"
          >
            <i class="i-Eye text-25 text-info"></i>
          </router-link>
          <a
            v-if="props.row.status !== 'completed' && currentUserPermissions && currentUserPermissions.includes('stock_count_delete')"
            @click="Remove(props.row)"
            class="cursor-pointer ml-2"
            title="Delete"
          >
            <i class="i-Close-Window text-25 text-danger"></i>
          </a>
        </span>
      </template>
    </vue-good-table>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";

export default {
  metaInfo: {
    title: "Stock Counts"
  },

  data() {
    return {
      isLoading: true,
      serverParams: { page: 1, perPage: 10 },
      limit: "10",
      totalRows: "",
      search: "",
      counts: []
    };
  },

  computed: {
    ...mapGetters(["currentUserPermissions"]),

    columns() {
      return [
        { label: this.$t("date"), field: "date", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Reference"), field: "Ref", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("warehouse"), field: "warehouse", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Counted"), field: "progress", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Status"), field: "status", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("User"), field: "user", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Action"), field: "actions", tdClass: "text-center", thClass: "text-center", sortable: false }
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
        this.Get_Counts(currentPage);
      }
    },

    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) {
        this.limit = currentPerPage;
        this.updateParams({ page: 1, perPage: currentPerPage });
        this.Get_Counts(1);
      }
    },

    onSearch(value) {
      this.search = value.searchTerm;
      this.Get_Counts(1);
    },

    Remove(row) {
      this.$swal({
        title: this.$t("Are_you_sure"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: this.$t("Yes_delete")
      }).then(result => {
        if (result.value) {
          axios
            .delete("stock_counts/" + row.id)
            .then(() => {
              this.Get_Counts(this.serverParams.page);
              this.makeToast("success", this.$t("Successfully_Deleted"), this.$t("Success"));
            })
            .catch(error => {
              const message = error.response && error.response.data && error.response.data.message;
              this.makeToast("danger", message || this.$t("InvalidData"), this.$t("Failed"));
            });
        }
      });
    },

    Get_Counts(page) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("stock_counts?page=" + page + "&limit=" + this.limit + "&search=" + this.search)
        .then(response => {
          this.counts = response.data.stock_counts;
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
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    }
  },

  created() {
    this.Get_Counts(1);
  }
};
</script>
