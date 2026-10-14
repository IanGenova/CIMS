<template>
  <div class="main-content">
    <breadcumb :page="$t('Deliveries')" :folder="$t('Sales')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-card>
        <b-row class="align-items-end mb-3">
          <b-col md="4">
            <b-form-input v-model="search" @input="Get_Deliveries" :placeholder="$t('SearchDeliveries')"/>
          </b-col>
          <b-col md="3">
            <v-select
              @input="Get_Deliveries"
              v-model="status"
              :reduce="label => label.value"
              :placeholder="$t('AllStatuses')"
              :options="statuses.map(s => ({label: label(s), value: s}))"
            />
          </b-col>
          <b-col md="5" class="text-right">
            <b-button variant="primary" size="sm" @click="Open_Create">
              <i class="i-Add"></i> {{ $t('NewDelivery') }}
            </b-button>
          </b-col>
        </b-row>

        <div v-if="!deliveries.length" class="text-center text-muted py-4">
          {{ $t('NoDeliveriesYet') }}
        </div>

        <div v-else class="table-responsive">
          <table class="table table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('Sale') }}</th>
                <th>{{ $t('CustomerName') }}</th>
                <th>{{ $t('date') }}</th>
                <th>{{ $t('Courier') }}</th>
                <th>{{ $t('TrackingNumber') }}</th>
                <th class="text-center">{{ $t('Status') }}</th>
                <th class="text-center">{{ $t('Action') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="d in deliveries" :key="d.id">
                <td><strong>{{ d.Ref }}</strong></td>
                <td>{{ d.sale_ref }}</td>
                <td>{{ d.client }}</td>
                <td>{{ d.date }}</td>
                <td>{{ d.courier || '—' }}</td>
                <td>{{ d.tracking_number || '—' }}</td>
                <td class="text-center">
                  <span class="badge" :class="badge(d.status)">{{ label(d.status) }}</span>
                  <br v-if="d.status === 'delivered' && d.received_by">
                  <small v-if="d.status === 'delivered' && d.received_by" class="text-muted">
                    {{ $t('ReceivedBy') }} {{ d.received_by }}
                  </small>
                  <br v-if="d.status === 'failed' && d.failure_reason">
                  <small v-if="d.status === 'failed' && d.failure_reason" class="text-danger">
                    {{ d.failure_reason }}
                  </small>
                </td>
                <td class="text-center">
                  <!-- Only transitions the state machine allows are offered. -->
                  <b-dropdown v-if="d.next_states.length" size="sm" variant="outline-primary"
                    :text="$t('Advance')" class="mr-1">
                    <b-dropdown-item v-for="s in d.next_states" :key="s" @click="Open_Transition(d, s)">
                      {{ label(s) }}
                    </b-dropdown-item>
                  </b-dropdown>
                  <span v-else class="text-muted small mr-1">{{ $t('Final') }}</span>
                  <a @click="Remove(d)" class="cursor-pointer" title="Delete">
                    <i class="i-Close-Window text-25 text-danger"></i>
                  </a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </b-card>
    </div>

    <!-- create against an undelivered sale -->
    <b-modal id="delivery_modal" :title="$t('NewDelivery')" hide-footer size="lg">
      <b-form-group :label="$t('Sale')">
        <v-select
          v-model="form.sale_id"
          :reduce="label => label.value"
          :placeholder="$t('ChooseASale')"
          :options="undelivered.map(s => ({
            label: s.Ref + ' — ' + (s.client || '') + ' — ' + s.date,
            value: s.id
          }))"
          @input="Fill_Address"
        />
        <small class="text-muted">{{ $t('OnlyCompletedUndelivered') }}</small>
      </b-form-group>

      <b-row>
        <b-col md="6">
          <b-form-group :label="$t('date')">
            <b-form-input type="date" v-model="form.date"/>
          </b-form-group>
        </b-col>
        <b-col md="6">
          <b-form-group :label="$t('Courier')">
            <b-form-input v-model="form.courier" :placeholder="$t('CourierPlaceholder')"/>
          </b-form-group>
        </b-col>
      </b-row>

      <b-form-group :label="$t('TrackingNumber')">
        <b-form-input v-model="form.tracking_number"/>
      </b-form-group>

      <b-form-group :label="$t('ShippingAddress')">
        <b-form-textarea rows="2" v-model="form.shipping_address"/>
      </b-form-group>

      <b-form-group :label="$t('Note')">
        <b-form-textarea rows="2" v-model="form.notes"/>
      </b-form-group>

      <b-button variant="primary" :disabled="Processing || !form.sale_id || !form.date" @click="Save">
        {{ $t('Submit') }}
      </b-button>
    </b-modal>

    <!-- advance state -->
    <b-modal id="transition_modal" :title="$t('UpdateStatus')" hide-footer>
      <div v-if="target">
        <p class="mb-3">
          <strong>{{ target.Ref }}</strong> →
          <span class="badge" :class="badge(nextStatus)">{{ label(nextStatus) }}</span>
        </p>

        <template v-if="nextStatus === 'dispatched'">
          <b-form-group :label="$t('Courier')">
            <b-form-input v-model="transitionForm.courier"/>
          </b-form-group>
          <b-form-group :label="$t('TrackingNumber')">
            <b-form-input v-model="transitionForm.tracking_number"/>
          </b-form-group>
        </template>

        <!-- Proof of delivery is the whole point of this state. -->
        <b-form-group v-if="nextStatus === 'delivered'" :label="$t('ReceivedBy')">
          <b-form-input v-model="transitionForm.received_by" :placeholder="$t('WhoSignedForIt')"/>
        </b-form-group>

        <b-form-group v-if="nextStatus === 'failed'" :label="$t('FailureReason')">
          <b-form-textarea rows="2" v-model="transitionForm.failure_reason"/>
        </b-form-group>

        <b-button variant="primary" :disabled="Processing || !canAdvance" @click="Do_Transition">
          {{ $t('Submit') }}
        </b-button>
      </div>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Deliveries" },

  data() {
    return {
      isLoading: true,
      Processing: false,
      deliveries: [],
      undelivered: [],
      statuses: [],
      search: "",
      status: "",
      target: null,
      nextStatus: "",
      form: {
        sale_id: null, date: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10),
        courier: "", tracking_number: "", shipping_address: "", notes: ""
      },
      transitionForm: { courier: "", tracking_number: "", received_by: "", failure_reason: "" }
    };
  },

  computed: {
    canAdvance() {
      if (this.nextStatus === "delivered") return !!this.transitionForm.received_by;
      if (this.nextStatus === "failed") return !!this.transitionForm.failure_reason;
      return true;
    }
  },

  methods: {
    label(s) {
      const map = {
        pending: this.$t("Pending"),
        dispatched: this.$t("Dispatched"),
        in_transit: this.$t("InTransit"),
        delivered: this.$t("Delivered"),
        failed: this.$t("DeliveryFailed"),
        returned: this.$t("Returned")
      };
      return map[s] || s;
    },

    badge(s) {
      const map = {
        pending: "badge-outline-secondary",
        dispatched: "badge-outline-primary",
        in_transit: "badge-outline-info",
        delivered: "badge-outline-success",
        failed: "badge-outline-danger",
        returned: "badge-outline-warning"
      };
      return map[s] || "badge-outline-secondary";
    },

    Get_Deliveries() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("deliveries", { params: { search: this.search, status: this.status, limit: 100, page: 1 } })
        .then(r => {
          this.deliveries = r.data.deliveries;
          this.statuses = r.data.statuses;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    Get_Undelivered() {
      axios.get("deliveries/sales/undelivered").then(r => {
        this.undelivered = r.data.sales;
      });
    },

    Open_Create() {
      this.Get_Undelivered();
      this.form = {
        sale_id: null, date: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10),
        courier: "", tracking_number: "", shipping_address: "", notes: ""
      };
      this.$bvModal.show("delivery_modal");
    },

    /** Pre-fill the address from the customer record so it is not retyped. */
    Fill_Address(saleId) {
      const s = this.undelivered.find(x => x.id === saleId);
      if (s && s.adresse && !this.form.shipping_address) {
        this.form.shipping_address = s.adresse;
      }
    },

    Save() {
      this.Processing = true;
      axios
        .post("deliveries", this.form)
        .then(() => {
          this.Processing = false;
          this.$bvModal.hide("delivery_modal");
          this.Get_Deliveries();
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    Open_Transition(d, next) {
      this.target = d;
      this.nextStatus = next;
      this.transitionForm = {
        courier: d.courier || "",
        tracking_number: d.tracking_number || "",
        received_by: "",
        failure_reason: ""
      };
      this.$bvModal.show("transition_modal");
    },

    Do_Transition() {
      this.Processing = true;
      axios
        .post("deliveries/" + this.target.id + "/transition",
          Object.assign({ status: this.nextStatus }, this.transitionForm))
        .then(() => {
          this.Processing = false;
          this.$bvModal.hide("transition_modal");
          this.Get_Deliveries();
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    Remove(d) {
      this.$swal({
        title: this.$t("Are_you_sure"),
        text: d.Ref,
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: this.$t("Yes_delete")
      }).then(result => {
        if (!result.value) return;
        axios
          .delete("deliveries/" + d.id)
          .then(() => {
            this.Get_Deliveries();
            this.makeToast("success", this.$t("Successfully_Deleted"), this.$t("Success"));
          })
          .catch(e => this.makeToast("danger", this.errorOf(e), this.$t("Failed")));
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
    this.Get_Deliveries();
  }
};
</script>
