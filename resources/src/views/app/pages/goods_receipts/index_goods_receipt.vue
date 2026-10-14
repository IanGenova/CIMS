<template>
  <div class="main-content">
    <breadcumb :page="$t('GoodsReceipts')" :folder="$t('Purchases')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <vue-good-table
      v-if="!isLoading"
      mode="remote"
      :columns="columns"
      :totalRows="totalRows"
      :rows="receipts"
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
          v-if="currentUserPermissions && currentUserPermissions.includes('goods_receipt_add')"
          class="btn btn-primary btn-icon m-1"
          to="/app/goods_receipts/store"
        >
          <span class="ul-btn__icon"><i class="i-Add"></i></span>
          <!-- 'Create' is a nested group of toast messages in the locale
               files, not a label; 'Add' is the key the other list pages use. -->
          <span class="ul-btn__text ml-1">{{ $t('Add') }}</span>
        </router-link>
      </div>

      <template slot="table-row" slot-scope="props">
        <span v-if="props.column.field == 'status'">
          <span
            class="badge"
            :class="props.row.status === 'received' ? 'badge-outline-success' : 'badge-outline-warning'"
          >{{ props.row.status }}</span>
        </span>

        <span v-else-if="props.column.field == 'actions'">
          <a @click="Show(props.row)" class="cursor-pointer" :title="$t('Details')">
            <i class="i-Eye text-25 text-info"></i>
          </a>
          <a
            v-if="currentUserPermissions && currentUserPermissions.includes('goods_receipt_delete')"
            @click="Remove(props.row)"
            class="cursor-pointer ml-2"
            title="Delete"
          >
            <i class="i-Close-Window text-25 text-danger"></i>
          </a>
        </span>
      </template>
    </vue-good-table>

    <b-modal hide-footer size="lg" id="grn_detail" :title="$t('GoodsReceipt')">
      <div v-if="detail">
        <b-row class="mb-3">
          <b-col md="6">
            <p class="mb-1"><strong>{{ $t('Reference') }}:</strong> {{ detail.goods_receipt.Ref }}</p>
            <p class="mb-1"><strong>{{ $t('PurchaseRef') }}:</strong> {{ detail.goods_receipt.purchase_ref }}</p>
            <p class="mb-1"><strong>{{ $t('DeliveryNote') }}:</strong> {{ detail.goods_receipt.delivery_note || '—' }}</p>
          </b-col>
          <b-col md="6">
            <p class="mb-1"><strong>{{ $t('date') }}:</strong> {{ detail.goods_receipt.date }}</p>
            <p class="mb-1"><strong>{{ $t('warehouse') }}:</strong> {{ detail.goods_receipt.warehouse }}</p>
            <p class="mb-1"><strong>{{ $t('User') }}:</strong> {{ detail.goods_receipt.user }}</p>
          </b-col>
        </b-row>
        <table class="table table-sm">
          <thead>
            <tr>
              <th>{{ $t('ProductName') }}</th>
              <th class="text-right">{{ $t('Ordered') }}</th>
              <th class="text-right">{{ $t('Received') }}</th>
              <th class="text-right">{{ $t('Rejected') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="l in detail.details" :key="'d'+l.id">
              <td>{{ l.code }} — {{ l.name }}</td>
              <td class="text-right">{{ l.ordered_qte }}</td>
              <td class="text-right text-success">{{ l.received_qte }}</td>
              <td class="text-right text-danger">{{ l.rejected_qte }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";

export default {
  metaInfo: {
    title: "Goods Receipts"
  },

  data() {
    return {
      isLoading: true,
      serverParams: { page: 1, perPage: 10 },
      limit: "10",
      totalRows: "",
      search: "",
      receipts: [],
      detail: null
    };
  },

  computed: {
    ...mapGetters(["currentUserPermissions"]),

    columns() {
      return [
        { label: this.$t("date"), field: "date", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Reference"), field: "Ref", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("PurchaseRef"), field: "purchase_ref", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("DeliveryNote"), field: "delivery_note", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("warehouse"), field: "warehouse", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: this.$t("Status"), field: "status", tdClass: "text-left", thClass: "text-left", sortable: false },
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
        this.Get_Receipts(currentPage);
      }
    },

    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) {
        this.limit = currentPerPage;
        this.updateParams({ page: 1, perPage: currentPerPage });
        this.Get_Receipts(1);
      }
    },

    onSearch(value) {
      this.search = value.searchTerm;
      this.Get_Receipts(1);
    },

    Show(row) {
      axios.get("goods_receipts/" + row.id).then(response => {
        this.detail = response.data;
        this.$bvModal.show("grn_detail");
      });
    },

    /**
     * Deleting a receipt takes the stock back out — including out of the exact
     * lots it went into — so it is worth confirming.
     */
    Remove(row) {
      this.$swal({
        title: this.$t("Are_you_sure"),
        text: this.$t("ReversingReceiptRemovesStock"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: this.$t("Yes_delete")
      }).then(result => {
        if (result.value) {
          axios
            .delete("goods_receipts/" + row.id)
            .then(() => {
              this.Get_Receipts(this.serverParams.page);
              this.makeToast("success", this.$t("Successfully_Deleted"), this.$t("Success"));
            })
            .catch(error => {
              const message = error.response && error.response.data && error.response.data.message;
              this.makeToast("danger", message || this.$t("InvalidData"), this.$t("Failed"));
            });
        }
      });
    },

    Get_Receipts(page) {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("goods_receipts?page=" + page + "&limit=" + this.limit + "&search=" + this.search)
        .then(response => {
          this.receipts = response.data.goods_receipts;
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
    this.Get_Receipts(1);
  }
};
</script>
