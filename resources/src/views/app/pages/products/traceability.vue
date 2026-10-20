<template>
  <div class="main-content">
    <breadcumb page="Trace Explorer" :folder="$t('Products')" />
    <b-row v-if="overview" class="mb-3">
      <b-col sm="6" lg="3" class="mb-2"><b-card><div class="text-muted">Pending QC lines</div><h3><b-button variant="link" class="p-0" @click="showExceptions('pending_qc_lines')">{{ overview.exceptions.pending_qc_lines }}</b-button></h3></b-card></b-col>
      <b-col sm="6" lg="3" class="mb-2"><b-card><div class="text-muted">Returned stock awaiting QC</div><h3><b-button variant="link" class="p-0" @click="showExceptions('pending_return_qc_items')">{{ overview.exceptions.pending_return_qc_items }}</b-button></h3></b-card></b-col>
      <b-col sm="6" lg="3" class="mb-2"><b-card><div class="text-muted">Rejected goods in custody</div><h3><b-button variant="link" class="p-0" @click="showExceptions('rejected_custody_items')">{{ overview.exceptions.rejected_custody_items }}</b-button></h3></b-card></b-col>
      <b-col sm="6" lg="3" class="mb-2"><b-card><div class="text-muted">Lots with unknown source</div><h3><b-button variant="link" class="p-0" @click="showExceptions('source_unknown_lots')">{{ overview.exceptions.source_unknown_lots }}</b-button></h3></b-card></b-col>
      <b-col sm="6" lg="3" class="mb-2"><b-card><div class="text-muted">Serials with unknown source</div><h3><b-button variant="link" class="p-0" @click="showExceptions('source_unknown_serials')">{{ overview.exceptions.source_unknown_serials }}</b-button></h3></b-card></b-col>
      <b-col sm="6" lg="3" class="mb-2"><b-card><div class="text-muted">Expired lots with stock</div><h3><b-button variant="link" class="p-0" @click="showExceptions('expired_lots_with_stock')">{{ overview.exceptions.expired_lots_with_stock }}</b-button></h3></b-card></b-col>
      <b-col sm="6" lg="3" class="mb-2"><b-card><div class="text-muted">Lot balance mismatches</div><h3><b-button variant="link" class="p-0" @click="showExceptions('lot_balance_mismatches')">{{ overview.exceptions.lot_balance_mismatches }}</b-button></h3></b-card></b-col>
      <b-col cols="12"><b-card title="Stock and sales by company location"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Code</th><th>Location</th><th>Type</th><th class="text-right">On hand units</th><th class="text-right">Reserved</th><th class="text-right">Available to promise</th><th class="text-right">In transit here</th><th class="text-right">Returned QC hold</th><th class="text-right">Rejected custody hold</th><th class="text-right">Low stock products</th><th class="text-right">Completed sales (30 days)</th><th class="text-right">Sales value (30 days)</th></tr></thead><tbody>
        <tr v-for="w in overview.locations" :key="w.id"><td>{{ w.code }}</td><td>{{ w.name }}</td><td>{{ w.site_type }}</td><td class="text-right">{{ Number(w.on_hand) }}</td><td class="text-right">{{ Number(w.reserved) }}</td><td class="text-right">{{ Number(w.available_to_promise) }}</td><td class="text-right">{{ Number(w.in_transit_to_location) }}</td><td class="text-right">{{ Number(w.return_qc_hold) }}</td><td class="text-right">{{ Number(w.rejected_qc_hold) }}</td><td class="text-right"><b-button variant="link" class="p-0" @click="showExceptions('low_stock', w.id)">{{ w.low_stock_products }}</b-button></td><td class="text-right">{{ w.sales_30d_count === null ? 'Restricted' : w.sales_30d_count }}</td><td class="text-right">{{ w.sales_30d_total === null ? 'Restricted' : Number(w.sales_30d_total).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</td></tr>
      </tbody></table></div></b-card></b-col>
    </b-row>
    <b-card v-if="exceptionType" class="mb-3" id="exception-detail">
      <div class="d-flex justify-content-between align-items-center"><h5>{{ exceptionTitle }}</h5><b-button size="sm" variant="outline-secondary" @click="exceptionType = ''">Close</b-button></div>
      <p v-if="exceptionWarehouseId" class="text-muted">Filtered to one location.</p>
      <b-alert v-if="exceptionMore" show variant="warning">Showing the first 100 entries. Investigate or narrow by location for more.</b-alert>
      <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Product</th><th>Location</th><th>Details</th><th class="text-right">Quantity</th></tr></thead><tbody>
        <tr v-for="(row, i) in exceptionRows" :key="i"><td>{{ row.product_code }} · {{ row.product }}</td><td>{{ row.warehouse || '—' }}</td><td>
          <span v-if="row.movement_balance !== undefined">Ledger {{ Number(row.movement_balance) }} / stock {{ Number(row.stock_balance) }} · </span><b-button v-if="row.batch_number" variant="link" class="p-0" @click="openBatch(row)">Lot {{ row.batch_number }} #{{ row.id }}</b-button>
          <b-button v-else-if="row.serial_number" variant="link" class="p-0" @click="openSerial(row)">{{ row.serial_number }}</b-button>
          <span v-else-if="row.receipt_ref">{{ row.receipt_ref }} · {{ row.date }}</span>
          <span v-else-if="row.shipment_ref">{{ row.shipment_ref }}<span v-if="!row.location"> · awaiting return inspection</span></span>
          <span v-else>Alert at {{ row.threshold }} units</span><span v-if="row.location"> · held at {{ row.location }}</span>
        </td><td class="text-right">{{ row.quantity === undefined ? (row.stock_balance === undefined ? '—' : Number(row.stock_balance)) : Number(row.quantity) }}</td></tr>
        <tr v-if="!exceptionRows.length"><td colspan="4">No matching entries.</td></tr>
      </tbody></table></div>
    </b-card>
    <b-card class="mb-3">
      <p class="text-muted mb-2">Find a tracked product by code, batch, serial, purchase order, goods receipt, or sale reference.</p>
      <b-form @submit.prevent="search">
        <b-input-group>
          <b-form-input v-model.trim="query" placeholder="Scan or enter a code or document reference" autofocus />
          <b-input-group-append><b-button type="submit" variant="primary" :disabled="busy || query.length < 2">Search</b-button></b-input-group-append>
        </b-input-group>
      </b-form>
      <small class="text-muted">Network-wide tracing is available to staff with access to all warehouses.</small>
    </b-card>

    <b-alert v-if="error" show variant="danger">{{ error }}</b-alert>
    <b-alert v-if="searched && !batches.length && !serials.length && !busy" show variant="info">No tracked stock matches this search.</b-alert>

    <b-row v-if="searched">
      <b-col lg="6" class="mb-3">
        <b-card title="Batches / receipt lots">
          <p v-if="batches.length === 40" class="text-warning">Showing the first 40 matches. Narrow the search for more.</p>
          <div v-for="lot in batches" :key="'b'+lot.id" class="border-bottom py-2">
            <b-button variant="link" class="p-0 text-left" @click="openBatch(lot)">{{ lot.batch_number }} · {{ lot.product_code }}</b-button>
            <div class="small">{{ lot.product }}</div>
            <div class="small text-muted">{{ lot.receipt_ref || 'Source unknown / historical' }} · {{ lot.purchase_ref || 'No linked PO' }} · lot ID {{ lot.id }}</div>
          </div>
          <p v-if="!batches.length" class="text-muted mb-0">No matching batches.</p>
        </b-card>
      </b-col>
      <b-col lg="6" class="mb-3">
        <b-card title="Serial numbers">
          <p v-if="serials.length === 40" class="text-warning">Showing the first 40 matches. Narrow the search for more.</p>
          <div v-for="unit in serials" :key="'s'+unit.id" class="border-bottom py-2">
            <b-button variant="link" class="p-0 text-left" @click="openSerial(unit)">{{ unit.serial_number }} · {{ unit.product_code }}</b-button>
            <div class="small">{{ unit.product }} · {{ unit.status }}</div>
            <div class="small text-muted">{{ unit.receipt_ref || 'Source unknown / historical' }} · {{ unit.purchase_ref || 'No linked PO' }}<span v-if="unit.sale_ref"> · {{ unit.sale_ref }}</span></div>
          </div>
          <p v-if="!serials.length" class="text-muted mb-0">No matching serials.</p>
        </b-card>
      </b-col>
    </b-row>

    <b-card v-if="trace" class="mb-3" id="trace-detail">
      <h4>{{ trace.batch.product }} · {{ trace.batch.batch_number }}</h4>
      <p class="text-muted">Product {{ trace.batch.code }} · internal lot ID {{ trace.batch.id }}</p>
      <b-alert :show="!trace.batch.source" variant="warning">Receipt source unknown for this historical lot.</b-alert>
      <b-row v-if="trace.batch.source" class="mb-3">
        <b-col md="6"><strong>Supplier → PO → Receipt</strong><br />{{ trace.batch.source.supplier || 'Supplier unknown' }} → {{ trace.batch.source.purchase_ref || 'PO unknown' }} → {{ trace.batch.source.goods_receipt_ref || 'Receipt unknown' }}<br /><small>Received {{ trace.batch.source.goods_receipt_date }} at {{ trace.batch.source.receiving_warehouse || 'Unknown location' }} · receipt line {{ trace.batch.source.goods_receipt_detail_id }}</small></b-col>
        <b-col md="6"><strong>Quality check</strong><br />{{ trace.batch.source.qc_status }} · passed {{ trace.batch.source.qc_passed_qte }} / failed {{ trace.batch.source.qc_failed_qte }}<br /><small>{{ trace.batch.source.qc_decider || 'No separate QC decision recorded' }} {{ trace.batch.source.qc_decided_at || '' }}<span v-if="trace.batch.source.qc_notes"> · {{ trace.batch.source.qc_notes }}</span></small></b-col>
      </b-row>
      <h5>Current locations</h5>
      <table class="table table-sm"><thead><tr><th>Location</th><th class="text-right">On hand units</th></tr></thead><tbody>
        <tr v-for="s in trace.stock_on_hand" :key="'stock'+s.warehouse_id"><td>{{ s.warehouse }}</td><td class="text-right">{{ s.qte }}</td></tr>
        <tr v-if="!trace.stock_on_hand.length"><td colspan="2">No on hand stock</td></tr>
      </tbody></table>
      <b-alert :show="true" :variant="trace.reconciliation.in_sync ? 'success' : 'danger'">Movement balance {{ trace.reconciliation.movement_balance }} · location stock {{ trace.reconciliation.stock_balance }} · {{ trace.reconciliation.in_sync ? 'Reconciled' : 'Mismatch — investigate' }}</b-alert>
      <h5>Documents that moved or consumed this lot</h5>
      <div class="table-responsive"><table class="table table-sm"><thead><tr><th>When</th><th>Action</th><th>Location</th><th class="text-right">Units</th><th>Document</th><th>Actor / customer</th></tr></thead><tbody>
        <tr v-for="m in trace.movements" :key="'move'+m.id"><td>{{ m.date }}</td><td>{{ m.type }}</td><td>{{ m.warehouse || '—' }}</td><td class="text-right" :class="m.qte < 0 ? 'text-danger' : 'text-success'">{{ m.qte }}</td><td>{{ m.reference_label || m.reference || '—' }}</td><td>{{ m.by || '—' }}<span v-if="m.customer"> · {{ m.customer }}</span></td></tr>
        <tr v-if="!trace.movements.length"><td colspan="6">No movement records</td></tr>
      </tbody></table></div>
    </b-card>

    <b-card v-if="serialTrace" class="mb-3" id="trace-detail">
      <h4>{{ serialTrace.serial_number }} · {{ serialTrace.product }}</h4>
      <p>Status: {{ serialTrace.status }}<span v-if="serialTrace.warehouse"> · {{ serialTrace.warehouse }}</span><span v-if="serialTrace.customer"> · Customer: {{ serialTrace.customer }}</span></p>
      <p v-if="serialTrace.source"><strong>Supplier → PO → Receipt:</strong> {{ serialTrace.source.supplier || 'Unknown' }} → {{ serialTrace.source.purchase_ref || 'Unknown' }} → {{ serialTrace.source.goods_receipt_ref || 'Unknown' }}<br /><strong>QC:</strong> {{ serialTrace.source.qc_status }} · {{ serialTrace.source.qc_decider || 'No separate decision' }}</p>
      <b-alert v-else show variant="warning">Receipt source unknown for this historical serial.</b-alert>
      <table class="table table-sm"><thead><tr><th>When</th><th>Event</th><th>Location</th><th>Document</th><th>Actor</th></tr></thead><tbody>
        <tr v-for="(e, i) in serialTrace.history" :key="i"><td>{{ e.created_at }}</td><td>{{ e.event }}</td><td>{{ e.warehouse }}</td><td>{{ e.reference_type }} #{{ e.reference_id }}</td><td>{{ e.by }}</td></tr>
      </tbody></table>
    </b-card>
  </div>
</template>

<script>
export default {
  metaInfo: { title: "Trace Explorer" },
  data() { return { query: this.$route.query.q || "", searched: false, busy: false, error: "", overview: null, batches: [], serials: [], trace: null, serialTrace: null, exceptionType: "", exceptionWarehouseId: null, exceptionRows: [], exceptionMore: false }; },
  computed: {
    exceptionTitle() {
      return { pending_qc_lines: "Pending QC lines", pending_return_qc_items: "Returned stock awaiting QC", rejected_custody_items: "Rejected goods in custody", source_unknown_lots: "Lots with unknown source", source_unknown_serials: "Serials with unknown source", expired_lots_with_stock: "Expired lots with stock", lot_balance_mismatches: "Lot balance mismatches", low_stock: "Low stock products" }[this.exceptionType] || "Exceptions";
    }
  },
  methods: {
    showExceptions(type, warehouseId = null) {
      this.error = ""; this.exceptionType = type; this.exceptionWarehouseId = warehouseId; this.exceptionRows = [];
      axios.get("traceability/exceptions", { params: { type, warehouse_id: warehouseId } })
        .then(({ data }) => { this.exceptionRows = data.rows || []; this.exceptionMore = data.may_have_more; this.$nextTick(() => document.getElementById("exception-detail").scrollIntoView({ behavior: "smooth" })); })
        .catch(error => { this.error = (error.response && error.response.data && error.response.data.message) || "Could not load exceptions."; });
    },
    search() {
      if (this.query.length < 2) return;
      this.busy = true; this.error = ""; this.trace = null; this.serialTrace = null;
      axios.get("traceability/search", { params: { query: this.query } })
        .then(({ data }) => { this.batches = data.batches || []; this.serials = data.serials || []; this.searched = true; })
        .catch(error => { this.error = (error.response && error.response.data && error.response.data.message) || "Search failed."; })
        .finally(() => { this.busy = false; });
    },
    openBatch(lot) {
      this.error = ""; this.serialTrace = null;
      axios.get("batches/" + lot.id + "/trace")
        .then(({ data }) => { this.trace = data; this.$nextTick(() => { const el = document.getElementById("trace-detail"); if (el) el.scrollIntoView({ behavior: "smooth" }); }); })
        .catch(error => { this.error = (error.response && error.response.data && error.response.data.message) || "Trace failed."; });
    },
    openSerial(unit) {
      this.error = ""; this.trace = null;
      axios.get("serials/lookup", { params: { serial: unit.serial_number } })
        .then(({ data }) => { this.serialTrace = (data.serials || []).find(s => s.id === unit.id) || (data.serials || [])[0] || null; this.$nextTick(() => { const el = document.getElementById("trace-detail"); if (el) el.scrollIntoView({ behavior: "smooth" }); }); })
        .catch(error => { this.error = (error.response && error.response.data && error.response.data.message) || "Trace failed."; });
    }
  },
  mounted() {
    axios.get("traceability/overview").then(({ data }) => { this.overview = data; });
    if (this.query) this.search();
  }
};
</script>
