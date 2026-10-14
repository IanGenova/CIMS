<template>
  <div class="main-content">
    <breadcumb :page="$t('StockMovements')" :folder="$t('Reports')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-card class="mb-3">
        <b-row>
          <b-col md="3">
            <b-form-group :label="$t('Product')" class="mb-0">
              <b-form-input
                v-model="search"
                debounce="500"
                @update="Reset_Page"
                :placeholder="$t('SearchByNameOrCode')"
              />
            </b-form-group>
          </b-col>
          <b-col md="3">
            <b-form-group :label="$t('warehouse')" class="mb-0">
              <v-select
                @input="Reset_Page"
                v-model="warehouse_id"
                :reduce="label => label.value"
                :placeholder="$t('AllWarehouses')"
                :options="warehouses.map(w => ({label: w.name, value: w.id}))"
              />
            </b-form-group>
          </b-col>
          <b-col md="2">
            <b-form-group :label="$t('MovementType')" class="mb-0">
              <v-select
                @input="Reset_Page"
                v-model="movement_type"
                :reduce="label => label.value"
                :placeholder="$t('AllTypes')"
                :options="typeOptions"
              />
            </b-form-group>
          </b-col>
          <b-col md="2">
            <b-form-group :label="$t('From')" class="mb-0">
              <b-form-input type="date" v-model="from" @change="Reset_Page"/>
            </b-form-group>
          </b-col>
          <b-col md="2">
            <b-form-group :label="$t('To')" class="mb-0">
              <b-form-input type="date" v-model="to" @change="Reset_Page"/>
            </b-form-group>
          </b-col>
        </b-row>
      </b-card>

      <b-card v-if="!movements.length">
        <p class="text-center text-muted mb-0 py-4">{{ $t('NoMovementsFound') }}</p>
      </b-card>

      <b-card v-else>
        <div class="table-responsive">
          <table class="table table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('date') }}</th>
                <th>{{ $t('MovementType') }}</th>
                <th>{{ $t('ProductName') }}</th>
                <th>{{ $t('warehouse') }}</th>
                <th class="text-right">{{ $t('Quantity') }}</th>
                <th class="text-right">{{ $t('BalanceAfter') }}</th>
                <th class="text-right">{{ $t('UnitCost') }}</th>
                <th class="text-right">{{ $t('TotalCost') }}</th>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('User') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in movements" :key="row.id">
                <td class="text-nowrap">{{ row.date }}</td>
                <td>
                  <span class="badge" :class="typeClass(row.type)">{{ row.type_label }}</span>
                </td>
                <td>
                  <a class="cursor-pointer product-link" @click="Open_Card(row)">
                    {{ row.product }}
                    <small class="text-muted d-block">{{ row.code }}</small>
                  </a>
                </td>
                <td>{{ row.warehouse }}</td>
                <td class="text-right" :class="row.quantity < 0 ? 'qty-down' : 'qty-up'">
                  {{ signed(row.quantity) }}
                </td>
                <td class="text-right">{{ number(row.balance_after) }}</td>
                <td class="text-right">{{ row.unit_cost === null ? '—' : money(row.unit_cost) }}</td>
                <td class="text-right">{{ row.total_cost === null ? '—' : money(row.total_cost) }}</td>
                <td>{{ row.reference || '—' }}</td>
                <td>{{ row.user || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <b-pagination
          v-model="page"
          :total-rows="totalRows"
          :per-page="limit"
          @change="Change_Page"
          align="right"
          class="mt-3 mb-0"
        />
      </b-card>
    </div>

    <!-- One product's card: how its stock got to where it is -->
    <b-modal id="stock_card" size="xl" :title="$t('StockCard')" hide-footer>
      <div v-if="card">
        <b-row class="mb-3">
          <b-col md="3">
            <p class="text-muted mb-0">{{ $t('ProductName') }}</p>
            <p class="mb-0"><strong>{{ card.product.name }}</strong></p>
            <small class="text-muted">{{ card.product.code }}</small>
          </b-col>
          <b-col md="3">
            <p class="text-muted mb-0">{{ $t('InStock') }}</p>
            <p class="text-24 line-height-1 mb-0">{{ number(card.product.on_hand) }}</p>
          </b-col>
          <b-col md="3">
            <p class="text-muted mb-0">{{ $t('AverageCost') }}</p>
            <p class="text-24 line-height-1 mb-0">
              {{ currentUser.currency }} {{ money(card.product.average_cost) }}
            </p>
          </b-col>
          <b-col md="3">
            <p class="text-muted mb-0">{{ $t('StockValue') }}</p>
            <p class="text-24 line-height-1 mb-0">
              {{ currentUser.currency }} {{ money(card.product.value) }}
            </p>
          </b-col>
        </b-row>

        <div class="table-responsive">
          <table class="table table-sm table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('date') }}</th>
                <th>{{ $t('MovementType') }}</th>
                <th>{{ $t('warehouse') }}</th>
                <th class="text-right">{{ $t('Quantity') }}</th>
                <th class="text-right">{{ $t('BalanceAfter') }}</th>
                <th class="text-right">{{ $t('UnitCost') }}</th>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('User') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="mv in card.movements" :key="mv.id">
                <td class="text-nowrap">{{ mv.date }}</td>
                <td><span class="badge" :class="typeClass(mv.type)">{{ mv.type_label }}</span></td>
                <td>{{ mv.warehouse }}</td>
                <td class="text-right" :class="mv.quantity < 0 ? 'qty-down' : 'qty-up'">
                  {{ signed(mv.quantity) }}
                </td>
                <td class="text-right">{{ number(mv.balance_after) }}</td>
                <td class="text-right">{{ mv.unit_cost === null ? '—' : money(mv.unit_cost) }}</td>
                <td>{{ mv.reference || '—' }}</td>
                <td>{{ mv.user || '—' }}</td>
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
  metaInfo: {
    title: "Stock Movements"
  },

  data() {
    return {
      isLoading: true,
      movements: [],
      warehouses: [],
      types: {},
      search: "",
      warehouse_id: "",
      movement_type: "",
      from: "",
      to: "",
      page: 1,
      limit: 25,
      totalRows: 0,
      card: null
    };
  },

  computed: {
    ...mapGetters(["currentUser"]),

    typeOptions() {
      return Object.keys(this.types).map(k => ({ label: this.types[k], value: k }));
    }
  },

  methods: {
    // Grouped, because a stock value runs to six figures and 1229134.50 is
    // not a number anyone can read at a glance.
    money(value) {
      return Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    },

    // Stock is kept to six decimals but reads better without the trailing zeros.
    number(value) {
      return Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 4 });
    },

    signed(value) {
      const n = Number(value || 0);
      return (n > 0 ? "+" : "") + this.number(n);
    },

    // Receipts read as gains, issues as losses, and an unrecorded movement is
    // flagged rather than blending in -- it means a path that has not been wired up.
    typeClass(type) {
      if (type === "unknown") return "badge-warning";
      if (["sale", "pos", "transfer_out", "purchase_return", "pos_hold"].indexOf(type) !== -1) {
        return "badge-danger";
      }
      if (["goods_receipt", "purchase", "sale_return", "transfer_in", "production", "pos_hold_release"].indexOf(type) !== -1) {
        return "badge-success";
      }
      return "badge-secondary";
    },

    Reset_Page() {
      this.page = 1;
      this.Get_Movements();
    },

    Change_Page(page) {
      this.page = page;
      this.$nextTick(this.Get_Movements);
    },

    Get_Movements() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("stock/movements", {
          params: {
            page: this.page,
            limit: this.limit,
            search: this.search || "",
            warehouse_id: this.warehouse_id || "",
            movement_type: this.movement_type || "",
            from: this.from || "",
            to: this.to || ""
          }
        })
        .then(response => {
          this.movements = response.data.movements;
          this.warehouses = response.data.warehouses;
          this.types = response.data.types;
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

    Open_Card(row) {
      this.card = null;
      this.$bvModal.show("stock_card");
      axios
        .get("stock/movements/product/" + row.product_id, {
          params: { warehouse_id: this.warehouse_id || "" }
        })
        .then(response => {
          this.card = response.data;
        })
        .catch(() => {
          this.$bvModal.hide("stock_card");
          this.$root.$bvToast.toast(this.$t("CouldNotLoadStockCard"), {
            title: this.$t("Failed"),
            variant: "danger",
            solid: true
          });
        });
    }
  },

  created() {
    this.Get_Movements();
  }
};
</script>

<style scoped>
/* The theme's success/danger tones are button fills; as small text they sit
   at 2.5:1. These are the measured equivalents that clear 4.5:1 on each
   theme's card background -- and on the hover grey a row picks up, where
   #0a8060 fell to 4.16. */
.qty-up {
  color: #087453;
}

.qty-down {
  color: #c62828;
}

.dark-theme .qty-up {
  color: #10b981;
}

.dark-theme .qty-down {
  color: #f87171;
}

/* A product name is a link, but in a table of them the accent colour is both
   unreadable and noisy. Keep the body colour and show the affordance on hover. */
.product-link {
  color: inherit;
  text-decoration: none;
}

.product-link:hover,
.product-link:focus {
  color: inherit;
  text-decoration: underline;
}

/* The pager's links take the same accent green, which is 2.54:1 on white. The
   deep selector is needed because the markup belongs to b-pagination. The
   current page is left alone -- it is white on a filled chip, and recolouring it
   puts green text on a green ground. */
.pagination >>> .page-item:not(.active) .page-link {
  color: #087453;
}

.dark-theme .pagination >>> .page-item:not(.active) .page-link {
  color: #10b981;
}
</style>
