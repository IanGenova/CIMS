<template>
  <div class="main-content">
    <breadcumb :page="$t('PurchaseApprovals')" :folder="$t('Purchases')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-alert variant="info" show v-if="threshold !== null">
        {{ $t('ApprovalThresholdIs') }}
        <strong>{{ currentUser.currency }} {{ money(threshold) }}</strong>.
        {{ $t('PurchasesAtOrAbove') }}
      </b-alert>

      <b-card v-if="!purchases.length">
        <p class="text-center text-muted mb-0 py-4">{{ $t('NothingAwaitingApproval') }}</p>
      </b-card>

      <b-card v-else>
        <div class="table-responsive">
          <table class="table table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('date') }}</th>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('Supplier') }}</th>
                <th>{{ $t('warehouse') }}</th>
                <th>{{ $t('RaisedBy') }}</th>
                <th class="text-right">{{ $t('GrandTotal') }}</th>
                <th class="text-center">{{ $t('Action') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in purchases" :key="p.id">
                <td>{{ p.date }}</td>
                <td>{{ p.Ref }}</td>
                <td>{{ p.supplier }}</td>
                <td>{{ p.warehouse }}</td>
                <td>
                  {{ p.raised_by }}
                  <!-- Approving your own purchase defeats the point of the
                       second signature, so it is called out. -->
                  <br v-if="p.raised_by_id === currentUser.id">
                  <small
                    v-if="p.raised_by_id === currentUser.id"
                    class="badge badge-outline-warning"
                  >{{ $t('YourOwnPurchase') }}</small>
                </td>
                <td class="text-right">{{ currentUser.currency }} {{ money(p.GrandTotal) }}</td>
                <td class="text-center">
                  <b-button
                    size="sm"
                    variant="success"
                    class="mr-1"
                    :disabled="Processing"
                    @click="Approve(p)"
                  >{{ $t('Approve') }}</b-button>
                  <b-button
                    size="sm"
                    variant="outline-danger"
                    :disabled="Processing"
                    @click="Open_Reject(p)"
                  >{{ $t('Reject') }}</b-button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </b-card>
    </div>

    <!-- A rejection has to say why: the buyer needs to know what to change. -->
    <b-modal id="reject_purchase" :title="$t('RejectPurchase')" hide-footer>
      <div v-if="selected">
        <p>{{ selected.Ref }} — {{ selected.supplier }} — {{ currentUser.currency }} {{ money(selected.GrandTotal) }}</p>
        <b-form-group :label="$t('ReasonForRejection')">
          <b-form-textarea v-model="reason" rows="3" :placeholder="$t('WhyIsThisRejected')"/>
        </b-form-group>
        <b-button
          variant="danger"
          :disabled="Processing || !reason.trim()"
          @click="Reject"
        >{{ $t('Reject') }}</b-button>
      </div>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";

export default {
  metaInfo: {
    title: "Purchase Approvals"
  },

  data() {
    return {
      isLoading: true,
      Processing: false,
      purchases: [],
      threshold: null,
      selected: null,
      reason: ""
    };
  },

  computed: {
    ...mapGetters(["currentUser"])
  },

  methods: {
    /** Money columns are DECIMAL(20,4); show them as currency. */
    money(value) {
      return Number(value || 0).toFixed(2);
    },

    Get_Pending() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("purchase_approvals/pending")
        .then(response => {
          this.purchases = response.data.purchases;
          this.threshold = response.data.threshold;
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

    Approve(row) {
      this.$swal({
        title: this.$t("Are_you_sure"),
        text: this.$t("ApprovingAllowsReceipt"),
        type: "question",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: this.$t("Approve")
      }).then(result => {
        if (!result.value) return;

        this.Processing = true;
        axios
          .post("purchase_approvals/" + row.id + "/approve", {})
          .then(() => {
            this.Processing = false;
            this.Get_Pending();
            this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
          })
          .catch(error => {
            this.Processing = false;
            const data = error.response && error.response.data;
            this.makeToast("danger", (data && data.message) || this.$t("InvalidData"), this.$t("Failed"));
          });
      });
    },

    Open_Reject(row) {
      this.selected = row;
      this.reason = "";
      this.$bvModal.show("reject_purchase");
    },

    Reject() {
      this.Processing = true;
      axios
        .post("purchase_approvals/" + this.selected.id + "/reject", { reason: this.reason })
        .then(() => {
          this.Processing = false;
          this.$bvModal.hide("reject_purchase");
          this.Get_Pending();
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        })
        .catch(error => {
          this.Processing = false;
          const data = error.response && error.response.data;
          this.makeToast("danger", (data && data.message) || this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    }
  },

  created() {
    this.Get_Pending();
  }
};
</script>
