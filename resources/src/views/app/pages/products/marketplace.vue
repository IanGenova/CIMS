<template>
  <div class="main-content">
    <breadcumb :page="$t('Marketplace')" :folder="$t('Products')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <!-- The queue comes first, and it is the reason the screen exists. An
           order held for an unmapped SKU is not an error, it is a job, and it
           stays invisible until somebody is shown it. -->
      <b-card v-if="waiting.length" class="mb-3 border-warning">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <h5 class="mb-0">
            <i class="i-Bell text-warning mr-2"></i>
            {{ $t('OrdersWaiting') }}
            <b-badge variant="warning" class="ml-1">{{ waiting.length }}</b-badge>
          </h5>
          <b-button size="sm" variant="outline-secondary" @click="Get_Everything">
            <i class="i-Repeat-2"></i> {{ $t('Refresh') }}
          </b-button>
        </div>

        <div class="table-responsive">
          <table class="table table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('Shop') }}</th>
                <th>{{ $t('OrderNumber') }}</th>
                <th>{{ $t('date') }}</th>
                <th>{{ $t('Customer') }}</th>
                <th class="text-right">{{ $t('Total') }}</th>
                <th>{{ $t('WaitingFor') }}</th>
                <th class="text-center">{{ $t('Action') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="order in waiting" :key="order.id">
                <td>
                  <b-badge :variant="channelVariant(order.channel)">{{ order.channel }}</b-badge>
                  <span class="ml-1">{{ order.account_name }}</span>
                </td>
                <td>{{ order.external_order_id }}</td>
                <td>{{ order.ordered_at }}</td>
                <td>{{ order.buyer_name || '—' }}</td>
                <td class="text-right">{{ order.currency }} {{ money(order.total) }}</td>
                <td>
                  <template v-if="order.missing_skus.length">
                    <!-- The SKU is a button rather than text, because naming the
                         problem and fixing it should not be two screens. -->
                    <b-button
                      v-for="sku in order.missing_skus"
                      :key="sku"
                      size="sm"
                      variant="outline-warning"
                      class="mr-1 mb-1"
                      @click="Open_Mapping(order, sku)"
                    >
                      <i class="i-Link"></i> {{ sku }}
                    </b-button>
                  </template>
                  <span v-else class="text-muted">{{ order.reason || $t('Failed') }}</span>
                </td>
                <td class="text-center">
                  <b-button
                    size="sm"
                    variant="primary"
                    :disabled="retrying === order.id"
                    @click="Retry(order)"
                  >
                    <b-spinner small v-if="retrying === order.id"></b-spinner>
                    <span v-else>{{ $t('PutThrough') }}</span>
                  </b-button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </b-card>

      <b-card v-else class="mb-3">
        <p class="text-center text-muted mb-0 py-3">
          <i class="i-Check text-success mr-1"></i> {{ $t('NothingWaiting') }}
        </p>
      </b-card>

      <!-- The tab strip is drawn by b-tabs, not by b-tab, so a click handler on
           a b-tab is an event nothing ever emits. This is the one that fires. -->
      <b-tabs content-class="mt-3" v-model="tab" @activate-tab="On_Tab">
        <!-- ------------------------------------------------ mappings -->
        <b-tab :title="$t('Mappings')">
          <b-card>
            <b-row class="mb-3">
              <b-col md="4">
                <v-select
                  v-model="filter_account"
                  :reduce="label => label.value"
                  :placeholder="$t('AllShops')"
                  :options="accountOptions"
                  @input="Get_Listings"
                />
              </b-col>
              <b-col md="5">
                <b-form-input
                  v-model="filter_search"
                  :placeholder="$t('SearchBySkuOrProduct')"
                  @change="Get_Listings"
                />
              </b-col>
              <b-col md="3" class="text-right">
                <b-button variant="primary" :disabled="!accounts.length" @click="Open_Mapping()">
                  <i class="i-Add"></i> {{ $t('AddMapping') }}
                </b-button>
              </b-col>
            </b-row>

            <p v-if="!listings.length" class="text-center text-muted py-4 mb-0">
              {{ $t('NoMappings') }}
            </p>

            <div v-else class="table-responsive">
              <table class="table table-hover">
                <thead class="bg-gray-300">
                  <tr>
                    <th>{{ $t('Shop') }}</th>
                    <th>{{ $t('MarketplaceSku') }}</th>
                    <th>{{ $t('Product') }}</th>
                    <th>{{ $t('PushStock') }}</th>
                    <th>{{ $t('LastPushed') }}</th>
                    <th class="text-center">{{ $t('Action') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in listings" :key="row.id">
                    <td>
                      <b-badge :variant="channelVariant(row.channel)">{{ row.channel }}</b-badge>
                      <span class="ml-1">{{ row.account_name }}</span>
                    </td>
                    <td>
                      <code>{{ row.external_sku }}</code>
                      <div v-if="row.external_item_id" class="text-muted small">
                        {{ row.external_item_id }}
                      </div>
                    </td>
                    <td>
                      <span v-if="row.product_name">
                        {{ row.product_name }}
                        <span class="text-muted small">({{ row.product_code }})</span>
                      </span>
                      <!-- A mapping whose product was deleted sells nothing and
                           says nothing; it has to look wrong. -->
                      <b-badge v-else variant="danger">{{ $t('ProductMissing') }}</b-badge>
                    </td>
                    <td>
                      <b-badge :variant="row.push_stock ? 'success' : 'secondary'">
                        {{ row.push_stock ? $t('Yes') : $t('No') }}
                      </b-badge>
                    </td>
                    <td>
                      <span v-if="row.last_pushed_at">
                        {{ row.last_pushed_at }}
                        <span class="text-muted">({{ money(row.last_pushed_qty) }})</span>
                      </span>
                      <span v-else class="text-muted">{{ $t('Never') }}</span>
                      <div v-if="row.last_push_error" class="text-danger small">
                        {{ row.last_push_error }}
                      </div>
                    </td>
                    <td class="text-center">
                      <b-button size="sm" variant="outline-primary" class="mr-1"
                                @click="Open_Mapping(null, null, row)">
                        <i class="i-Edit"></i>
                      </b-button>
                      <b-button size="sm" variant="outline-danger" @click="Remove_Mapping(row)">
                        <i class="i-Close-Window"></i>
                      </b-button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </b-card>
        </b-tab>

        <!-- -------------------------------------------------- orders -->
        <b-tab :title="$t('MarketplaceOrders')">
          <b-card>
            <b-row class="mb-3">
              <b-col md="4">
                <v-select
                  v-model="order_state"
                  :reduce="label => label.value"
                  :placeholder="$t('AllStatuses')"
                  :options="stateOptions"
                  @input="Get_Orders"
                />
              </b-col>
              <b-col md="5">
                <b-form-input
                  v-model="order_search"
                  :placeholder="$t('SearchByOrderOrBuyer')"
                  @change="Get_Orders"
                />
              </b-col>
            </b-row>

            <p v-if="!orders.length" class="text-center text-muted py-4 mb-0">
              {{ $t('NoMarketplaceOrders') }}
            </p>

            <div v-else class="table-responsive">
              <table class="table table-hover">
                <thead class="bg-gray-300">
                  <tr>
                    <th>{{ $t('Shop') }}</th>
                    <th>{{ $t('OrderNumber') }}</th>
                    <th>{{ $t('date') }}</th>
                    <th>{{ $t('Customer') }}</th>
                    <th class="text-right">{{ $t('Total') }}</th>
                    <th>{{ $t('Status') }}</th>
                    <th>{{ $t('Sale') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in orders" :key="row.id">
                    <td>
                      <b-badge :variant="channelVariant(row.channel)">{{ row.channel }}</b-badge>
                      <span class="ml-1">{{ row.account_name }}</span>
                    </td>
                    <td>{{ row.external_order_id }}</td>
                    <td>{{ row.ordered_at }}</td>
                    <td>{{ row.buyer_name || '—' }}</td>
                    <td class="text-right">{{ row.currency }} {{ money(row.total) }}</td>
                    <td>
                      <b-badge :variant="stateVariant(row.state)">{{ row.state }}</b-badge>
                      <div v-if="row.state_reason" class="text-muted small">
                        {{ row.state_reason }}
                      </div>
                    </td>
                    <td>
                      <span v-if="row.sale_ref">{{ row.sale_ref }}</span>
                      <span v-else class="text-muted">—</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </b-card>
        </b-tab>

        <!-- ------------------------------------------------- accounts -->
        <b-tab :title="$t('Shops')">
          <b-card>
            <div class="text-right mb-3">
              <b-button variant="primary" @click="Open_Account()">
                <i class="i-Add"></i> {{ $t('AddShop') }}
              </b-button>
            </div>

            <p v-if="!accounts.length" class="text-center text-muted py-4 mb-0">
              {{ $t('NoShops') }}
            </p>

            <div v-else class="table-responsive">
              <table class="table table-hover">
                <thead class="bg-gray-300">
                  <tr>
                    <th>{{ $t('Channel') }}</th>
                    <th>{{ $t('Name') }}</th>
                    <th>{{ $t('warehouse') }}</th>
                    <th class="text-center">{{ $t('Mappings') }}</th>
                    <th class="text-center">{{ $t('Waiting') }}</th>
                    <th>{{ $t('LastSync') }}</th>
                    <th>{{ $t('Status') }}</th>
                    <th class="text-center">{{ $t('Action') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in accounts" :key="row.id">
                    <td><b-badge :variant="channelVariant(row.channel)">{{ row.channel }}</b-badge></td>
                    <td>
                      {{ row.name }}
                      <div v-if="row.external_shop_id" class="text-muted small">
                        {{ row.external_shop_id }}
                      </div>
                    </td>
                    <td>{{ row.warehouse_name || '—' }}</td>
                    <td class="text-center">{{ row.listings }}</td>
                    <td class="text-center">
                      <b-badge v-if="row.waiting" variant="warning">{{ row.waiting }}</b-badge>
                      <span v-else class="text-muted">0</span>
                    </td>
                    <td>
                      <span v-if="row.orders_synced_at">{{ row.orders_synced_at }}</span>
                      <span v-else class="text-muted">{{ $t('Never') }}</span>
                    </td>
                    <td>
                      <b-badge :variant="row.is_active ? 'success' : 'secondary'">
                        {{ row.is_active ? $t('Active') : $t('Inactive') }}
                      </b-badge>
                      <!-- Configured and authorised are different things, and a
                           shop that has never been authorised syncs nothing
                           while looking perfectly set up. -->
                      <b-badge v-if="row.auth_error" variant="danger" class="ml-1">
                        {{ $t('ReconnectNeeded') }}
                      </b-badge>
                      <b-badge v-else-if="!row.authorised" variant="warning" class="ml-1">
                        {{ $t('NotAuthorised') }}
                      </b-badge>
                      <b-badge v-else variant="success" class="ml-1">
                        {{ $t('Connected') }}
                      </b-badge>
                      <div v-if="row.auth_error" class="text-danger small mt-1">{{ row.auth_error }}</div>
                    </td>
                    <td class="text-center text-nowrap">
                      <!-- Off to Shopee or Lazada to approve the app; they send
                           the browser back here when the seller is done. -->
                      <b-button size="sm" class="mr-1"
                                :variant="row.authorised && !row.auth_error ? 'outline-success' : 'success'"
                                :disabled="connecting === row.id" @click="Connect(row)">
                        <b-spinner small v-if="connecting === row.id"></b-spinner>
                        <span v-else>{{ row.authorised ? $t('Reconnect') : $t('Connect') }}</span>
                      </b-button>
                      <b-button size="sm" variant="outline-primary" class="mr-1"
                                @click="Open_Account(row)">
                        <i class="i-Edit"></i>
                      </b-button>
                      <b-button size="sm" variant="outline-secondary"
                                :disabled="syncing === row.id" @click="Sync_Now(row)">
                        <b-spinner small v-if="syncing === row.id"></b-spinner>
                        <i v-else class="i-Repeat-2"></i>
                      </b-button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </b-card>
        </b-tab>
      </b-tabs>
    </div>

    <!-- ------------------------------------------------ mapping modal -->
    <b-modal id="mapping_modal" :title="mapping.id ? $t('EditMapping') : $t('AddMapping')"
             hide-footer size="lg">
      <b-form @submit.prevent="Save_Mapping">
        <b-row>
          <b-col md="6">
            <b-form-group :label="$t('Shop')">
              <v-select
                v-model="mapping.marketplace_account_id"
                :reduce="label => label.value"
                :options="accountOptions"
                :disabled="!!mapping.id"
              />
            </b-form-group>
          </b-col>
          <b-col md="6">
            <b-form-group :label="$t('MarketplaceSku')">
              <b-form-input v-model="mapping.external_sku" :disabled="!!mapping.id" required/>
            </b-form-group>
          </b-col>
          <b-col md="6">
            <b-form-group :label="$t('Product')">
              <!-- Searched, not listed: a catalogue of any size is not a dropdown. -->
              <v-select
                v-model="mapping.product_id"
                :reduce="label => label.value"
                :options="productOptions"
                @search="Search_Products"
                :placeholder="$t('TypeToSearch')"
              />
            </b-form-group>
          </b-col>
          <b-col md="6">
            <b-form-group :label="$t('MarketplaceItemId')">
              <b-form-input v-model="mapping.external_item_id"/>
            </b-form-group>
          </b-col>
          <b-col md="12">
            <b-form-checkbox v-model="mapping.push_stock" class="mb-3">
              {{ $t('PushStockForThisListing') }}
            </b-form-checkbox>
          </b-col>
        </b-row>

        <div v-if="mapping_for_order" class="alert alert-info">
          {{ $t('WillRetryOrderAfterMapping') }} <strong>{{ mapping_for_order.external_order_id }}</strong>
        </div>

        <b-button type="submit" variant="primary" :disabled="saving">
          <b-spinner small v-if="saving"></b-spinner>
          {{ $t('Submit') }}
        </b-button>
      </b-form>
    </b-modal>

    <!-- ------------------------------------------------ account modal -->
    <b-modal id="account_modal" :title="account.id ? $t('EditShop') : $t('AddShop')" hide-footer>
      <b-form @submit.prevent="Save_Account">
        <b-form-group :label="$t('Channel')">
          <v-select
            v-model="account.channel"
            :reduce="label => label.value"
            :options="channels.map(c => ({label: c.label, value: c.value}))"
            :disabled="!!account.id"
          />
        </b-form-group>
        <b-form-group :label="$t('Name')">
          <b-form-input v-model="account.name" required/>
        </b-form-group>
        <b-form-group :label="$t('ShopId')">
          <b-form-input v-model="account.external_shop_id"/>
        </b-form-group>
        <b-form-group :label="$t('warehouse')">
          <v-select
            v-model="account.warehouse_id"
            :reduce="label => label.value"
            :options="warehouses.map(w => ({label: w.name, value: w.id}))"
          />
        </b-form-group>
        <b-form-group :label="$t('Customer')">
          <v-select
            v-model="account.client_id"
            :reduce="label => label.value"
            :options="clients.map(c => ({label: c.name, value: c.id}))"
          />
        </b-form-group>
        <b-form-checkbox v-if="account.id" v-model="account.is_active" class="mb-3">
          {{ $t('Active') }}
        </b-form-checkbox>

        <b-button type="submit" variant="primary" :disabled="saving">
          <b-spinner small v-if="saving"></b-spinner>
          {{ $t('Submit') }}
        </b-button>
      </b-form>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: {
    title: "Marketplace"
  },

  data() {
    return {
      isLoading: true,
      saving: false,
      retrying: null,
      syncing: null,
      connecting: null,
      tab: 0,

      accounts: [],
      waiting: [],
      warehouses: [],
      clients: [],
      channels: [],

      listings: [],
      filter_account: "",
      filter_search: "",

      orders: [],
      order_state: "",
      order_search: "",

      products: [],

      mapping: {},
      mapping_for_order: null,
      account: {}
    };
  },

  computed: {
    accountOptions() {
      return this.accounts.map(a => ({
        label: a.name + " (" + a.channel + ")",
        value: a.id
      }));
    },

    productOptions() {
      return this.products.map(p => ({
        label: p.name + " (" + p.code + ")",
        value: p.id
      }));
    },

    stateOptions() {
      return [
        { label: this.$t("Imported"), value: "imported" },
        { label: this.$t("Unmapped"), value: "unmapped" },
        { label: this.$t("Cancelled"), value: "cancelled" },
        { label: this.$t("Failed"), value: "failed" },
        { label: this.$t("Ignored"), value: "ignored" }
      ];
    }
  },

  methods: {
    money(value) {
      return Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    },

    channelVariant(channel) {
      if (channel === "shopee") return "danger";
      if (channel === "lazada") return "info";
      return "secondary";
    },

    stateVariant(state) {
      if (state === "imported") return "success";
      if (state === "unmapped") return "warning";
      if (state === "failed") return "danger";
      return "secondary";
    },

    toast(message, variant) {
      this.$root.$bvToast.toast(message, {
        title: variant === "danger" ? this.$t("Failed") : this.$t("Success"),
        variant: variant,
        solid: true
      });
    },

    // The server sends a message with a 422; anything else is a surprise and
    // should say so rather than showing a blank failure.
    complain(error) {
      // The app's axios interceptor rejects with the response body itself,
      // not an axios error, so the message is usually at the top level.
      const body = (error && error.response && error.response.data) || error || {};
      const message = (body && body.message) || this.$t("InvalidData");
      this.toast(message, "danger");
    },

    Get_Everything() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("marketplace")
        .then(response => {
          this.accounts = response.data.accounts;
          this.waiting = response.data.waiting;
          this.warehouses = response.data.warehouses;
          this.clients = response.data.clients;
          this.channels = response.data.channels;
          NProgress.done();
          this.isLoading = false;
          this.Get_Listings();
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    Get_Listings() {
      axios
        .get("marketplace/listings", {
          params: {
            account_id: this.filter_account || "",
            search: this.filter_search || ""
          }
        })
        .then(response => {
          this.listings = response.data.listings;
        });
    },

    // The orders tab is the only one that is not needed to open the screen, so
    // it is fetched when somebody actually looks at it.
    On_Tab(index) {
      if (index === 1) {
        this.Get_Orders();
      }
    },

    Get_Orders() {
      axios
        .get("marketplace/orders", {
          params: {
            state: this.order_state || "",
            search: this.order_search || ""
          }
        })
        .then(response => {
          this.orders = response.data.orders;
        });
    },

    Search_Products(search, loading) {
      if (loading) loading(true);
      axios
        .get("marketplace/products", { params: { search: search } })
        .then(response => {
          this.products = response.data.products;
          if (loading) loading(false);
        })
        .catch(() => {
          if (loading) loading(false);
        });
    },

    Open_Mapping(order, sku, row) {
      this.mapping_for_order = order || null;
      this.products = [];

      if (row) {
        this.mapping = {
          id: row.id,
          marketplace_account_id: row.marketplace_account_id,
          external_sku: row.external_sku,
          external_item_id: row.external_item_id,
          product_id: row.product_id,
          push_stock: !!row.push_stock
        };
        if (row.product_id && row.product_name) {
          this.products = [
            { id: row.product_id, name: row.product_name, code: row.product_code }
          ];
        }
      } else {
        this.mapping = {
          id: null,
          marketplace_account_id: order
            ? order.account_id
            : this.filter_account || (this.accounts[0] && this.accounts[0].id),
          external_sku: sku || "",
          external_item_id: "",
          product_id: null,
          push_stock: true
        };
      }

      this.$bvModal.show("mapping_modal");
    },

    Save_Mapping() {
      if (!this.mapping.product_id) {
        this.toast(this.$t("ChooseAProduct"), "danger");
        return;
      }

      this.saving = true;
      const request = this.mapping.id
        ? axios.put("marketplace/listings/" + this.mapping.id, {
            product_id: this.mapping.product_id,
            external_item_id: this.mapping.external_item_id,
            push_stock: !!this.mapping.push_stock
          })
        : axios.post("marketplace/listings", {
            marketplace_account_id: this.mapping.marketplace_account_id,
            product_id: this.mapping.product_id,
            external_sku: this.mapping.external_sku,
            external_item_id: this.mapping.external_item_id,
            push_stock: !!this.mapping.push_stock
          });

      request
        .then(() => {
          this.saving = false;
          this.$bvModal.hide("mapping_modal");
          this.toast(this.$t("Saved"), "success");

          // Mapping a SKU from the queue is only half of what the person
          // wanted; the order they were looking at goes through now, without
          // them having to find it again and press a second button.
          const order = this.mapping_for_order;
          this.mapping_for_order = null;

          if (order) {
            this.Retry(order);
          } else {
            this.Get_Everything();
          }
        })
        .catch(error => {
          this.saving = false;
          this.complain(error);
        });
    },

    Remove_Mapping(row) {
      this.$swal({
        title: this.$t("Are_you_sure"),
        text: this.$t("DeleteMappingWarning"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: this.$t("Yes_delete"),
        cancelButtonText: this.$t("Cancel")
      }).then(result => {
        if (!result.value) {
          return;
        }
        axios
          .delete("marketplace/listings/" + row.id)
          .then(() => {
            this.toast(this.$t("Successfully_Deleted"), "success");
            this.Get_Everything();
          })
          .catch(error => this.complain(error));
      });
    },

    Retry(order) {
      this.retrying = order.id;
      axios
        .post("marketplace/orders/" + order.id + "/retry")
        .then(response => {
          this.retrying = null;
          const state = response.data.state;

          if (state === "imported") {
            this.toast(this.$t("OrderPutThrough"), "success");
          } else {
            // Still held. Saying why beats a silent list that did not shrink.
            this.toast(
              response.data.reason || this.$t("StillWaiting"),
              "warning"
            );
          }

          this.Get_Everything();
        })
        .catch(error => {
          this.retrying = null;
          this.complain(error);
        });
    },

    Open_Account(row) {
      this.account = row
        ? {
            id: row.id,
            channel: row.channel,
            name: row.name,
            external_shop_id: row.external_shop_id,
            warehouse_id: row.warehouse_id,
            client_id: row.client_id,
            is_active: !!row.is_active
          }
        : {
            id: null,
            channel: "shopee",
            name: "",
            external_shop_id: "",
            warehouse_id: null,
            client_id: null,
            is_active: true
          };

      this.$bvModal.show("account_modal");
    },

    Save_Account() {
      this.saving = true;
      const request = this.account.id
        ? axios.put("marketplace/accounts/" + this.account.id, {
            name: this.account.name,
            external_shop_id: this.account.external_shop_id,
            warehouse_id: this.account.warehouse_id,
            client_id: this.account.client_id,
            is_active: !!this.account.is_active
          })
        : axios.post("marketplace/accounts", {
            channel: this.account.channel,
            name: this.account.name,
            external_shop_id: this.account.external_shop_id,
            warehouse_id: this.account.warehouse_id,
            client_id: this.account.client_id
          });

      request
        .then(() => {
          this.saving = false;
          this.$bvModal.hide("account_modal");
          this.toast(this.$t("Saved"), "success");
          this.Get_Everything();
        })
        .catch(error => {
          this.saving = false;
          this.complain(error);
        });
    },

    // Ask the server for the marketplace's approval page and go there. The
    // link is single use and short lived, so it is fetched on the click.
    Connect(row) {
      this.connecting = row.id;
      axios
        .post("marketplace/accounts/" + row.id + "/connect")
        .then(response => {
          window.location.assign(response.data.url);
        })
        .catch(error => {
          this.connecting = null;
          this.complain(error);
        });
    },

    // The marketplace has sent the browser back (see the web route
    // marketplace/callback). Say what happened, show the Shops tab, and take
    // the message out of the address so a refresh does not repeat it.
    Back_From_Marketplace() {
      const q = this.$route.query || {};
      if (!q.connected && !q.connect_error) {
        return;
      }
      if (q.connected) {
        this.toast(this.$t("ShopConnected"), "success");
      } else {
        this.toast(String(q.connect_error), "danger");
      }
      this.tab = 2;
      this.$router.replace({ path: this.$route.path }).catch(() => {});
    },

    Sync_Now(row) {
      this.syncing = row.id;
      axios
        .post("marketplace/accounts/" + row.id + "/sync")
        .then(response => {
          this.syncing = null;
          const orders = response.data.orders || {};
          const stock = response.data.stock || {};

          // A sync that could not reach the marketplace has to look different
          // from one that found nothing new.
          if (orders.error || stock.error) {
            this.toast(orders.error || stock.error, "danger");
          } else {
            this.toast(
              this.$t("SyncFinished", {
                imported: orders.imported || 0,
                unmapped: orders.unmapped || 0,
                pushed: stock.pushed || 0
              }),
              "success"
            );
          }

          this.Get_Everything();
        })
        .catch(error => {
          this.syncing = null;
          this.complain(error);
        });
    }
  },

  created() {
    this.Get_Everything();
    this.Back_From_Marketplace();
  }
};
</script>
