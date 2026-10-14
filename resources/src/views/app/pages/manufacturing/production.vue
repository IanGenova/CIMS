<template>
  <div class="main-content">
    <breadcumb :page="$t('Manufacturing')" :folder="$t('Products')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-tabs card>
        <!-- Recipes -->
        <b-tab :title="$t('BillsOfMaterial')" active>
          <div class="d-flex justify-content-between align-items-center mb-3">
            <p class="text-muted mb-0">{{ $t('BomIntro') }}</p>
            <b-button variant="primary" size="sm" @click="Open_Create_Bom">
              <i class="i-Add"></i> {{ $t('Add') }}
            </b-button>
          </div>

          <div v-if="!boms.length" class="text-center text-muted py-4">{{ $t('NoBomsYet') }}</div>

          <div v-else class="table-responsive">
            <table class="table table-hover">
              <thead class="bg-gray-300">
                <tr>
                  <th>{{ $t('Name') }}</th>
                  <th>{{ $t('Produces') }}</th>
                  <th class="text-right">{{ $t('OutputQty') }}</th>
                  <th>{{ $t('Components') }}</th>
                  <th class="text-right">{{ $t('UnitCost') }}</th>
                  <th class="text-center">{{ $t('Status') }}</th>
                  <th class="text-center">{{ $t('Action') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="b in boms" :key="b.id">
                  <td><strong>{{ b.name }}</strong></td>
                  <td>{{ b.product }}</td>
                  <td class="text-right">{{ b.output_quantity }}</td>
                  <td>
                    <span v-for="i in b.items" :key="i.id" class="badge badge-outline-primary mr-1">
                      {{ i.code }} × {{ i.quantity }}<span v-if="i.waste_percent"> +{{ i.waste_percent }}%</span>
                    </span>
                  </td>
                  <!-- What one finished unit costs by the recipe, waste included. -->
                  <td class="text-right"><strong>{{ money(b.unit_cost) }}</strong></td>
                  <td class="text-center">
                    <span class="badge" :class="b.is_active ? 'badge-outline-success' : 'badge-outline-secondary'">
                      {{ b.is_active ? $t('Active') : $t('Inactive') }}
                    </span>
                  </td>
                  <td class="text-center">
                    <a @click="Open_Edit_Bom(b)" class="cursor-pointer mr-2" title="Edit">
                      <i class="i-Pen-2 text-25 text-success"></i>
                    </a>
                    <a @click="Remove_Bom(b)" class="cursor-pointer" title="Delete">
                      <i class="i-Close-Window text-25 text-danger"></i>
                    </a>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </b-tab>

        <!-- Production orders -->
        <b-tab :title="$t('ProductionOrders')" @click="Get_Orders">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <p class="text-muted mb-0">{{ $t('ProductionIntro') }}</p>
            <b-button variant="primary" size="sm" :disabled="!boms.length" @click="Open_Create_Order">
              <i class="i-Add"></i> {{ $t('Add') }}
            </b-button>
          </div>

          <div v-if="!orders.length" class="text-center text-muted py-4">{{ $t('NoProductionOrdersYet') }}</div>

          <div v-else class="table-responsive">
            <table class="table table-hover">
              <thead class="bg-gray-300">
                <tr>
                  <th>{{ $t('Reference') }}</th>
                  <th>{{ $t('date') }}</th>
                  <th>{{ $t('Produces') }}</th>
                  <th>{{ $t('warehouse') }}</th>
                  <th class="text-right">{{ $t('Quantity') }}</th>
                  <th class="text-right">{{ $t('UnitCost') }}</th>
                  <th class="text-center">{{ $t('Status') }}</th>
                  <th class="text-center">{{ $t('Action') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="o in orders" :key="o.id">
                  <td><strong>{{ o.Ref }}</strong></td>
                  <td>{{ o.date }}</td>
                  <td>{{ o.product }}</td>
                  <td>{{ o.warehouse }}</td>
                  <td class="text-right">{{ o.quantity }}</td>
                  <td class="text-right">{{ o.status === 'completed' ? money(o.unit_cost) : '—' }}</td>
                  <td class="text-center">
                    <span class="badge" :class="statusBadge(o.status)">{{ $t('PO_' + o.status) }}</span>
                  </td>
                  <td class="text-center">
                    <template v-if="o.status === 'planned' || o.status === 'in_progress'">
                      <b-button size="sm" variant="outline-primary" class="mr-1" @click="Open_Build(o)">
                        {{ $t('Build') }}
                      </b-button>
                      <b-button size="sm" variant="outline-danger" @click="Cancel_Order(o)">
                        {{ $t('Cancel') }}
                      </b-button>
                    </template>
                    <span v-else class="text-muted small">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </b-tab>
      </b-tabs>
    </div>

    <!-- BOM -->
    <b-modal id="bom_modal" :title="editingBom ? $t('EditBom') : $t('NewBom')" hide-footer size="lg">
      <b-row>
        <b-col md="6">
          <b-form-group :label="$t('Name')">
            <b-form-input v-model="bomForm.name" :placeholder="$t('BomNamePlaceholder')"/>
          </b-form-group>
        </b-col>
        <b-col md="6">
          <b-form-group :label="$t('Produces')">
            <v-select
              v-model="bomForm.product_id"
              :reduce="label => label.value"
              :placeholder="$t('Choose_Product')"
              :options="productOptions"
            />
          </b-form-group>
        </b-col>
      </b-row>

      <b-form-group :label="$t('OutputQty')">
        <b-form-input type="number" step="any" min="0" v-model.number="bomForm.output_quantity"/>
        <small class="text-muted">{{ $t('OutputQtyNote') }}</small>
      </b-form-group>

      <label class="mt-2">{{ $t('Components') }}</label>
      <table class="table table-sm">
        <thead>
          <tr>
            <th>{{ $t('Product') }}</th>
            <th style="width:120px">{{ $t('Quantity') }}</th>
            <th style="width:120px">{{ $t('WastePercent') }}</th>
            <th style="width:40px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(it, i) in bomForm.items" :key="i">
            <td>
              <v-select
                v-model="it.product_id"
                :reduce="label => label.value"
                :placeholder="$t('Choose_Product')"
                :options="productOptions"
              />
            </td>
            <td><b-form-input size="sm" type="number" step="any" min="0" v-model.number="it.quantity"/></td>
            <td><b-form-input size="sm" type="number" step="any" min="0" v-model.number="it.waste_percent"/></td>
            <td>
              <a v-if="bomForm.items.length > 1" @click="bomForm.items.splice(i, 1)" class="cursor-pointer">
                <i class="i-Close-Window text-20 text-danger"></i>
              </a>
            </td>
          </tr>
        </tbody>
      </table>

      <b-button size="sm" variant="outline-primary"
        @click="bomForm.items.push({product_id: null, quantity: 1, waste_percent: 0})">
        {{ $t('AddComponent') }}
      </b-button>

      <b-form-checkbox v-model="bomForm.is_active" switch class="mt-3">{{ $t('Active') }}</b-form-checkbox>

      <b-button class="mt-3" variant="primary" :disabled="Processing || !canSaveBom" @click="Save_Bom">
        {{ $t('Submit') }}
      </b-button>
    </b-modal>

    <!-- order -->
    <b-modal id="order_modal" :title="$t('NewProductionOrder')" hide-footer>
      <b-form-group :label="$t('BillOfMaterials')">
        <v-select
          v-model="orderForm.bom_id"
          :reduce="label => label.value"
          :placeholder="$t('Choose')"
          :options="boms.filter(b => b.is_active).map(b => ({label: b.name + ' → ' + b.product, value: b.id}))"
        />
      </b-form-group>
      <b-form-group :label="$t('warehouse')">
        <v-select
          v-model="orderForm.warehouse_id"
          :reduce="label => label.value"
          :placeholder="$t('Choose_Warehouse')"
          :options="warehouses.map(w => ({label: w.name, value: w.id}))"
        />
      </b-form-group>
      <b-row>
        <b-col md="6">
          <b-form-group :label="$t('Quantity')">
            <b-form-input type="number" step="any" min="0" v-model.number="orderForm.quantity"/>
          </b-form-group>
        </b-col>
        <b-col md="6">
          <b-form-group :label="$t('date')">
            <b-form-input type="date" v-model="orderForm.date"/>
          </b-form-group>
        </b-col>
      </b-row>
      <b-button variant="primary"
        :disabled="Processing || !orderForm.bom_id || !orderForm.warehouse_id || !orderForm.quantity"
        @click="Save_Order">{{ $t('Submit') }}</b-button>
    </b-modal>

    <!-- build: shows what will be consumed before committing -->
    <b-modal id="build_modal" :title="$t('BuildOrder')" hide-footer size="lg">
      <div v-if="buildTarget">
        <p class="mb-3">
          <strong>{{ buildTarget.Ref }}</strong> — {{ buildTarget.quantity }} × {{ buildTarget.product }}
        </p>

        <div v-if="requirements.length" class="table-responsive">
          <table class="table table-sm">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('Component') }}</th>
                <th class="text-right">{{ $t('Required') }}</th>
                <th class="text-right">{{ $t('Available') }}</th>
                <th class="text-right">{{ $t('Short') }}</th>
                <th class="text-right">{{ $t('Cost') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in requirements" :key="r.product_id" :class="r.short > 0 ? 'table-danger' : ''">
                <td>{{ r.code }} — {{ r.name }}</td>
                <td class="text-right">{{ r.required }}</td>
                <td class="text-right">{{ r.available }}</td>
                <td class="text-right" :class="r.short > 0 ? 'text-danger' : 'text-muted'">
                  {{ r.short > 0 ? r.short : '—' }}
                </td>
                <td class="text-right">{{ money(r.line_cost) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="mt-2">{{ $t('EstimatedCost') }}: <strong>{{ money(estimatedCost) }}</strong></p>

        <!-- A batch-tracked output needs its own lot number. -->
        <div v-if="canBuild">
          <b-form-group :label="$t('BatchNumber')" v-if="needsBatch">
            <b-form-input v-model="buildForm.batch_number" :placeholder="$t('OutputLotPlaceholder')"/>
          </b-form-group>
          <b-row v-if="needsBatch">
            <b-col md="6">
              <b-form-group :label="$t('ManufactureDate')">
                <b-form-input type="date" v-model="buildForm.manufacture_date"/>
              </b-form-group>
            </b-col>
            <b-col md="6">
              <b-form-group :label="$t('ExpiryDate')">
                <b-form-input type="date" v-model="buildForm.expiry_date"/>
              </b-form-group>
            </b-col>
          </b-row>
        </div>

        <b-alert v-else variant="danger" show class="mt-2">{{ $t('NotEnoughToBuild') }}</b-alert>

        <b-button variant="primary" :disabled="Processing || !canBuild" @click="Do_Build">
          {{ $t('Build') }}
        </b-button>
      </div>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";
import { startingWarehouse } from "../../../../utils/defaultWarehouse";

export default {
  metaInfo: { title: "Manufacturing" },

  data() {
    return {
      isLoading: true,
      Processing: false,
      boms: [],
      orders: [],
      products: [],
      warehouses: [],
      editingBom: null,
      buildTarget: null,
      requirements: [],
      estimatedCost: 0,
      canBuild: false,
      needsBatch: false,
      bomForm: {
        name: "", product_id: null, output_quantity: 1, is_active: true,
        items: [{ product_id: null, quantity: 1, waste_percent: 0 }]
      },
      orderForm: {
        bom_id: null, warehouse_id: null, quantity: null,
        date: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10)
      },
      buildForm: { batch_number: "", manufacture_date: "", expiry_date: "" }
    };
  },

  computed: {
    ...mapGetters(["currentUser"]),

    productOptions() {
      return this.products.map(p => ({ label: p.code + " — " + p.name, value: p.id }));
    },

    canSaveBom() {
      return this.bomForm.name && this.bomForm.product_id && this.bomForm.output_quantity > 0 &&
        this.bomForm.items.length &&
        this.bomForm.items.every(i => i.product_id && i.quantity > 0);
    }
  },

  methods: {
    money(v) {
      const symbol = (this.currentUser && this.currentUser.currency) || "";
      return (symbol ? symbol + " " : "") + Number(v || 0).toFixed(2);
    },

    statusBadge(s) {
      const map = {
        planned: "badge-outline-secondary",
        in_progress: "badge-outline-primary",
        completed: "badge-outline-success",
        cancelled: "badge-outline-danger"
      };
      return map[s] || "badge-outline-secondary";
    },

    Get_Boms() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("manufacturing/boms")
        .then(r => {
          this.boms = r.data.boms;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    Get_Orders() {
      axios.get("manufacturing/orders").then(r => { this.orders = r.data.orders; });
    },

    Get_Refs() {
      axios.get("Products?page=1&limit=1000&SortField=name&SortType=asc").then(r => {
        this.products = r.data.products || [];
      });
      axios.get("warehouses?page=1&limit=100&SortField=id&SortType=asc").then(r => {
        this.warehouses = r.data.warehouses || [];
      });
    },

    Open_Create_Bom() {
      this.editingBom = null;
      this.bomForm = {
        name: "", product_id: null, output_quantity: 1, is_active: true,
        items: [{ product_id: null, quantity: 1, waste_percent: 0 }]
      };
      this.$bvModal.show("bom_modal");
    },

    Open_Edit_Bom(b) {
      this.editingBom = b;
      this.bomForm = {
        name: b.name,
        product_id: b.product_id,
        output_quantity: b.output_quantity,
        is_active: b.is_active,
        items: b.items.map(i => ({
          product_id: i.product_id, quantity: i.quantity, waste_percent: i.waste_percent
        }))
      };
      this.$bvModal.show("bom_modal");
    },

    Save_Bom() {
      this.Processing = true;
      const req = this.editingBom
        ? axios.put("manufacturing/boms/" + this.editingBom.id, this.bomForm)
        : axios.post("manufacturing/boms", this.bomForm);
      req
        .then(() => {
          this.Processing = false;
          this.$bvModal.hide("bom_modal");
          this.Get_Boms();
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    Remove_Bom(b) {
      this.confirmThen(b.name, () =>
        axios.delete("manufacturing/boms/" + b.id).then(() => {
          this.Get_Boms();
          this.makeToast("success", this.$t("Successfully_Deleted"), this.$t("Success"));
        })
      );
    },

    Open_Create_Order() {
      this.orderForm = {
        bom_id: null, quantity: null,
        // The user's only warehouse is preselected.
        warehouse_id: startingWarehouse(this.warehouses, null),
        date: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10)
      };
      this.$bvModal.show("order_modal");
    },

    Save_Order() {
      this.Processing = true;
      axios
        .post("manufacturing/orders", this.orderForm)
        .then(() => {
          this.Processing = false;
          this.$bvModal.hide("order_modal");
          this.Get_Orders();
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    Open_Build(o) {
      this.buildTarget = o;
      this.buildForm = { batch_number: "", manufacture_date: "", expiry_date: "" };
      axios.get("manufacturing/orders/" + o.id + "/requirements").then(r => {
        this.requirements = r.data.requirements;
        this.canBuild = r.data.can_build;
        this.estimatedCost = r.data.estimated_cost;
        const p = this.products.find(x => x.id === o.product_id);
        this.needsBatch = !!(p && p.has_batches);
        this.$bvModal.show("build_modal");
      });
    },

    Do_Build() {
      this.Processing = true;
      axios
        .post("manufacturing/orders/" + this.buildTarget.id + "/complete", this.buildForm)
        .then(r => {
          this.Processing = false;
          this.$bvModal.hide("build_modal");
          this.Get_Orders();
          this.makeToast(
            "success",
            this.$t("UnitCost") + ": " + this.money(r.data.unit_cost),
            this.$t("Success")
          );
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    Cancel_Order(o) {
      this.confirmThen(o.Ref, () =>
        axios.post("manufacturing/orders/" + o.id + "/cancel", {}).then(() => {
          this.Get_Orders();
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        })
      );
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
    this.Get_Boms();
    this.Get_Orders();
    this.Get_Refs();
  }
};
</script>
