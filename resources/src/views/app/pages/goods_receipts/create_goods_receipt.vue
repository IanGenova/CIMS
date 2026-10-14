<template>
  <div class="main-content">
    <breadcumb :page="$t('CreateGoodsReceipt')" :folder="$t('GoodsReceipts')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-card v-if="!isLoading">
      <b-row>
        <!-- Step 1: which order is being delivered -->
        <b-col md="6">
          <b-form-group :label="$t('PurchaseOrder')">
            <v-select
              @input="Load_Purchase"
              v-model="purchase_id"
              :reduce="label => label.value"
              :placeholder="$t('ChoosePurchaseToReceive')"
              :options="purchases.map(p => ({
                label: p.Ref + ' — ' + p.supplier + ' (' + p.outstanding_lines + ' ' + $t('LinesOutstanding') + ')',
                value: p.id
              }))"
            />
            <small v-if="!purchases.length" class="text-muted">
              {{ $t('NoPurchasesAwaitingReceipt') }}
            </small>
          </b-form-group>
        </b-col>

        <b-col md="3">
          <b-form-group :label="$t('date')">
            <b-form-input type="date" v-model="date"/>
          </b-form-group>
        </b-col>

        <b-col md="3">
          <b-form-group :label="$t('DeliveryNote')">
            <b-form-input v-model="delivery_note" :placeholder="$t('SupplierDeliveryNoteNumber')"/>
          </b-form-group>
        </b-col>
      </b-row>

      <!-- Approval is enforced server-side too; showing it here saves a round trip -->
      <b-alert
        variant="warning"
        show
        v-if="purchase && purchase.approval_status === 'pending'"
      >{{ $t('PurchaseAwaitingApproval') }}</b-alert>

      <b-alert
        variant="danger"
        show
        v-if="purchase && purchase.approval_status === 'rejected'"
      >{{ $t('PurchaseWasRejected') }}</b-alert>

      <!-- Step 2: what actually arrived -->
      <div v-if="details.length" class="mt-3">
        <h5>{{ $t('WhatArrived') }}</h5>
        <div class="table-responsive">
          <table class="table table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('ProductName') }}</th>
                <th class="text-right">{{ $t('Ordered') }}</th>
                <th class="text-right">{{ $t('Outstanding') }}</th>
                <th style="width:120px">{{ $t('Received') }}</th>
                <th style="width:120px">{{ $t('Rejected') }}</th>
                <th style="width:320px">{{ $t('BatchAndExpiry') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(line, i) in details" :key="line.purchase_detail_id">
                <td>
                  <span class="text-muted">{{ line.code }}</span><br>{{ line.name }}
                </td>
                <td class="text-right">{{ line.ordered_qte }}</td>
                <td class="text-right">{{ line.outstanding }}</td>
                <td>
                  <b-form-input
                    type="number"
                    min="0"
                    :max="line.outstanding"
                    step="any"
                    v-model.number="line.received_qte"
                  />
                </td>
                <td>
                  <b-form-input
                    type="number"
                    min="0"
                    step="any"
                    v-model.number="line.rejected_qte"
                  />
                </td>
                <td>
                  <!-- Only batch-tracked products need a lot; showing the
                       fields for everything else would just be noise. -->
                  <div v-if="line.has_batches">
                    <b-form-input
                      class="mb-1"
                      v-model="line.batch_number"
                      :placeholder="$t('BatchNumber') + ' *'"
                      :state="batchState(line)"
                    />
                    <b-form-input
                      type="date"
                      v-model="line.expiry_date"
                      :placeholder="$t('ExpiryDate')"
                    />
                  </div>
                  <serial-entry
                    v-else-if="line.has_serials"
                    v-model="line.serials"
                    mode="enter"
                    :units="(Number(line.received_qte) || 0) * (line.base_units_per || 1)"
                    :name="line.name"
                  ></serial-entry>
                  <span v-else class="text-muted">{{ $t('NotBatchTracked') }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <b-form-group :label="$t('Note')" class="mt-3">
          <b-form-textarea v-model="notes" rows="2"/>
        </b-form-group>

        <b-button
          variant="primary"
          :disabled="SubmitProcessing"
          @click="Submit"
        >{{ $t('Submit') }}</b-button>
        <div v-once class="typo__p" v-if="SubmitProcessing">
          <div class="spinner sm spinner-primary mt-3"></div>
        </div>
      </div>
    </b-card>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { firstSerialProblem } from "../../../../utils/serials";

export default {
  metaInfo: {
    title: "Create Goods Receipt"
  },

  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      purchases: [],
      purchase_id: "",
      purchase: null,
      details: [],
      date: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10),
      delivery_note: "",
      notes: ""
    };
  },

  methods: {
    /** Red only once someone has typed a quantity but left the lot blank. */
    batchState(line) {
      if (!line.has_batches) return null;
      if (!line.received_qte || line.received_qte <= 0) return null;
      return line.batch_number ? null : false;
    },

    Get_Awaiting() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("goods_receipts/awaiting")
        .then(response => {
          this.purchases = response.data.purchases;
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

    Load_Purchase(value) {
      if (!value) {
        this.details = [];
        this.purchase = null;
        return;
      }

      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("goods_receipts/create?purchase_id=" + value)
        .then(response => {
          this.purchase = response.data.purchase;
          this.details = response.data.details.map(d =>
            Object.assign({}, d, {
              // Default to receiving everything outstanding: the common case
              // is a full delivery, and short deliveries are edited down.
              received_qte: d.outstanding,
              rejected_qte: 0,
              batch_number: "",
              expiry_date: "",
              serials: []
            })
          );
          NProgress.done();
        })
        .catch(() => {
          NProgress.done();
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    Submit() {
      const lines = this.details.filter(
        l => (l.received_qte > 0 || l.rejected_qte > 0)
      );

      if (!lines.length) {
        this.makeToast("warning", this.$t("NothingToReceive"), this.$t("Warning"));
        return;
      }

      const missingBatch = lines.find(
        l => l.has_batches && l.received_qte > 0 && !l.batch_number
      );

      if (missingBatch) {
        this.makeToast(
          "warning",
          this.$t("BatchNumberRequiredFor") + " " + missingBatch.name,
          this.$t("Warning")
        );
        return;
      }

      // One serial per unit that arrived, counted in the base unit.
      const serialProblem = firstSerialProblem(
        lines.map(l => ({ name: l.name, has_serials: l.has_serials, serials: l.serials,
          quantity: (Number(l.received_qte) || 0) * (l.base_units_per || 1) }))
      );
      if (serialProblem) {
        this.makeToast("warning", serialProblem, this.$t("Warning"));
        return;
      }

      this.SubmitProcessing = true;
      NProgress.start();
      NProgress.set(0.1);

      axios
        .post("goods_receipts", {
          purchase_id: this.purchase_id,
          date: this.date,
          delivery_note: this.delivery_note,
          notes: this.notes,
          details: lines.map(l => ({
            purchase_detail_id: l.purchase_detail_id,
            received_qte: l.received_qte || 0,
            rejected_qte: l.rejected_qte || 0,
            batch_number: l.batch_number || null,
            expiry_date: l.expiry_date || null,
            serials: l.has_serials ? l.serials : undefined
          }))
        })
        .then(() => {
          NProgress.done();
          this.SubmitProcessing = false;
          this.$router.push("/app/goods_receipts/list");
          this.makeToast("success", this.$t("Successfully_Created"), this.$t("Success"));
        })
        .catch(error => {
          NProgress.done();
          this.SubmitProcessing = false;
          const data = error.response && error.response.data;
          // Surface the server's own reason - "only 8 outstanding", "needs a
          // batch number" - rather than a generic failure.
          const batchErrors = data && data.errors && (data.errors.batch || data.errors.serials || data.errors.details);
          const message = batchErrors && batchErrors.length
            ? batchErrors[0]
            : (data && data.message ? data.message : this.$t("InvalidData"));
          this.makeToast("danger", message, this.$t("Failed"));
        });
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title: title, variant: variant, solid: true });
    }
  },

  created() {
    this.Get_Awaiting();
  }
};
</script>
