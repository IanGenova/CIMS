<template>
  <div class="main-content">
    <breadcumb :page="editing ? 'Edit Group Permissions' : 'Create Group Permissions'" :folder="$t('Settings')" />
    <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-alert v-if="error" show variant="danger">{{ error }}</b-alert>
    <b-card v-if="!loading" class="mb-3">
      <b-form @submit.prevent="save">
        <b-row>
          <b-col md="6"><b-form-group label="Role name" label-for="role-name"><b-form-input id="role-name" v-model.trim="role.name" required maxlength="191" /></b-form-group></b-col>
          <b-col md="6"><b-form-group label="Description" label-for="role-description"><b-form-input id="role-description" v-model.trim="role.description" maxlength="1000" /></b-form-group></b-col>
        </b-row>
        <b-row class="align-items-end mb-3">
          <b-col md="8"><b-form-group label="Find a permission" label-for="permission-search" class="mb-0"><b-form-input id="permission-search" v-model.trim="search" placeholder="Search by task or permission name" /></b-form-group></b-col>
          <b-col md="4" class="text-md-right mt-2 mt-md-0"><strong>{{ selected.length }}</strong> of {{ catalog.length }} permissions selected</b-col>
        </b-row>
        <p class="text-muted">Choose only the tasks this role needs. Warehouse access is assigned separately to each user in Users Management.</p>
        <b-alert show variant="info" class="mb-3">
          <strong>New CIMS workflow access:</strong> Branch requests use Transfer plus Branch Request Approve; receipt and return QC use the Quality control permissions below. Customer Orders and pre-orders use Sales View, Add, and Edit. Trace Explorer uses Products View and requires access to all warehouses. Online Shop settings use Setting System; product publishing uses Products Edit.
        </b-alert>
        <b-row>
          <b-col v-for="group in groups" :key="group.name" lg="6" class="mb-3">
            <b-card :title="group.name" class="h-100">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <small class="text-muted">{{ selectedIn(group) }} / {{ group.items.length }} selected</small>
                <b-button size="sm" variant="link" class="p-0" @click="toggleGroup(group)">{{ selectedIn(group) === group.items.length ? 'Clear shown' : 'Select shown' }}</b-button>
              </div>
              <b-form-checkbox v-for="permission in group.items" :key="permission.name" v-model="selected" :value="permission.name" class="mb-2">
                <strong>{{ permissionTitle(permission) }}</strong>
                <small class="d-block text-muted">{{ permission.name }}<span v-if="permission.description"> · {{ permission.description }}</span></small>
              </b-form-checkbox>
            </b-card>
          </b-col>
        </b-row>
        <b-alert v-if="!groups.length" show variant="info">No permissions match this search.</b-alert>
        <div class="d-flex align-items-center">
          <b-button type="submit" variant="primary" :disabled="saving || !role.name">{{ saving ? 'Saving…' : 'Save group permissions' }}</b-button>
          <router-link class="btn btn-outline-secondary ml-2" to="/app/Settings/Permissions">Cancel</router-link>
        </div>
      </b-form>
    </b-card>
  </div>
</template>

<script>
export default {
  props: { editing: { type: Boolean, default: false } },
  data() {
    return { loading: true, saving: false, error: "", search: "", catalog: [], selected: [], role: { name: "", description: "" } };
  },
  computed: {
    groups() {
      const term = this.search.toLowerCase();
      const found = {};
      this.catalog.forEach(permission => {
        const title = this.permissionTitle(permission);
        if (term && ![title, permission.name, permission.description || ""].some(value => value.toLowerCase().includes(term))) return;
        const name = this.groupName(permission.name);
        if (!found[name]) found[name] = [];
        found[name].push(permission);
      });
      const order = ["Quality control", "Branch requests and transfers", "Customer orders and online shop", "People and group permissions", "Purchasing and suppliers", "Products and inventory", "Sales and POS", "Reports and audit", "Settings and integrations", "Other permissions"];
      return order.filter(name => found[name]).map(name => ({ name, items: found[name] }));
    }
  },
  methods: {
    groupName(name) {
      const key = name.toLowerCase();
      if (key.includes("qc") || key.includes("disposition")) return "Quality control";
      if (key.startsWith("branch_") || key.startsWith("transfer") || key.includes("shipment")) return "Branch requests and transfers";
      if (key.includes("customer_order") || key.includes("reservation") || key.includes("preorder") || key.includes("shop") || key.includes("web_order")) return "Customer orders and online shop";
      if (key.startsWith("users_") || key.startsWith("permissions_") || key === "record_view") return "People and group permissions";
      if (key.includes("purchase") || key.includes("supplier")) return "Purchasing and suppliers";
      if (key.includes("product") || key.includes("stock") || key.includes("warehouse") || key.includes("batch") || key.includes("serial") || key.includes("adjustment") || key.includes("goods_receipt")) return "Products and inventory";
      if (key.includes("sale") || key.includes("pos") || key.includes("payment")) return "Sales and POS";
      if (key.includes("report") || key.includes("audit")) return "Reports and audit";
      if (key.includes("setting") || key.includes("webhook") || key.includes("security") || key.includes("backup") || key.includes("integration")) return "Settings and integrations";
      return "Other permissions";
    },
    permissionTitle(permission) {
      const label = (permission.label || "").trim();
      if (label && label.toLowerCase() !== permission.name.toLowerCase()) return label;
      return permission.name.replace(/[_-]+/g, " ").replace(/\b\w/g, letter => letter.toUpperCase());
    },
    selectedIn(group) { return group.items.filter(item => this.selected.includes(item.name)).length; },
    toggleGroup(group) {
      const names = group.items.map(item => item.name);
      this.selected = this.selectedIn(group) === names.length
        ? this.selected.filter(name => !names.includes(name))
        : Array.from(new Set(this.selected.concat(names)));
    },
    load() {
      const requests = [axios.get("roles/permission-catalog")];
      if (this.editing) requests.push(axios.get("roles/" + this.$route.params.id + "/edit"));
      Promise.all(requests).then(results => {
        this.catalog = results[0].data.permissions || [];
        if (this.editing) {
          this.role = results[1].data.role;
          this.selected = results[1].data.permissions || [];
        }
      }).catch(error => {
        this.error = (error.response && error.response.data && error.response.data.message) || "Could not load group permissions.";
      }).finally(() => { this.loading = false; });
    },
    save() {
      if (this.saving) return;
      this.saving = true; this.error = "";
      const payload = { role: this.role, permissions: this.selected };
      const request = this.editing
        ? axios.put("roles/" + this.$route.params.id, payload)
        : axios.post("roles", payload);
      request.then(() => {
        this.$store.dispatch("refreshUserPermissions");
        this.$router.push({ name: "groupPermission" });
      }).catch(error => {
        const data = error.response && error.response.data;
        const first = data && data.errors && Object.values(data.errors)[0];
        this.error = (first && first[0]) || (data && data.message) || "Could not save group permissions.";
      }).finally(() => { this.saving = false; });
    }
  },
  created() { this.load(); }
};
</script>
