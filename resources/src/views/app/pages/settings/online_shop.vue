<template>
  <div class="main-content">
    <breadcumb page="Online Shop" folder="Settings"/>
    <b-card class="mb-4">
      <h4 class="mb-3">CIMS storefront</h4>
      <p class="text-muted">The storefront uses this location's live available stock. Products must also be marked “Publish on online shop” on their product record.</p>
      <p v-if="loading" class="text-muted">Loading shop settings…</p>
      <b-alert variant="danger" :show="!!error">{{ error }}</b-alert>
      <b-button v-if="!loading && loadError" variant="outline-primary" @click="load">Try again</b-button>
      <div v-if="!loading && !loadError">
        <b-form-group label="Shop name"><b-form-input v-model="form.shop_name" maxlength="120"/></b-form-group>
        <b-alert variant="info" :show="warehouses.length === 0">
          No eligible location is configured yet. In <router-link to="/app/settings/Warehouses">Warehouse settings</router-link>, classify a location as a central warehouse, main store, or branch before enabling online orders.
        </b-alert>
        <b-form-group label="Selling and fulfillment location">
          <b-form-select v-model="form.shop_warehouse_id" :options="warehouseOptions"/>
        </b-form-group>
        <b-form-checkbox v-model="form.shop_enabled" :disabled="warehouses.length === 0" switch class="mb-3">Accept online orders</b-form-checkbox>
        <b-button variant="primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save shop settings' }}</b-button>
        <a v-if="url" :href="url" target="_blank" rel="noopener" class="btn btn-outline-primary ml-2">Preview /shop</a>
        <hr><p class="mb-0 small">For online payments, configure the Xendit secret key and webhook token in System Settings → Payment Gateway. Manual checkout remains available without Xendit.</p>
      </div>
    </b-card>
  </div>
</template>
<script>
export default {
  data() { return {loading:true,loadError:false,saving:false,error:'',url:'',warehouses:[],form:{shop_name:'',shop_warehouse_id:null,shop_enabled:false}} },
  computed: {warehouseOptions() { return [{value:null,text:'Choose a location'}].concat(this.warehouses.map(w => ({value:w.id,text:w.code+' · '+w.name+' ('+(w.site_type || '').replace(/_/g,' ')+')'}))); }},
  mounted() { this.load(); },
  methods: {
    load() {
      this.loading=true;this.loadError=false;this.error='';
      axios.get('shop/settings').then(({data}) => {
        if (!data || !Array.isArray(data.warehouses)) throw new Error('Unexpected shop settings response. Please try again.');
        this.form.shop_name=data.shop_name||'';
        this.form.shop_warehouse_id=data.shop_warehouse_id;
        this.form.shop_enabled=!!data.shop_enabled;
        this.warehouses=data.warehouses;
        this.url=data.url;
      }).catch(e => {
        this.loadError=true;
        this.error=e.response && e.response.data && e.response.data.message || e.message || 'Could not load shop settings.';
      }).finally(() => {this.loading=false;});
    },
    save() { this.error='';this.saving=true;axios.put('shop/settings',this.form).then(() => {this.$bvToast.toast('Shop settings saved',{title:'Online Shop',variant:'success'});}).catch(e => {const data=e.response && e.response.data || {};this.error=data.errors ? Object.values(data.errors)[0][0] : data.message || 'Could not save shop settings.';}).finally(() => {this.saving=false;}); }
  }
}
</script>
