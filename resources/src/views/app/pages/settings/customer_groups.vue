<template>
  <div class="main-content">
    <breadcumb :page="$t('CustomerGroups')" :folder="$t('Settings')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-card class="mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <p class="text-muted mb-0">{{ $t('CustomerGroupIntro') }}</p>
          <b-button variant="primary" size="sm" @click="Open_Create">
            <i class="i-Add"></i> {{ $t('Add') }}
          </b-button>
        </div>

        <div v-if="!groups.length" class="text-center text-muted py-4">
          {{ $t('NoCustomerGroupsYet') }}
        </div>

        <div v-else class="table-responsive">
          <table class="table table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('Name') }}</th>
                <th class="text-right">{{ $t('DefaultDiscount') }}</th>
                <th class="text-right">{{ $t('CustomerCount') }}</th>
                <th class="text-right">{{ $t('PriceLists') }}</th>
                <th class="text-center">{{ $t('Status') }}</th>
                <th class="text-center">{{ $t('Action') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="g in groups" :key="g.id">
                <td><strong>{{ g.name }}</strong></td>
                <td class="text-right">{{ g.default_discount_percent }}%</td>
                <td class="text-right">{{ g.customer_count }}</td>
                <td class="text-right">{{ g.price_list_count }}</td>
                <td class="text-center">
                  <span class="badge" :class="g.is_active ? 'badge-outline-success' : 'badge-outline-secondary'">
                    {{ g.is_active ? $t('Active') : $t('Inactive') }}
                  </span>
                </td>
                <td class="text-center">
                  <a @click="Open_Edit(g)" class="cursor-pointer mr-2" title="Edit">
                    <i class="i-Pen-2 text-25 text-success"></i>
                  </a>
                  <a @click="Remove(g)" class="cursor-pointer" title="Delete">
                    <i class="i-Close-Window text-25 text-danger"></i>
                  </a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </b-card>

      <!-- Price lists: the per-product overrides that beat the group discount -->
      <b-card>
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h5 class="mb-1">{{ $t('PriceLists') }}</h5>
            <p class="text-muted mb-0 small">{{ $t('PriceListIntro') }}</p>
          </div>
          <b-button variant="primary" size="sm" @click="Open_Create_List" :disabled="!groups.length">
            <i class="i-Add"></i> {{ $t('Add') }}
          </b-button>
        </div>

        <div v-if="!lists.length" class="text-center text-muted py-4">
          {{ $t('NoPriceListsYet') }}
        </div>

        <div v-else class="table-responsive">
          <table class="table table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('Name') }}</th>
                <th>{{ $t('CustomerGroup') }}</th>
                <th>{{ $t('StartsOn') }}</th>
                <th>{{ $t('EndsOn') }}</th>
                <th class="text-right">{{ $t('Products') }}</th>
                <th class="text-center">{{ $t('Status') }}</th>
                <th class="text-center">{{ $t('Action') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="l in lists" :key="l.id">
                <td><strong>{{ l.name }}</strong></td>
                <td>{{ l.customer_group || '—' }}</td>
                <td>{{ l.starts_on || $t('Always') }}</td>
                <td>{{ l.ends_on || $t('Always') }}</td>
                <td class="text-right">{{ l.item_count }}</td>
                <td class="text-center">
                  <!-- "Live" folds the active flag and the date window together. -->
                  <span class="badge" :class="l.is_live ? 'badge-outline-success' : 'badge-outline-secondary'">
                    {{ l.is_live ? $t('Live') : $t('NotLive') }}
                  </span>
                </td>
                <td class="text-center">
                  <a @click="Open_Items(l)" class="cursor-pointer mr-2" :title="$t('Products')">
                    <i class="i-File-Horizontal-Text text-25 text-primary"></i>
                  </a>
                  <a @click="Open_Edit_List(l)" class="cursor-pointer mr-2" title="Edit">
                    <i class="i-Pen-2 text-25 text-success"></i>
                  </a>
                  <a @click="Remove_List(l)" class="cursor-pointer" title="Delete">
                    <i class="i-Close-Window text-25 text-danger"></i>
                  </a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </b-card>
    </div>

    <!-- group -->
    <b-modal id="cg_modal" :title="editing ? $t('EditCustomerGroup') : $t('NewCustomerGroup')" hide-footer>
      <b-form-group :label="$t('Name')">
        <b-form-input v-model="form.name" :placeholder="$t('CustomerGroupNamePlaceholder')"/>
      </b-form-group>
      <b-form-group :label="$t('DefaultDiscount') + ' %'">
        <b-form-input type="number" step="any" min="0" max="100" v-model.number="form.default_discount_percent"/>
        <small class="text-muted">{{ $t('DefaultDiscountNote') }}</small>
      </b-form-group>
      <b-form-checkbox v-model="form.is_active" switch>{{ $t('Active') }}</b-form-checkbox>
      <b-button class="mt-3" variant="primary" :disabled="Processing || !form.name" @click="Save">
        {{ $t('Submit') }}
      </b-button>
    </b-modal>

    <!-- price list -->
    <b-modal id="pl_modal" :title="editingList ? $t('EditPriceList') : $t('NewPriceList')" hide-footer>
      <b-form-group :label="$t('Name')">
        <b-form-input v-model="listForm.name"/>
      </b-form-group>
      <b-form-group :label="$t('CustomerGroup')">
        <v-select
          v-model="listForm.customer_group_id"
          :reduce="label => label.value"
          :placeholder="$t('Choose')"
          :options="groups.map(g => ({label: g.name, value: g.id}))"
        />
      </b-form-group>
      <b-row>
        <b-col md="6">
          <b-form-group :label="$t('StartsOn')">
            <b-form-input type="date" v-model="listForm.starts_on"/>
          </b-form-group>
        </b-col>
        <b-col md="6">
          <b-form-group :label="$t('EndsOn')">
            <b-form-input type="date" v-model="listForm.ends_on"/>
          </b-form-group>
        </b-col>
      </b-row>
      <b-form-checkbox v-model="listForm.is_active" switch>{{ $t('Active') }}</b-form-checkbox>
      <b-button class="mt-3" variant="primary"
        :disabled="Processing || !listForm.name || !listForm.customer_group_id" @click="Save_List">
        {{ $t('Submit') }}
      </b-button>
    </b-modal>

    <!-- price list items -->
    <b-modal id="pli_modal" :title="$t('PriceListProducts')" hide-footer size="xl">
      <div v-if="currentList">
        <p class="mb-3"><strong>{{ currentList.name }}</strong></p>

        <b-row class="align-items-end mb-3">
          <b-col md="4">
            <b-form-group :label="$t('Product')" class="mb-0">
              <v-select
                v-model="itemForm.product_id"
                :reduce="label => label.value"
                :placeholder="$t('Choose_Product')"
                :options="products.map(p => ({label: p.code + ' — ' + p.name, value: p.id}))"
              />
            </b-form-group>
          </b-col>
          <b-col md="2">
            <b-form-group :label="$t('FromQuantity')" class="mb-0">
              <b-form-input type="number" step="any" min="0" v-model.number="itemForm.min_quantity"/>
            </b-form-group>
          </b-col>
          <b-col md="2">
            <b-form-group :label="$t('FixedPrice')" class="mb-0">
              <b-form-input type="number" step="any" min="0" v-model.number="itemForm.price"/>
            </b-form-group>
          </b-col>
          <b-col md="2">
            <b-form-group :label="$t('OrDiscount') + ' %'" class="mb-0">
              <b-form-input type="number" step="any" min="0" max="100"
                v-model.number="itemForm.discount_percent" :disabled="itemForm.price !== null && itemForm.price !== ''"/>
            </b-form-group>
          </b-col>
          <b-col md="2">
            <b-button variant="primary" size="sm" block :disabled="Processing || !itemForm.product_id" @click="Add_Item">
              {{ $t('Add') }}
            </b-button>
          </b-col>
        </b-row>

        <p class="text-muted small">{{ $t('VolumeBandNote') }}</p>

        <div v-if="!items.length" class="text-center text-muted py-3">{{ $t('NoRowsYet') }}</div>

        <div v-else class="table-responsive">
          <table class="table table-sm table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('ProductCode') }}</th>
                <th>{{ $t('ProductName') }}</th>
                <th class="text-right">{{ $t('ListPrice') }}</th>
                <th class="text-right">{{ $t('FromQuantity') }}</th>
                <th class="text-right">{{ $t('FixedPrice') }}</th>
                <th class="text-right">{{ $t('OrDiscount') }}</th>
                <th class="text-right">{{ $t('TheyPay') }}</th>
                <th class="text-center">{{ $t('Action') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="it in items" :key="it.id">
                <td>{{ it.code }}</td>
                <td>{{ it.product }}</td>
                <td class="text-right text-muted">{{ money(it.list_price) }}</td>
                <td class="text-right">{{ it.min_quantity }}</td>
                <td class="text-right">{{ it.price === null ? '—' : money(it.price) }}</td>
                <td class="text-right">{{ it.discount_percent === null ? '—' : it.discount_percent + '%' }}</td>
                <td class="text-right"><strong>{{ money(it.effective_price) }}</strong></td>
                <td class="text-center">
                  <a @click="Remove_Item(it)" class="cursor-pointer" title="Delete">
                    <i class="i-Close-Window text-20 text-danger"></i>
                  </a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";

export default {
  metaInfo: { title: "Customer Groups" },

  data() {
    return {
      isLoading: true,
      Processing: false,
      groups: [],
      lists: [],
      products: [],
      items: [],
      currentList: null,
      editing: null,
      editingList: null,
      form: { name: "", default_discount_percent: 0, is_active: true },
      listForm: { name: "", customer_group_id: null, starts_on: "", ends_on: "", is_active: true },
      itemForm: { product_id: null, min_quantity: 0, price: null, discount_percent: null }
    };
  },

  computed: {
    ...mapGetters(["currentUser"])
  },

  methods: {
    money(v) {
      const symbol = (this.currentUser && this.currentUser.currency) || "";
      return (symbol ? symbol + " " : "") + Number(v || 0).toFixed(2);
    },

    Get_Data() {
      NProgress.start();
      NProgress.set(0.1);
      Promise.all([
        axios.get("customer_groups?page=1&limit=100&SortField=id&SortType=desc"),
        axios.get("price_lists")
      ])
        .then(([g, l]) => {
          this.groups = g.data.customergroups;
          this.lists = l.data.pricelists;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    Get_Products() {
      axios.get("Products?page=1&limit=1000&SortField=name&SortType=asc").then(r => {
        this.products = r.data.products || [];
      });
    },

    Open_Create() {
      this.editing = null;
      this.form = { name: "", default_discount_percent: 0, is_active: true };
      this.$bvModal.show("cg_modal");
    },

    Open_Edit(g) {
      this.editing = g;
      this.form = {
        name: g.name,
        default_discount_percent: g.default_discount_percent,
        is_active: g.is_active
      };
      this.$bvModal.show("cg_modal");
    },

    Save() {
      this.Processing = true;
      const req = this.editing
        ? axios.put("customer_groups/" + this.editing.id, this.form)
        : axios.post("customer_groups", this.form);
      req.then(() => {
        this.Processing = false;
        this.$bvModal.hide("cg_modal");
        this.Get_Data();
        this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
      }).catch(e => {
        this.Processing = false;
        this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
      });
    },

    Remove(g) {
      this.confirmThen(g.name, () =>
        axios.delete("customer_groups/" + g.id).then(() => {
          this.Get_Data();
          this.makeToast("success", this.$t("Successfully_Deleted"), this.$t("Success"));
        })
      );
    },

    Open_Create_List() {
      this.editingList = null;
      this.listForm = { name: "", customer_group_id: null, starts_on: "", ends_on: "", is_active: true };
      this.$bvModal.show("pl_modal");
    },

    Open_Edit_List(l) {
      this.editingList = l;
      this.listForm = {
        name: l.name,
        customer_group_id: l.customer_group_id,
        starts_on: l.starts_on || "",
        ends_on: l.ends_on || "",
        is_active: l.is_active
      };
      this.$bvModal.show("pl_modal");
    },

    Save_List() {
      this.Processing = true;
      const body = {
        name: this.listForm.name,
        customer_group_id: this.listForm.customer_group_id,
        starts_on: this.listForm.starts_on || null,
        ends_on: this.listForm.ends_on || null,
        is_active: this.listForm.is_active
      };
      const req = this.editingList
        ? axios.put("price_lists/" + this.editingList.id, body)
        : axios.post("price_lists", body);
      req.then(() => {
        this.Processing = false;
        this.$bvModal.hide("pl_modal");
        this.Get_Data();
        this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
      }).catch(e => {
        this.Processing = false;
        this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
      });
    },

    Remove_List(l) {
      this.confirmThen(l.name, () =>
        axios.delete("price_lists/" + l.id).then(() => {
          this.Get_Data();
          this.makeToast("success", this.$t("Successfully_Deleted"), this.$t("Success"));
        })
      );
    },

    Open_Items(l) {
      this.currentList = l;
      this.itemForm = { product_id: null, min_quantity: 0, price: null, discount_percent: null };
      this.Load_Items();
      this.$bvModal.show("pli_modal");
    },

    Load_Items() {
      axios.get("price_lists/" + this.currentList.id + "/items").then(r => {
        this.items = r.data.items;
      });
    },

    Add_Item() {
      this.Processing = true;
      axios
        .post("price_lists/" + this.currentList.id + "/items", {
          product_id: this.itemForm.product_id,
          min_quantity: this.itemForm.min_quantity || 0,
          price: this.itemForm.price === "" ? null : this.itemForm.price,
          discount_percent: this.itemForm.discount_percent === "" ? null : this.itemForm.discount_percent
        })
        .then(() => {
          this.Processing = false;
          this.itemForm = { product_id: null, min_quantity: 0, price: null, discount_percent: null };
          this.Load_Items();
          this.Get_Data();
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    Remove_Item(it) {
      axios.delete("price_list_items/" + it.id).then(() => {
        this.Load_Items();
        this.Get_Data();
      });
    },

    confirmThen(text, fn) {
      this.$swal({
        title: this.$t("Are_you_sure"),
        text: text,
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: this.$t("Yes_delete")
      }).then(result => {
        if (!result.value) return;
        fn().catch(e => this.makeToast("danger", this.errorOf(e), this.$t("Failed")));
      });
    },

    errorOf(error) {
      const d = error.response && error.response.data;
      if (d && d.errors) {
        const first = Object.keys(d.errors)[0];
        return d.errors[first][0];
      }
      return (d && d.message) || this.$t("InvalidData");
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    }
  },

  created() {
    this.Get_Data();
    this.Get_Products();
  }
};
</script>
