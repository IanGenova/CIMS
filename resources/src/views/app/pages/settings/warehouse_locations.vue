<template>
  <div class="main-content">
    <breadcumb :page="$t('BinLocations')" :folder="$t('Settings')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-card class="mb-3">
        <b-row class="align-items-end">
          <b-col md="4">
            <b-form-group :label="$t('warehouse')" class="mb-0">
              <v-select
                @input="Reload"
                v-model="warehouse_id"
                :reduce="label => label.value"
                :placeholder="$t('Choose_Warehouse')"
                :options="warehouses.map(w => ({label: w.name, value: w.id}))"
              />
            </b-form-group>
          </b-col>
          <b-col md="4" v-if="currentWarehouse">
            <b-form-checkbox v-model="usesLocations" switch @change="ToggleWarehouse">
              {{ $t('UseBinLocations') }}
            </b-form-checkbox>
            <small class="text-muted">{{ $t('BinLocationsNote') }}</small>
          </b-col>
          <b-col md="4" class="text-right">
            <b-button variant="primary" size="sm" :disabled="!warehouse_id" @click="Open_Create">
              <i class="i-Add"></i> {{ $t('AddBin') }}
            </b-button>
          </b-col>
        </b-row>
      </b-card>

      <div v-if="!warehouse_id" class="text-center text-muted py-5">
        {{ $t('ChooseAWarehouseFirst') }}
      </div>

      <template v-else>
        <b-tabs card>
          <!-- The bins themselves -->
          <b-tab :title="$t('Bins')" active>
            <div v-if="!locations.length" class="text-center text-muted py-4">
              {{ $t('NoBinsYet') }}
            </div>
            <div v-else class="table-responsive">
              <table class="table table-hover">
                <thead class="bg-gray-300">
                  <tr>
                    <th class="text-right">{{ $t('PickOrder') }}</th>
                    <th>{{ $t('BinCode') }}</th>
                    <th>{{ $t('Zone') }}</th>
                    <th>{{ $t('Aisle') }}</th>
                    <th>{{ $t('Rack') }}</th>
                    <th class="text-right">{{ $t('Holding') }}</th>
                    <th class="text-center">{{ $t('Receiving') }}</th>
                    <th class="text-center">{{ $t('Status') }}</th>
                    <th class="text-center">{{ $t('Action') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="l in locations" :key="l.id">
                    <td class="text-right text-muted">{{ l.pick_sequence }}</td>
                    <td><strong>{{ l.code }}</strong></td>
                    <td>{{ l.zone || '—' }}</td>
                    <td>{{ l.aisle || '—' }}</td>
                    <td>{{ l.rack || '—' }}</td>
                    <td class="text-right">{{ l.held_qty }} <small class="text-muted">({{ l.product_lines }})</small></td>
                    <td class="text-center">
                      <i v-if="l.is_receiving" class="i-Check text-20 text-success"></i>
                      <span v-else class="text-muted">—</span>
                    </td>
                    <td class="text-center">
                      <span class="badge" :class="l.is_active ? 'badge-outline-success' : 'badge-outline-secondary'">
                        {{ l.is_active ? $t('Active') : $t('Inactive') }}
                      </span>
                    </td>
                    <td class="text-center">
                      <a @click="Open_Edit(l)" class="cursor-pointer mr-2" title="Edit">
                        <i class="i-Pen-2 text-25 text-success"></i>
                      </a>
                      <a @click="Remove(l)" class="cursor-pointer" title="Delete">
                        <i class="i-Close-Window text-25 text-danger"></i>
                      </a>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </b-tab>

          <!-- Where the stock actually is -->
          <b-tab :title="$t('StockByBin')" @click="Load_Stock">
            <b-row class="mb-3">
              <b-col md="4">
                <b-form-input v-model="stockSearch" @input="Load_Stock" :placeholder="$t('Search')"/>
              </b-col>
              <b-col md="8" class="text-right">
                <span v-if="totalUnallocated > 0" class="badge badge-outline-warning">
                  {{ $t('WaitingToBePutAway') }}: {{ totalUnallocated }}
                </span>
              </b-col>
            </b-row>

            <div v-if="!stockRows.length" class="text-center text-muted py-4">{{ $t('NoStockHere') }}</div>

            <div v-else class="table-responsive">
              <table class="table table-hover">
                <thead class="bg-gray-300">
                  <tr>
                    <th>{{ $t('ProductCode') }}</th>
                    <th>{{ $t('ProductName') }}</th>
                    <th class="text-right">{{ $t('InWarehouse') }}</th>
                    <th class="text-right">{{ $t('PutAway') }}</th>
                    <th class="text-right">{{ $t('Unallocated') }}</th>
                    <th>{{ $t('Bins') }}</th>
                    <th class="text-center">{{ $t('Action') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="r in stockRows" :key="r.product_id">
                    <td>{{ r.code }}</td>
                    <td>{{ r.product }}</td>
                    <td class="text-right">{{ r.warehouse_qty }}</td>
                    <td class="text-right">{{ r.allocated }}</td>
                    <!-- Stock that has arrived but has not been shelved. -->
                    <td class="text-right" :class="r.unallocated > 0 ? 'text-warning' : 'text-muted'">
                      {{ r.unallocated }}
                    </td>
                    <td>
                      <span v-for="b in r.bins" :key="b.location_id" class="badge badge-outline-primary mr-1">
                        {{ b.code }}: {{ b.qte }}
                      </span>
                      <span v-if="!r.bins.length" class="text-muted">—</span>
                    </td>
                    <td class="text-center">
                      <a @click="Open_PutAway(r)" class="cursor-pointer mr-2" :title="$t('PutAway')">
                        <i class="i-Download text-25 text-primary"></i>
                      </a>
                      <a v-if="r.bins.length" @click="Open_Move(r)" class="cursor-pointer" :title="$t('MoveStock')">
                        <i class="i-Arrow-Forward text-25 text-success"></i>
                      </a>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </b-tab>

          <b-tab :title="$t('Movements')" @click="Load_Movements">
            <div v-if="!movements.length" class="text-center text-muted py-4">{{ $t('NoMovementsYet') }}</div>
            <div v-else class="table-responsive">
              <table class="table table-sm table-hover">
                <thead class="bg-gray-300">
                  <tr>
                    <th>{{ $t('date') }}</th>
                    <th>{{ $t('ProductName') }}</th>
                    <th>{{ $t('Reason') }}</th>
                    <th>{{ $t('From') }}</th>
                    <th>{{ $t('To') }}</th>
                    <th class="text-right">{{ $t('Quantity') }}</th>
                    <th>{{ $t('User') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="m in movements" :key="m.id">
                    <td>{{ m.created_at }}</td>
                    <td>{{ m.product }}</td>
                    <td><span class="badge badge-outline-secondary">{{ m.reason }}</span></td>
                    <td>{{ m.from_code || '—' }}</td>
                    <td>{{ m.to_code || '—' }}</td>
                    <td class="text-right">{{ m.qte }}</td>
                    <td>{{ m.username || '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </b-tab>
        </b-tabs>
      </template>
    </div>

    <!-- bin -->
    <b-modal id="bin_modal" :title="editing ? $t('EditBin') : $t('AddBin')" hide-footer>
      <b-form-group :label="$t('BinCode')">
        <b-form-input v-model="form.code" :placeholder="$t('BinCodePlaceholder')"/>
      </b-form-group>
      <b-row>
        <b-col md="6"><b-form-group :label="$t('Zone')"><b-form-input v-model="form.zone"/></b-form-group></b-col>
        <b-col md="6"><b-form-group :label="$t('Aisle')"><b-form-input v-model="form.aisle"/></b-form-group></b-col>
        <b-col md="6"><b-form-group :label="$t('Rack')"><b-form-input v-model="form.rack"/></b-form-group></b-col>
        <b-col md="6"><b-form-group :label="$t('Bin')"><b-form-input v-model="form.bin"/></b-form-group></b-col>
      </b-row>
      <b-form-group :label="$t('PickOrder')">
        <b-form-input type="number" min="0" v-model.number="form.pick_sequence"/>
        <small class="text-muted">{{ $t('PickOrderNote') }}</small>
      </b-form-group>
      <b-form-checkbox v-model="form.is_receiving" switch>{{ $t('ReceivingBin') }}</b-form-checkbox>
      <b-form-checkbox v-model="form.is_active" switch class="mt-2">{{ $t('Active') }}</b-form-checkbox>
      <b-button class="mt-3" variant="primary" :disabled="Processing || !form.code" @click="Save">
        {{ $t('Submit') }}
      </b-button>
    </b-modal>

    <!-- put away -->
    <b-modal id="putaway_modal" :title="$t('PutAway')" hide-footer>
      <div v-if="target">
        <p class="mb-3"><strong>{{ target.product }}</strong><br>
          <small class="text-muted">{{ $t('Unallocated') }}: {{ target.unallocated }}</small></p>
        <b-form-group :label="$t('Bin')">
          <v-select
            v-model="moveForm.to_location_id"
            :reduce="label => label.value"
            :placeholder="$t('Choose')"
            :options="binOptions"
          />
        </b-form-group>
        <b-form-group :label="$t('Quantity')">
          <b-form-input type="number" step="any" min="0" v-model.number="moveForm.quantity"/>
        </b-form-group>
        <b-button variant="primary" :disabled="Processing || !moveForm.to_location_id || !moveForm.quantity"
          @click="Do_PutAway">{{ $t('Submit') }}</b-button>
      </div>
    </b-modal>

    <!-- move -->
    <b-modal id="move_modal" :title="$t('MoveStock')" hide-footer>
      <div v-if="target">
        <p class="mb-3"><strong>{{ target.product }}</strong></p>
        <b-form-group :label="$t('From')">
          <v-select
            v-model="moveForm.from_location_id"
            :reduce="label => label.value"
            :placeholder="$t('Choose')"
            :options="target.bins.map(b => ({label: b.code + ' (' + b.qte + ')', value: b.location_id}))"
          />
        </b-form-group>
        <b-form-group :label="$t('To')">
          <v-select
            v-model="moveForm.to_location_id"
            :reduce="label => label.value"
            :placeholder="$t('Choose')"
            :options="binOptions"
          />
        </b-form-group>
        <b-form-group :label="$t('Quantity')">
          <b-form-input type="number" step="any" min="0" v-model.number="moveForm.quantity"/>
        </b-form-group>
        <b-button variant="primary"
          :disabled="Processing || !moveForm.from_location_id || !moveForm.to_location_id || !moveForm.quantity"
          @click="Do_Move">{{ $t('Submit') }}</b-button>
      </div>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Bin Locations" },

  data() {
    return {
      isLoading: true,
      Processing: false,
      warehouses: [],
      warehouse_id: null,
      usesLocations: false,
      locations: [],
      stockRows: [],
      stockSearch: "",
      totalUnallocated: 0,
      binChoices: [],
      movements: [],
      editing: null,
      target: null,
      form: {
        code: "", zone: "", aisle: "", rack: "", bin: "",
        pick_sequence: 0, is_receiving: false, is_active: true
      },
      moveForm: { from_location_id: null, to_location_id: null, quantity: null }
    };
  },

  computed: {
    currentWarehouse() {
      return this.warehouses.find(w => w.id === this.warehouse_id);
    },
    binOptions() {
      return this.binChoices.map(b => ({
        label: b.code + (b.is_receiving ? " *" : ""),
        value: b.id
      }));
    }
  },

  methods: {
    Get_Locations() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("warehouse_locations", { params: { warehouse_id: this.warehouse_id } })
        .then(r => {
          this.locations = r.data.locations;
          this.warehouses = r.data.warehouses;
          const w = this.warehouses.find(x => x.id === this.warehouse_id);
          this.usesLocations = w ? !!w.uses_locations : false;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    Reload() {
      this.Get_Locations();
      if (this.warehouse_id) this.Load_Stock();
    },

    Load_Stock() {
      if (!this.warehouse_id) return;
      axios
        .get("warehouse_locations/stock/view", {
          params: { warehouse_id: this.warehouse_id, search: this.stockSearch }
        })
        .then(r => {
          this.stockRows = r.data.rows;
          this.totalUnallocated = r.data.total_unallocated;
          this.binChoices = r.data.locations;
        });
    },

    Load_Movements() {
      axios
        .get("warehouse_locations/movements/recent", { params: { warehouse_id: this.warehouse_id } })
        .then(r => { this.movements = r.data.movements; });
    },

    ToggleWarehouse(value) {
      axios
        .put("warehouse_locations/warehouse/" + this.warehouse_id + "/toggle", { uses_locations: value })
        .then(() => {
          this.Get_Locations();
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        })
        .catch(e => this.makeToast("danger", this.errorOf(e), this.$t("Failed")));
    },

    Open_Create() {
      this.editing = null;
      this.form = {
        code: "", zone: "", aisle: "", rack: "", bin: "",
        pick_sequence: 0, is_receiving: false, is_active: true
      };
      this.$bvModal.show("bin_modal");
    },

    Open_Edit(l) {
      this.editing = l;
      this.form = {
        code: l.code, zone: l.zone || "", aisle: l.aisle || "",
        rack: l.rack || "", bin: l.bin || "",
        pick_sequence: l.pick_sequence, is_receiving: l.is_receiving, is_active: l.is_active
      };
      this.$bvModal.show("bin_modal");
    },

    Save() {
      this.Processing = true;
      const body = Object.assign({}, this.form, { warehouse_id: this.warehouse_id });
      const req = this.editing
        ? axios.put("warehouse_locations/" + this.editing.id, body)
        : axios.post("warehouse_locations", body);
      req
        .then(() => {
          this.Processing = false;
          this.$bvModal.hide("bin_modal");
          this.Get_Locations();
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    Remove(l) {
      this.$swal({
        title: this.$t("Are_you_sure"),
        text: l.code,
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: this.$t("Yes_delete")
      }).then(result => {
        if (!result.value) return;
        axios
          .delete("warehouse_locations/" + l.id)
          .then(() => {
            this.Get_Locations();
            this.makeToast("success", this.$t("Successfully_Deleted"), this.$t("Success"));
          })
          .catch(e => this.makeToast("danger", this.errorOf(e), this.$t("Failed")));
      });
    },

    Open_PutAway(r) {
      this.target = r;
      this.moveForm = { from_location_id: null, to_location_id: null, quantity: r.unallocated };
      this.$bvModal.show("putaway_modal");
    },

    Do_PutAway() {
      this.Processing = true;
      axios
        .post("warehouse_locations/putaway", {
          warehouse_id: this.warehouse_id,
          product_id: this.target.product_id,
          product_variant_id: this.target.product_variant_id,
          location_id: this.moveForm.to_location_id,
          quantity: this.moveForm.quantity
        })
        .then(() => {
          this.Processing = false;
          this.$bvModal.hide("putaway_modal");
          this.Load_Stock();
          this.Get_Locations();
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    Open_Move(r) {
      this.target = r;
      this.moveForm = { from_location_id: null, to_location_id: null, quantity: null };
      this.$bvModal.show("move_modal");
    },

    Do_Move() {
      this.Processing = true;
      axios
        .post("warehouse_locations/move", {
          warehouse_id: this.warehouse_id,
          product_id: this.target.product_id,
          product_variant_id: this.target.product_variant_id,
          from_location_id: this.moveForm.from_location_id,
          to_location_id: this.moveForm.to_location_id,
          quantity: this.moveForm.quantity
        })
        .then(() => {
          this.Processing = false;
          this.$bvModal.hide("move_modal");
          this.Load_Stock();
          this.Get_Locations();
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
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
    this.Get_Locations();
  }
};
</script>
