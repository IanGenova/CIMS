<template>
  <div class="main-content">
    <breadcumb page="Customer Orders & Pre-orders" :folder="$t('ListSales')" />
    <b-alert v-if="error" show variant="danger">{{ error }}</b-alert>
    <b-card v-if="can('Sales_add')" title="Reserve stock or accept a pre-order" class="mb-3">
      <b-row>
        <b-col md="4"><b-form-group label="Customer"><b-form-select v-model="form.client_id" :options="clientOptions" /></b-form-group></b-col>
        <b-col md="4"><b-form-group label="Selling branch"><b-form-select v-model="form.warehouse_id" :options="warehouseOptions" /></b-form-group></b-col>
        <b-col md="4"><b-form-group label="Promise date"><b-form-input v-model="form.promise_date" type="date" /></b-form-group></b-col>
      </b-row>
      <b-row v-for="(line, index) in form.lines" :key="index">
        <b-col md="5"><b-form-group label="Product"><b-form-select v-model="line.product_id" :options="productOptions" @change="line.product_variant_id = null" /></b-form-group></b-col>
        <b-col md="2"><b-form-group label="Variant"><b-form-select v-model="line.product_variant_id" :options="variantOptions(line.product_id)" /></b-form-group></b-col>
        <b-col md="3"><b-form-group label="Quantity in base units"><b-form-input v-model.number="line.quantity" type="number" min="0.000001" step="any" /></b-form-group></b-col>
        <b-col md="2" class="d-flex align-items-center"><b-button variant="outline-danger" :disabled="form.lines.length === 1" @click="form.lines.splice(index, 1)">Remove</b-button></b-col>
      </b-row>
      <b-button size="sm" variant="outline-primary" @click="form.lines.push({ product_id: null, product_variant_id: null, quantity: 1 })">Add product</b-button>
      <b-row class="mt-2">
        <b-col md="4"><b-form-checkbox v-model="form.allow_preorder">Allow pending stock when unavailable</b-form-checkbox></b-col>
        <b-col md="4"><b-form-group label="Expected supply date"><b-form-input v-model="form.expected_supply_date" type="date" /></b-form-group></b-col>
        <b-col md="4"><b-form-group label="Deposit noted (not a payment posting)"><b-form-input v-model.number="form.deposit_amount" type="number" min="0" step="0.01" /></b-form-group></b-col>
      </b-row>
      <b-form-group label="Notes"><b-form-input v-model="form.notes" /></b-form-group>
      <b-button variant="primary" :disabled="busy" @click="submit">Create customer order</b-button>
    </b-card>

    <b-card title="Customer orders" class="mb-3">
      <div class="table-responsive"><table class="table table-sm">
        <thead><tr><th>Reference</th><th>Customer</th><th>Branch</th><th>Source</th><th>Status</th><th>Promise date</th></tr></thead>
        <tbody><tr v-for="row in rows" :key="row.id"><td><b-button variant="link" class="p-0" @click="open(row.id)">{{ row.Ref }}</b-button></td><td>{{ row.customer }}</td><td>{{ row.warehouse }}</td><td>{{ row.source_channel }}</td><td>{{ row.status === 'pending_stock' ? 'Pending stock' : row.status }}</td><td>{{ row.promise_date || '—' }}</td></tr>
          <tr v-if="!rows.length"><td colspan="6">No customer orders yet.</td></tr>
        </tbody>
      </table></div>
    </b-card>

    <b-card v-if="selected" :title="selected.Ref + ' · ' + (selected.status === 'pending_stock' ? 'Pending stock' : selected.status)" id="order-detail">
      <b-alert v-if="selected.source_channel === 'web'" show variant="info">
        Online order · Quoted total ₱{{ Number(selected.quoted_total || 0).toFixed(2) }} · Payment choice {{ selected.checkout_payment_method ? selected.checkout_payment_method.replace(/_/g, ' ') : 'manual' }}
        <strong v-if="selected.shop_payment"> · Xendit {{ selected.shop_payment.refund_status ? 'refund '+selected.shop_payment.refund_status : selected.shop_payment.status }}</strong>
      </b-alert>
      <b-alert v-if="selected.shop_payment && selected.shop_payment.status === 'review'" show variant="warning">
        Payment needs reconciliation in Xendit. Reference {{ selected.shop_payment.reference_id }}. Keep stock reserved until the charge is confirmed or safely resolved.
      </b-alert>
      <p>Expected supply {{ selected.expected_supply_date || 'not set' }} · Promise {{ selected.promise_date || 'not set' }}</p>
      <p class="text-muted">{{ selected.notes || 'No notes' }}</p>
      <div v-for="line in selected.lines" :key="line.id" class="border rounded p-3 mb-2">
        <strong>{{ line.code }} · {{ line.name }}</strong> · {{ Number(line.quantity) }} base units · <span :class="line.status === 'pending_stock' ? 'text-warning' : 'text-success'">{{ line.status === 'pending_stock' ? 'Pending stock' : line.status }}</span>
        <div v-for="source in line.reservations" :key="source.id" class="small text-muted">{{ source.status }} · {{ Number(source.quantity) }} · {{ source.batch_number ? 'lot ' + source.batch_number : (source.serial_number || 'ordinary stock') }}</div>
        <b-button v-if="line.status === 'pending_stock' && can('Sales_edit')" size="sm" variant="primary" :disabled="busy" class="mt-1" @click="allocate(line)">Reserve now</b-button>
      </div>
      <router-link v-if="selected.status === 'reserved' && can('Sales_add') && (selected.checkout_payment_method !== 'xendit' || (selected.shop_payment && selected.shop_payment.status === 'paid'))" class="btn btn-success mr-2" :to="{ name: 'store_sale', query: { customer_order_id: selected.id } }">Fulfill as sale</router-link>
      <b-button v-if="selected.shop_payment && ['pending','review'].includes(selected.shop_payment.status) && can('Sales_edit')" variant="outline-primary" class="mr-2" :disabled="busy" @click="verifyPayment">Verify Xendit payment</b-button>
      <b-button v-if="['pending_stock','reserved'].includes(selected.status) && can('Sales_edit') && (!selected.shop_payment || !['paid','pending','creating','review'].includes(selected.shop_payment.status))" variant="outline-danger" :disabled="busy" @click="cancel">Cancel order and release stock</b-button>
      <router-link v-if="selected.sale_id" :to="{ name: 'detail_sale', params: { id: selected.sale_id } }">View sale</router-link>
    </b-card>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
export default {
  metaInfo: { title: "Customer Orders & Pre-orders" },
  data() {
    return {
      error: "", busy: false, rows: [], selected: null,
      clients: [], warehouses: [], products: [], variants: [],
      form: { client_id: null, warehouse_id: null, source_channel: "staff", allow_preorder: true,
        expected_supply_date: null, promise_date: null, deposit_amount: 0, notes: "",
        lines: [{ product_id: null, product_variant_id: null, quantity: 1 }] }
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    clientOptions() { return [{ value: null, text: "Choose customer" }].concat(this.clients.map(c => ({ value: c.id, text: c.name }))); },
    warehouseOptions() { return [{ value: null, text: "Choose branch" }].concat(this.warehouses.map(w => ({ value: w.id, text: w.name }))); },
    productOptions() { return [{ value: null, text: "Choose product" }].concat(this.products.map(p => ({ value: p.id, text: p.code + " · " + p.name }))); }
  },
  methods: {
    can(permission) { return this.currentUserPermissions && this.currentUserPermissions.includes(permission); },
    variantOptions(productId) { return [{ value: null, text: "Base item" }].concat(this.variants.filter(v => Number(v.product_id) === Number(productId)).map(v => ({ value: v.id, text: v.name }))); },
    problem(error) {
      const data = error.response && error.response.data;
      const first = data && data.errors ? Object.values(data.errors)[0] : null;
      this.error = (first && first[0]) || (data && data.message) || "The order could not be completed.";
    },
    refresh() { axios.get("customer_orders").then(({ data }) => { this.rows = data.orders || []; }).catch(this.problem); },
    open(id) {
      this.error = "";
      axios.get("customer_orders/" + id).then(({ data }) => {
        this.selected = data.order;
        this.$nextTick(() => { const el = document.getElementById("order-detail"); if (el) el.scrollIntoView({ behavior: "smooth" }); });
      }).catch(this.problem);
    },
    submit() {
      this.busy = true; this.error = "";
      axios.post("customer_orders", this.form).then(({ data }) => {
        this.refresh(); this.open(data.id);
        this.form.lines = [{ product_id: null, product_variant_id: null, quantity: 1 }];
        this.form.notes = "";
      }).catch(this.problem).finally(() => { this.busy = false; });
    },
    allocate(line) {
      this.busy = true; this.error = "";
      axios.post("customer_order_lines/" + line.id + "/allocate").then(() => {
        this.refresh(); this.open(this.selected.id);
      }).catch(this.problem).finally(() => { this.busy = false; });
    },
    cancel() {
      this.busy = true; this.error = "";
      axios.post("customer_orders/" + this.selected.id + "/cancel").then(() => {
        this.refresh(); this.open(this.selected.id);
      }).catch(this.problem).finally(() => { this.busy = false; });
    },
    verifyPayment() {
      this.busy = true; this.error = "";
      axios.post("customer_orders/" + this.selected.id + "/verify_payment").then(() => {
        this.refresh(); this.open(this.selected.id);
      }).catch(this.problem).finally(() => { this.busy = false; });
    }
  },
  mounted() {
    this.refresh();
    axios.get("customer_orders/options").then(({ data }) => {
      this.clients = data.clients || []; this.warehouses = data.warehouses || [];
      this.products = data.products || []; this.variants = data.variants || [];
    }).catch(this.problem);
  }
};
</script>
