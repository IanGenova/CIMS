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
              <th>QC status</th>
              <th class="text-right">Passed / Failed</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="l in detail.details" :key="'d'+l.id">
              <td>{{ l.code }} — {{ l.name }}</td>
              <td class="text-right">{{ l.ordered_qte }}</td>
              <td class="text-right text-success">{{ l.received_qte }}</td>
              <td class="text-right text-danger">{{ l.rejected_qte }}</td>
              <td>{{ l.qc_status }}<small v-if="l.qc_decider" class="d-block text-muted">{{ l.qc_decider }} · {{ l.qc_decided_at }}</small><small v-if="l.qc_notes" class="d-block text-muted">{{ l.qc_notes }}</small></td>
              <td class="text-right">{{ l.qc_passed_qte }} / {{ l.qc_failed_qte }}</td>
            </tr>
          </tbody>
        </table>

        <div v-for="l in detail.details" :key="'qc'+l.id" v-if="l.qc_status === 'pending'" class="border rounded p-3 mb-3">
          <h6>Inspect {{ l.code }} — {{ l.name }}</h6>
          <p class="text-muted mb-2">{{ l.received_qte }} units are held out of sellable stock.</p>
          <template v-if="currentUserPermissions && currentUserPermissions.includes('goods_receipt_qc_approve')">
            <b-row>
              <b-col md="3"><b-form-group label="Passed"><b-form-input type="number" min="0" step="any" v-model.number="l.qc_passed_input" :disabled="l.qc_serials.length > 0" /></b-form-group></b-col>
              <b-col md="3"><b-form-group label="Failed"><b-form-input type="number" min="0" step="any" v-model.number="l.qc_failed_input" :disabled="l.qc_serials.length > 0" /></b-form-group></b-col>
              <b-col md="6"><b-form-group label="Inspection notes / failure reason"><b-form-input v-model="l.qc_notes_input" /></b-form-group></b-col>
            </b-row>
            <div v-if="l.qc_serials.length" class="mb-2">
              <div class="small text-muted mb-1">Select the serials that passed. Unselected units fail QC.</div>
              <b-form-checkbox v-for="serial in l.qc_serials" :key="serial" v-model="l.accepted_serials" :value="serial" @change="syncSerialQc(l)">{{ serial }}</b-form-checkbox>
            </div>
            <b-button variant="success" :disabled="qcProcessing" @click="Decide_Qc(l)">Record QC decision</b-button>
          </template>
          <p v-else class="text-muted mb-0">Waiting for a user with QC approver permission.</p>
        </div>
        <div v-for="l in detail.details" :key="'disposition'+l.id" v-if="Number(l.qc_failed_qte) > 0" class="border rounded p-3 mb-3">
          <h6>Failed goods disposition · {{ l.code }} — {{ l.name }}</h6>
          <p class="text-muted">{{ l.qc_failed_qte }} failed · {{ l.qc_disposition_remaining }} awaiting a disposition request. Failed goods are outside sellable stock.</p>
          <div v-for="d in l.qc_dispositions" :key="d.id" class="border-bottom py-2">
            <strong>{{ d.action === 'supplier_return' ? 'Supplier return' : 'Write-off' }}</strong> · {{ Number(d.quantity) }} · {{ d.status }}
            <div class="small">Requested by {{ d.requester }}: {{ d.reason }}<span v-if="d.approver"> · Decided by {{ d.approver }}<span v-if="d.decision_reason">: {{ d.decision_reason }}</span></span></div>
            <div v-if="d.status === 'pending' && currentUserPermissions && currentUserPermissions.includes('goods_receipt_qc_disposition_approve')" class="mt-1">
              <b-button size="sm" variant="success" :disabled="qcProcessing" @click="Decide_Disposition(d, 'approve')">Approve</b-button>
              <b-button size="sm" variant="outline-danger" :disabled="qcProcessing" @click="Decide_Disposition(d, 'reject')">Reject</b-button>
            </div>
          </div>
          <b-row v-if="l.qc_disposition_remaining > 0 && currentUserPermissions && currentUserPermissions.includes('goods_receipt_qc_disposition_request')" class="mt-2">
            <b-col md="3"><b-form-group label="Action"><b-form-select v-model="l.disposition_action" :options="[{ value: 'supplier_return', text: 'Return to supplier' }, { value: 'write_off', text: 'Write off' }]" /></b-form-group></b-col>
            <b-col md="2"><b-form-group label="Quantity"><b-form-input type="number" min="0.000001" step="any" :max="l.qc_disposition_remaining" v-model.number="l.disposition_quantity" /></b-form-group></b-col>
            <b-col md="5"><b-form-group label="Reason"><b-form-input v-model="l.disposition_reason" /></b-form-group></b-col>
            <b-col md="2" class="d-flex align-items-center"><b-button variant="primary" :disabled="qcProcessing" @click="Request_Disposition(l)">Request</b-button></b-col>
          </b-row>
          <div v-if="l.qc_failed_serials.length && l.qc_disposition_remaining > 0" class="small mb-2">
            <div>Select the failed serial numbers in this request:</div>
            <b-form-checkbox v-for="serial in l.qc_failed_serials" :key="serial" v-model="l.disposition_serials" :value="serial" @change="syncDispositionSerials(l)">{{ serial }}</b-form-checkbox>
          </div>
        </div>
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
      detail: null,
      qcProcessing: false
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
        this.detail.details.forEach(l => {
          this.$set(l, "qc_passed_input", l.received_qte);
          this.$set(l, "qc_failed_input", 0);
          this.$set(l, "qc_notes_input", "");
          this.$set(l, "accepted_serials", l.qc_serials.slice());
          this.$set(l, "disposition_action", "supplier_return");
          this.$set(l, "disposition_quantity", l.qc_disposition_remaining);
          this.$set(l, "disposition_reason", "");
          this.$set(l, "disposition_serials", []);
        });
        this.$bvModal.show("grn_detail");
      });
    },

    syncSerialQc(line) {
      this.$nextTick(() => {
        line.qc_passed_input = line.accepted_serials.length / line.base_units_per;
        line.qc_failed_input = line.received_qte - line.qc_passed_input;
      });
    },

    Decide_Qc(line) {
      this.qcProcessing = true;
      axios.post("goods_receipts/" + this.detail.goods_receipt.id + "/qc", {
        receipt_detail_id: line.id,
        passed_qte: line.qc_passed_input,
        failed_qte: line.qc_failed_input,
        notes: line.qc_notes_input,
        accepted_serials: line.accepted_serials
      }).then(() => {
        const receiptId = this.detail.goods_receipt.id;
        this.Show({ id: receiptId });
        this.Get_Receipts(this.serverParams.page);
        this.makeToast("success", "QC decision recorded", this.$t("Success"));
      }).catch(error => {
        const data = error.response && error.response.data;
        const first = data && data.errors ? Object.values(data.errors)[0] : null;
        this.makeToast("danger", (first && first[0]) || (data && data.message) || this.$t("InvalidData"), this.$t("Failed"));
      }).finally(() => { this.qcProcessing = false; });
    },

    Request_Disposition(line) {
      this.qcProcessing = true;
      axios.post("goods_receipts/" + this.detail.goods_receipt.id + "/qc_dispositions", {
        receipt_detail_id: line.id, action: line.disposition_action,
        quantity: line.disposition_quantity, reason: line.disposition_reason,
        serial_numbers: line.disposition_serials
      }).then(() => {
        this.Show({ id: this.detail.goods_receipt.id });
        this.makeToast("success", "Disposition sent for manager approval", this.$t("Success"));
      }).catch(this.showQcError).finally(() => { this.qcProcessing = false; });
    },

    syncDispositionSerials(line) {
      this.$nextTick(() => { line.disposition_quantity = line.disposition_serials.length / line.base_units_per; });
    },

    Decide_Disposition(disposition, decision) {
      const reason = decision === "reject" ? window.prompt("Reason for rejection") : "";
      if (decision === "reject" && !reason) return;
      this.qcProcessing = true;
      axios.post("qc_dispositions/" + disposition.id + "/decision", { decision, reason })
        .then(() => {
          this.Show({ id: this.detail.goods_receipt.id });
          this.makeToast("success", "Disposition decision recorded", this.$t("Success"));
        }).catch(this.showQcError).finally(() => { this.qcProcessing = false; });
    },

    showQcError(error) {
      const data = error.response && error.response.data;
      const first = data && data.errors ? Object.values(data.errors)[0] : null;
      this.makeToast("danger", (first && first[0]) || (data && data.message) || this.$t("InvalidData"), this.$t("Failed"));
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
