<template>
  <div class="main-content">
    <breadcumb page="Branch Requests & Pull-outs" :folder="$t('Transfer')" />
    <b-alert v-if="error" show variant="danger">{{ error }}</b-alert>
    <b-card v-if="can('transfer_add')" title="Request stock or pull it back" class="mb-3">
      <b-row>
        <b-col md="2"><b-form-group label="Request type"><b-form-select v-model="form.kind" :options="[{value:'replenishment',text:'Send to branch'},{value:'pullout',text:'Pull out to central'}]" @change="form.from_warehouse_id=null; form.to_warehouse_id=null" /></b-form-group></b-col>
        <b-col md="3"><b-form-group label="From"><b-form-select v-model="form.from_warehouse_id" :options="sourceOptions" /></b-form-group></b-col>
        <b-col md="3"><b-form-group label="To"><b-form-select v-model="form.to_warehouse_id" :options="destinationOptions" /></b-form-group></b-col>
        <b-col md="2"><b-form-group label="Priority"><b-form-select v-model="form.priority" :options="['normal', 'urgent']" /></b-form-group></b-col>
        <b-col md="4"><b-form-group label="Notes"><b-form-input v-model="form.notes" /></b-form-group></b-col>
      </b-row>
      <b-row v-for="(line, index) in form.lines" :key="index">
        <b-col md="5"><b-form-group label="Product"><b-form-select v-model="line.product_id" :options="productOptions" @change="line.product_variant_id = null" /></b-form-group></b-col>
        <b-col md="2"><b-form-group label="Variant"><b-form-select v-model="line.product_variant_id" :options="variantOptions(line.product_id)" /></b-form-group></b-col>
        <b-col md="3"><b-form-group label="Quantity in base units"><b-form-input v-model.number="line.quantity" type="number" min="0.000001" step="any" /></b-form-group></b-col>
        <b-col md="2" class="d-flex align-items-center"><b-button variant="outline-danger" @click="form.lines.splice(index, 1)" :disabled="form.lines.length === 1">Remove</b-button></b-col>
      </b-row>
      <b-button size="sm" variant="outline-primary" @click="form.lines.push({ product_id: null, product_variant_id: null, quantity: 1 })">Add product</b-button>
      <b-button variant="primary" class="ml-2" :disabled="busy" @click="submit">Submit request</b-button>
    </b-card>

    <b-card title="Branch requests" class="mb-3">
      <div class="table-responsive"><table class="table table-sm">
        <thead><tr><th>Reference</th><th>Type</th><th>Source</th><th>Destination</th><th>Priority</th><th>Status</th><th>Requested</th></tr></thead>
        <tbody><tr v-for="row in rows" :key="row.id"><td><b-button variant="link" class="p-0" @click="open(row.id)">{{ row.Ref }}</b-button></td><td>{{ row.kind === 'pullout' ? 'Pull-out' : 'Replenishment' }}</td><td>{{ row.source }}</td><td>{{ row.destination }}</td><td>{{ row.priority }}</td><td>{{ row.status }}</td><td>{{ row.created_at }}</td></tr>
          <tr v-if="!rows.length"><td colspan="7">No branch requests yet.</td></tr>
        </tbody>
      </table></div>
    </b-card>

    <b-card v-if="selected" :title="selected.Ref + ' · ' + selected.status" id="request-detail">
      <p class="text-muted">{{ selected.notes || 'No request notes' }}</p>
      <div v-if="can('transfer_edit') && !['cancelled', 'received', 'in_transit', 'discrepancy'].includes(selected.status)" class="mb-3">
        <b-form-input v-model="cancelReason" placeholder="Reason for cancelling this request" class="mb-2" />
        <b-button size="sm" variant="outline-danger" :disabled="busy || cancelReason.trim().length < 3" @click="cancelRequest">Cancel request and release allocations</b-button>
      </div>
      <div v-for="line in selected.lines" :key="line.id" class="border rounded p-3 mb-3">
        <h5>{{ line.code }} · {{ line.name }}</h5>
        <p>Requested {{ Number(line.requested_qte) }} base units</p>
        <div v-if="can('branch_request_approve') && selected.status !== 'cancelled'" class="d-flex align-items-end mb-3">
          <b-form-group label="Approve and reserve quantity" class="mr-2 mb-0"><b-form-input v-model.number="line.allocate_quantity" type="number" min="0.000001" step="any" /></b-form-group>
          <b-button variant="primary" :disabled="busy" @click="allocate(line)">Allocate</b-button>
        </div>
        <div v-for="shipment in line.shipments" :key="shipment.id" class="border-top py-2">
          <strong>{{ shipment.Ref }}</strong> · {{ Number(shipment.quantity) }} units · {{ shipment.status }}
          <b-button v-if="shipment.status === 'allocated' && can('transfer_add')" size="sm" variant="warning" class="ml-2" :disabled="busy" @click="dispatch(shipment)">Dispatch from central</b-button>
          <div v-if="shipment.status === 'in_transit' || shipment.status === 'discrepancy'" class="mt-2">
            <p class="text-muted mb-1">Confirm what the branch physically received. Shortages remain in transit.</p>
            <b-row v-for="item in shipment.items" :key="item.id">
              <b-col md="6">{{ item.batch_number ? 'Lot ' + item.batch_number : (item.serial_number || 'Untracked stock') }} · sent {{ Number(item.quantity) }} · received {{ Number(item.received_qte) }}</b-col>
              <b-col md="3"><b-form-input v-model.number="item.receive_now" type="number" min="0" :max="Number(item.quantity) - Number(item.received_qte)" step="any" /></b-col>
            </b-row>
            <b-form-input v-model="shipment.discrepancy_notes_input" class="my-2" placeholder="Reason for any shortage" />
            <b-button v-if="can('transfer_edit')" size="sm" variant="success" :disabled="busy" @click="receive(shipment)">Record branch receipt</b-button>
          </div>
          <div v-if="selected.kind === 'pullout' && line.requires_qc" class="mt-2">
            <div v-for="item in shipment.items" :key="'qc-'+item.id" v-if="can('goods_receipt_qc_approve') && Number(item.received_qte) > Number(item.qc_passed_qte) + Number(item.qc_failed_qte)">
              <p class="mb-1">Return QC: {{ item.batch_number ? 'Lot ' + item.batch_number : (item.serial_number || line.name) }} · awaiting {{ Number(item.received_qte) - Number(item.qc_passed_qte) - Number(item.qc_failed_qte) }}</p>
              <b-row>
                <b-col md="2"><b-form-group label="Pass"><b-form-input v-model.number="item.qc_pass_now" type="number" min="0" step="any" /></b-form-group></b-col>
                <b-col md="2"><b-form-group label="Fail"><b-form-input v-model.number="item.qc_fail_now" type="number" min="0" step="any" /></b-form-group></b-col>
                <b-col md="6"><b-form-group label="Inspection notes"><b-form-input v-model="item.qc_notes_input" /></b-form-group></b-col>
                <b-col v-if="Number(item.qc_fail_now) > 0" md="6"><b-form-group label="Physical QC hold location"><b-form-input v-model="item.qc_hold_location_input" placeholder="e.g. Returns cage R1" /></b-form-group></b-col>
                <b-col md="2" class="d-flex align-items-center"><b-button size="sm" variant="success" :disabled="busy" @click="decideQc(shipment,item)">Decide QC</b-button></b-col>
              </b-row>
            </div>
            <div v-for="item in shipment.items" :key="'custody-item-'+item.id">
              <div v-if="Number(item.qc_disposition_remaining) > 0 && can('branch_return_qc_disposition_request')" class="border-top pt-2 mt-2">
                <strong>Request disposition of rejected return</strong>
                <b-row>
                  <b-col md="2"><b-form-group label="Action"><b-form-select v-model="item.disposition_action" :options="[{value:'write_off',text:'Write off'},{value:'supplier_return',text:'Return to supplier'}]" /></b-form-group></b-col>
                  <b-col md="2"><b-form-group label="Quantity"><b-form-input v-model.number="item.disposition_quantity" type="number" step="any" min="0.000001" :max="Number(item.qc_disposition_remaining)" /></b-form-group></b-col>
                  <b-col md="6"><b-form-group label="Reason"><b-form-input v-model="item.disposition_reason" /></b-form-group></b-col>
                  <b-col md="2" class="d-flex align-items-center"><b-button size="sm" variant="warning" :disabled="busy" @click="requestDisposition(item)">Request</b-button></b-col>
                </b-row>
              </div>
              <div v-for="disposition in item.qc_dispositions" :key="'disposition-'+disposition.id" class="small border-top pt-2 mt-2">
                {{ disposition.action }} · {{ Number(disposition.quantity) }} units · {{ disposition.status }} · requested by {{ disposition.requester }}
                <div v-if="disposition.status === 'pending' && can('branch_return_qc_disposition_approve')" class="d-flex mt-1">
                  <b-form-input v-model="disposition.decision_reason_input" placeholder="Reason for rejection, if rejecting" class="mr-1" />
                  <b-button size="sm" variant="success" class="mr-1" :disabled="busy" @click="decideDisposition(disposition,'approve')">Approve</b-button>
                  <b-button size="sm" variant="outline-danger" :disabled="busy" @click="decideDisposition(disposition,'reject')">Reject</b-button>
                </div>
                <div v-if="disposition.status === 'approved' && can('branch_return_qc_disposition_execute')" class="d-flex mt-1">
                  <b-form-input v-model="disposition.execution_reference_input" placeholder="Handoff / write-off reference" class="mr-1" />
                  <b-form-input v-model="disposition.execution_note_input" placeholder="Physical handoff details" class="mr-1" />
                  <b-button size="sm" variant="warning" :disabled="busy" @click="executeDisposition(disposition)">Confirm exit</b-button>
                </div>
                <div v-if="disposition.executed_at">Confirmed {{ disposition.executed_at }} by {{ disposition.executor || 'Unknown' }} · {{ disposition.execution_reference }} · {{ disposition.execution_note }}</div>
              </div>
              <div v-for="custody in item.qc_custody" :key="'custody-'+custody.id" class="small border-top pt-2 mt-2">
                Rejected goods held: {{ Number(custody.remaining_quantity) }} base units at <strong>{{ custody.location }}</strong> · {{ custody.status }}
                <div v-if="custody.status === 'held' && can('goods_receipt_qc_disposition_execute')" class="d-flex mt-1">
                  <b-form-input v-model="custody.move_location" placeholder="New location" class="mr-1" />
                  <b-form-input v-model="custody.move_note" placeholder="Move reason" class="mr-1" />
                  <b-button size="sm" variant="outline-primary" :disabled="busy" @click="moveCustody(custody)">Move</b-button>
                </div>
                <div v-for="(event, index) in custody.events" :key="index" class="text-muted">{{ event.created_at }} · {{ event.event }} {{ Number(event.quantity) }} · {{ event.from_location || 'Received' }} → {{ event.to_location || 'Exited' }} · {{ event.actor || 'System' }}<span v-if="event.note"> · {{ event.note }}</span></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </b-card>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
export default {
  metaInfo: { title: "Branch Requests" },
  data() {
    return {
      busy: false, error: "", rows: [], selected: null, cancelReason: "",
      warehouses: [], products: [], variants: [],
      form: { kind: "replenishment", from_warehouse_id: null, to_warehouse_id: null, priority: "normal", notes: "", lines: [{ product_id: null, product_variant_id: null, quantity: 1 }] }
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    sourceOptions() { return [{ value: null, text: "Choose source" }].concat(this.warehouses.filter(w => this.form.kind === "pullout" ? ["main_store", "branch"].includes(w.site_type) : w.site_type === "central_warehouse").map(w => ({ value: w.id, text: w.name }))); },
    destinationOptions() { return [{ value: null, text: "Choose destination" }].concat(this.warehouses.filter(w => this.form.kind === "pullout" ? w.site_type === "central_warehouse" : ["main_store", "branch"].includes(w.site_type)).map(w => ({ value: w.id, text: w.name }))); },
    productOptions() { return [{ value: null, text: "Choose product" }].concat(this.products.map(p => ({ value: p.id, text: p.code + " · " + p.name }))); }
  },
  methods: {
    can(permission) { return this.currentUserPermissions && this.currentUserPermissions.includes(permission); },
    variantOptions(productId) { return [{ value: null, text: "Base item" }].concat(this.variants.filter(v => Number(v.product_id) === Number(productId)).map(v => ({ value: v.id, text: v.name }))); },
    problem(error) {
      const data = error.response && error.response.data;
      const first = data && data.errors ? Object.values(data.errors)[0] : null;
      this.error = (first && first[0]) || (data && data.message) || "The request could not be completed.";
      this.busy = false;
    },
    refresh() { axios.get("branch_requests").then(({ data }) => { this.rows = data.requests || []; }).catch(this.problem); },
    open(id) {
      this.error = "";
      axios.get("branch_requests/" + id).then(({ data }) => {
        this.selected = data.request;
        this.selected.lines.forEach(line => {
          this.$set(line, "allocate_quantity", Number(line.requested_qte) - line.shipments.reduce((n, s) => n + Number(s.quantity), 0));
          line.shipments.forEach(shipment => {
            this.$set(shipment, "discrepancy_notes_input", shipment.discrepancy_notes || "");
            shipment.items.forEach(item => {
              this.$set(item, "receive_now", Number(item.quantity) - Number(item.received_qte));
              this.$set(item, "qc_pass_now", Number(item.received_qte) - Number(item.qc_passed_qte) - Number(item.qc_failed_qte));
              this.$set(item, "qc_fail_now", 0);
              this.$set(item, "qc_notes_input", "");
              this.$set(item, "qc_hold_location_input", "");
              this.$set(item, "disposition_action", "write_off");
              this.$set(item, "disposition_quantity", Number(item.qc_disposition_remaining));
              this.$set(item, "disposition_reason", "");
              (item.qc_dispositions || []).forEach(d => { this.$set(d, "decision_reason_input", ""); this.$set(d, "execution_reference_input", ""); this.$set(d, "execution_note_input", ""); });
              (item.qc_custody || []).forEach(c => { this.$set(c, "move_location", ""); this.$set(c, "move_note", ""); });
            });
          });
        });
        this.$nextTick(() => { const el = document.getElementById("request-detail"); if (el) el.scrollIntoView({ behavior: "smooth" }); });
      }).catch(this.problem);
    },
    submit() {
      this.busy = true; this.error = "";
      axios.post("branch_requests", this.form).then(({ data }) => {
        this.refresh(); this.open(data.id);
        this.form.lines = [{ product_id: null, product_variant_id: null, quantity: 1 }]; this.form.notes = "";
      }).catch(this.problem).finally(() => { this.busy = false; });
    },
    allocate(line) {
      this.busy = true; this.error = "";
      axios.post("branch_requests/" + this.selected.id + "/allocate", { line_id: line.id, quantity: line.allocate_quantity })
        .then(() => { this.refresh(); this.open(this.selected.id); })
        .catch(this.problem).finally(() => { this.busy = false; });
    },
    dispatch(shipment) {
      this.busy = true; this.error = "";
      axios.post("branch_shipments/" + shipment.id + "/dispatch")
        .then(() => { this.refresh(); this.open(this.selected.id); })
        .catch(this.problem).finally(() => { this.busy = false; });
    },
    cancelRequest() {
      this.busy = true; this.error = "";
      axios.post("branch_requests/" + this.selected.id + "/cancel", { reason: this.cancelReason })
        .then(() => { this.cancelReason = ""; this.refresh(); this.open(this.selected.id); })
        .catch(this.problem).finally(() => { this.busy = false; });
    },
    receive(shipment) {
      this.busy = true; this.error = "";
      axios.post("branch_shipments/" + shipment.id + "/receive", {
        items: shipment.items.map(item => ({ id: item.id, received_qte: item.receive_now })),
        discrepancy_notes: shipment.discrepancy_notes_input
      }).then(() => { this.refresh(); this.open(this.selected.id); })
        .catch(this.problem).finally(() => { this.busy = false; });
    },
    decideQc(shipment, item) {
      this.busy = true; this.error = "";
      axios.post("branch_shipments/" + shipment.id + "/qc", {
        item_id: item.id, passed_qte: item.qc_pass_now,
        failed_qte: item.qc_fail_now, notes: item.qc_notes_input,
        hold_location: item.qc_hold_location_input
      }).then(() => { this.refresh(); this.open(this.selected.id); })
        .catch(this.problem).finally(() => { this.busy = false; });
    },
    moveCustody(custody) {
      this.busy = true; this.error = "";
      axios.post("qc_custody/" + custody.id + "/move", {
        location: custody.move_location, note: custody.move_note
      }).then(() => { this.refresh(); this.open(this.selected.id); })
        .catch(this.problem).finally(() => { this.busy = false; });
    },
    requestDisposition(item) {
      this.busy = true; this.error = "";
      axios.post("branch_shipment_items/" + item.id + "/qc_dispositions", {
        action: item.disposition_action, quantity: item.disposition_quantity, reason: item.disposition_reason
      }).then(() => this.open(this.selected.id)).catch(this.problem).finally(() => { this.busy = false; });
    },
    decideDisposition(disposition, decision) {
      this.busy = true; this.error = "";
      axios.post("branch_return_qc_dispositions/" + disposition.id + "/decision", {
        decision, reason: disposition.decision_reason_input
      }).then(() => this.open(this.selected.id)).catch(this.problem).finally(() => { this.busy = false; });
    },
    executeDisposition(disposition) {
      this.busy = true; this.error = "";
      axios.post("branch_return_qc_dispositions/" + disposition.id + "/execute", {
        execution_reference: disposition.execution_reference_input,
        execution_note: disposition.execution_note_input
      }).then(() => { this.refresh(); this.open(this.selected.id); })
        .catch(this.problem).finally(() => { this.busy = false; });
    }
  },
  mounted() {
    this.refresh();
    axios.get("branch_requests/options").then(({ data }) => { this.warehouses = data.warehouses || []; this.products = data.products || []; this.variants = data.variants || []; }).catch(this.problem);
  }
};
</script>
