<template>
  <!--
    Taking a payment through Xendit: the customer scans the code with GCash,
    Maya, a bank app or their phone camera and pays on Xendit's page. This
    window waits for the server to say the money arrived; nothing here records
    a payment by itself. Used by the POS, Create Sale and the Sales list.
  -->
  <b-modal
    id="xendit_checkout"
    ref="modal"
    :title="$t('XenditCheckoutTitle')"
    hide-footer
    no-close-on-backdrop
    centered
    size="md"
    @hidden="onHidden"
  >
    <div class="text-center xendit-checkout">
      <div v-if="state === 'starting'" class="py-5">
        <div class="spinner sm spinner-primary mt-3"></div>
        <p class="mt-3 text-muted">{{$t('XenditStarting')}}</p>
      </div>

      <div v-else-if="state === 'error'" class="py-4">
        <i class="i-Close-Window text-danger" style="font-size: 3rem"></i>
        <p class="mt-3">{{ error }}</p>
        <b-button variant="outline-secondary" @click="close">{{$t('Close')}}</b-button>
      </div>

      <template v-else-if="checkout">
        <p class="mb-1 text-muted" v-if="saleRef">{{ saleRef }}</p>
        <h3 class="mb-3 font-weight-bold">{{ money(checkout.amount) }}</h3>

        <div v-if="checkout.status === 'pending'">
          <!-- Drawn by the server from Xendit's own checkout link. -->
          <div class="xendit-qr mx-auto mb-2" v-html="checkout.qr_svg"></div>
          <p class="text-small">{{$t('XenditScanToPay')}}</p>

          <b-input-group size="sm" class="mb-2">
            <b-form-input readonly :value="checkout.payment_link_url"></b-form-input>
            <b-input-group-append>
              <b-button variant="outline-primary" @click="copyLink">{{$t('Copy')}}</b-button>
              <b-button variant="outline-primary" :href="checkout.payment_link_url" target="_blank" rel="noopener">{{$t('Open')}}</b-button>
            </b-input-group-append>
          </b-input-group>

          <p class="text-muted text-small mb-3">
            <span class="spinner-glow spinner-glow-primary mr-2" style="width:10px;height:10px;display:inline-block"></span>
            {{$t('XenditWaiting')}}
            <span v-if="checkout.expires_at"> · {{$t('XenditExpiresAt', { time: timeOf(checkout.expires_at) })}}</span>
          </p>

          <b-button variant="outline-danger" size="sm" :disabled="cancelling" @click="cancel">{{$t('XenditCancelCheckout')}}</b-button>
        </div>

        <div v-else-if="checkout.status === 'paid'" class="py-3">
          <i class="i-Yes text-success" style="font-size: 3.5rem"></i>
          <p class="mt-2 mb-1 font-weight-bold">{{$t('XenditPaid')}}</p>
          <p class="text-muted" v-if="checkout.channel">{{$t('XenditPaidWith', { channel: checkout.channel })}}</p>
          <b-button variant="success" @click="close">{{$t('Done')}}</b-button>
        </div>

        <div v-else class="py-3">
          <i class="i-Close-Window text-warning" style="font-size: 3rem"></i>
          <p class="mt-2">{{ checkout.status === 'expired' ? $t('XenditExpired') : $t('XenditCancelled') }}</p>
          <b-button variant="primary" class="mr-2" @click="retry">{{$t('XenditTryAgain')}}</b-button>
          <b-button variant="outline-secondary" @click="close">{{$t('Close')}}</b-button>
        </div>
      </template>
    </div>
  </b-modal>
</template>

<script>
import { startCheckout, watchCheckout } from "../utils/xenditCheckout";

export default {
  name: "XenditCheckout",

  data() {
    return {
      state: "idle",
      error: "",
      checkout: null,
      saleId: null,
      amount: 0,
      saleRef: "",
      stopWatching: null,
      cancelling: false,
    };
  },

  methods: {
    /** Ask for `amount` on this sale and show the code to scan. */
    open(saleId, amount, saleRef) {
      this.saleId = saleId;
      this.amount = amount;
      this.saleRef = saleRef || "";
      this.$refs.modal.show();
      this.start();
    },

    start() {
      this.stop();
      this.state = "starting";
      this.checkout = null;
      startCheckout(axios, this.saleId, this.amount)
        .then(checkout => {
          this.state = "ready";
          this.update(checkout);
        })
        .catch(error => {
          this.state = "error";
          this.error = (error && error.message) || this.$t("XenditCouldNotStart");
        });
    },

    update(checkout) {
      this.checkout = checkout;
      if (checkout.status === "pending" && !this.stopWatching) {
        this.stopWatching = watchCheckout(axios, checkout.id, c => this.update(c), { every: 3000 });
      }
      if (checkout.status === "paid") {
        this.stop();
        this.$emit("paid", checkout);
      }
    },

    cancel() {
      this.cancelling = true;
      axios
        .post("xendit/checkouts/" + this.checkout.id + "/cancel", {})
        .then(r => {
          this.cancelling = false;
          // It may have been paid at the very moment it was cancelled; the
          // server's answer is the one that counts.
          this.update(r.data.checkout);
        })
        .catch(error => {
          this.cancelling = false;
          this.$bvToast.toast((error && error.message) || this.$t("Failed"), {
            title: "Xendit", variant: "danger", solid: true,
          });
        });
    },

    retry() {
      this.start();
    },

    close() {
      this.$refs.modal.hide();
    },

    onHidden() {
      this.stop();
      this.$emit("closed", this.checkout);
    },

    stop() {
      if (this.stopWatching) {
        this.stopWatching();
        this.stopWatching = null;
      }
    },

    copyLink() {
      try {
        navigator.clipboard.writeText(this.checkout.payment_link_url).then(() => {
          this.$bvToast.toast(this.$t("Copied"), { title: "Xendit", variant: "success", solid: true, autoHideDelay: 2000 });
        }, () => {});
      } catch (e) {
        // The link is in a field the cashier can select by hand.
      }
    },

    money(v) {
      return "₱" + Number(v || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },

    timeOf(iso) {
      const d = new Date(iso);
      return isNaN(d) ? "" : d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
    },
  },

  beforeDestroy() {
    this.stop();
  },
};
</script>

<style scoped>
.xendit-qr {
  width: 220px;
  max-width: 100%;
}
.xendit-qr >>> svg {
  width: 100%;
  height: auto;
  display: block;
}
</style>
