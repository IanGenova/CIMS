<template>
  <div class="main-content">
    <breadcumb :page="$t('Webhooks')" :folder="$t('Settings')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-tabs card>
        <b-tab :title="$t('Endpoints')" active>
          <div class="d-flex justify-content-between align-items-center mb-3">
            <p class="text-muted mb-0">{{ $t('WebhookIntro') }}</p>
            <b-button variant="primary" size="sm" @click="Open_Create">
              <i class="i-Add"></i> {{ $t('Add') }}
            </b-button>
          </div>

          <b-alert variant="info" show class="small">{{ signatureHelp }}</b-alert>

          <div v-if="!webhooks.length" class="text-center text-muted py-4">{{ $t('NoWebhooksYet') }}</div>

          <div v-else class="table-responsive">
            <table class="table table-hover">
              <thead class="bg-gray-300">
                <tr>
                  <th>{{ $t('Name') }}</th>
                  <th>{{ $t('Endpoint') }}</th>
                  <th>{{ $t('Events') }}</th>
                  <th class="text-center">{{ $t('Queue') }}</th>
                  <th class="text-center">{{ $t('Status') }}</th>
                  <th class="text-center">{{ $t('Action') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="w in webhooks" :key="w.id">
                  <td>
                    <strong>{{ w.name }}</strong><br>
                    <small class="text-muted">{{ $t('Secret') }}: {{ w.secret_hint }}</small>
                  </td>
                  <td><code class="text-small">{{ w.url }}</code></td>
                  <td>
                    <span v-for="e in w.events" :key="e" class="badge badge-outline-primary mr-1">{{ e }}</span>
                  </td>
                  <td class="text-center">
                    <span v-if="w.pending" class="badge badge-outline-warning mr-1">{{ w.pending }}</span>
                    <span v-if="w.failed" class="badge badge-outline-danger">{{ w.failed }}</span>
                    <span v-if="!w.pending && !w.failed" class="text-muted">—</span>
                  </td>
                  <td class="text-center">
                    <span class="badge" :class="w.is_active ? 'badge-outline-success' : 'badge-outline-secondary'">
                      {{ w.is_active ? $t('Active') : $t('Inactive') }}
                    </span>
                    <br v-if="w.last_error">
                    <small v-if="w.last_error" class="text-danger">{{ w.last_error }}</small>
                  </td>
                  <td class="text-center">
                    <b-dropdown size="sm" variant="outline-primary" :text="$t('Manage')">
                      <b-dropdown-item @click="Test(w)">{{ $t('SendTest') }}</b-dropdown-item>
                      <b-dropdown-item @click="Open_Edit(w)">{{ $t('Edit') }}</b-dropdown-item>
                      <b-dropdown-item @click="Rotate(w)">{{ $t('RotateSecret') }}</b-dropdown-item>
                      <b-dropdown-item @click="Remove(w)">{{ $t('Delete.Title') }}</b-dropdown-item>
                    </b-dropdown>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </b-tab>

        <b-tab :title="$t('Deliveries')" @click="Get_Deliveries">
          <div class="d-flex justify-content-end mb-3">
            <b-button size="sm" variant="outline-primary" :disabled="Processing" @click="DeliverPending">
              {{ $t('DeliverPendingNow') }}
            </b-button>
          </div>

          <div v-if="!deliveries.length" class="text-center text-muted py-4">{{ $t('NoDeliveriesLogged') }}</div>

          <div v-else class="table-responsive">
            <table class="table table-sm table-hover">
              <thead class="bg-gray-300">
                <tr>
                  <th>{{ $t('Endpoint') }}</th>
                  <th>{{ $t('Event') }}</th>
                  <th class="text-center">{{ $t('Attempts') }}</th>
                  <th class="text-center">{{ $t('Response') }}</th>
                  <th class="text-center">{{ $t('Status') }}</th>
                  <th>{{ $t('date') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="d in deliveries" :key="d.id">
                  <td>{{ d.webhook }}</td>
                  <td><code>{{ d.event }}</code></td>
                  <td class="text-center">{{ d.attempts }}</td>
                  <td class="text-center">{{ d.response_status || '—' }}</td>
                  <td class="text-center">
                    <span class="badge" :class="statusBadge(d.status)">{{ d.status }}</span>
                  </td>
                  <td>{{ d.created_at }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </b-tab>
      </b-tabs>
    </div>

    <b-modal id="webhook_modal" :title="editing ? $t('EditWebhook') : $t('NewWebhook')" hide-footer size="lg">
      <b-form-group :label="$t('Name')">
        <b-form-input v-model="form.name" :placeholder="$t('WebhookNamePlaceholder')"/>
      </b-form-group>
      <b-form-group :label="$t('Endpoint')">
        <b-form-input v-model="form.url" placeholder="https://example.com/hooks/cims"/>
      </b-form-group>
      <b-form-group :label="$t('Events')">
        <v-select
          multiple
          v-model="form.events"
          :placeholder="$t('ChooseEvents')"
          :options="availableEvents"
        />
        <small class="text-muted">{{ $t('WildcardNote') }}</small>
      </b-form-group>
      <b-form-checkbox v-model="form.is_active" switch>{{ $t('Active') }}</b-form-checkbox>
      <b-button class="mt-3" variant="primary" :disabled="Processing || !canSave" @click="Save">
        {{ $t('Submit') }}
      </b-button>
    </b-modal>

    <!-- shown once, at creation or rotation -->
    <b-modal id="secret_modal" :title="$t('SigningSecret')" hide-footer no-close-on-backdrop>
      <b-alert variant="warning" show class="small">{{ $t('SecretShownOnce') }}</b-alert>
      <div class="p-3 bg-gray-100 rounded"><code>{{ newSecret }}</code></div>
      <b-button class="mt-3" variant="primary" @click="$bvModal.hide('secret_modal')">{{ $t('Done') }}</b-button>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Webhooks" },

  data() {
    return {
      isLoading: true,
      Processing: false,
      webhooks: [],
      deliveries: [],
      availableEvents: [],
      signatureHelp: "",
      editing: null,
      newSecret: "",
      form: { name: "", url: "", events: [], is_active: true }
    };
  },

  computed: {
    canSave() {
      return this.form.name && this.form.url && this.form.events.length;
    }
  },

  methods: {
    statusBadge(s) {
      const map = {
        pending: "badge-outline-warning",
        delivered: "badge-outline-success",
        failed: "badge-outline-danger"
      };
      return map[s] || "badge-outline-secondary";
    },

    Get_Data() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("webhooks")
        .then(r => {
          this.webhooks = r.data.webhooks;
          this.availableEvents = r.data.available_events;
          this.signatureHelp = r.data.signature_help;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    Get_Deliveries() {
      axios.get("webhook_deliveries").then(r => { this.deliveries = r.data.deliveries; });
    },

    Open_Create() {
      this.editing = null;
      this.form = { name: "", url: "", events: [], is_active: true };
      this.$bvModal.show("webhook_modal");
    },

    Open_Edit(w) {
      this.editing = w;
      this.form = { name: w.name, url: w.url, events: w.events.slice(), is_active: w.is_active };
      this.$bvModal.show("webhook_modal");
    },

    Save() {
      this.Processing = true;
      const req = this.editing
        ? axios.put("webhooks/" + this.editing.id, this.form)
        : axios.post("webhooks", this.form);
      req
        .then(r => {
          this.Processing = false;
          this.$bvModal.hide("webhook_modal");
          this.Get_Data();
          if (r.data.secret) {
            this.newSecret = r.data.secret;
            this.$bvModal.show("secret_modal");
          }
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    Rotate(w) {
      this.$swal({
        title: this.$t("Are_you_sure"),
        text: this.$t("RotateSecretBody"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: this.$t("RotateSecret")
      }).then(result => {
        if (!result.value) return;
        axios.post("webhooks/" + w.id + "/rotate", {}).then(r => {
          this.newSecret = r.data.secret;
          this.$bvModal.show("secret_modal");
          this.Get_Data();
        });
      });
    },

    Test(w) {
      this.Processing = true;
      axios
        .post("webhooks/" + w.id + "/test", {})
        .then(r => {
          this.Processing = false;
          this.Get_Data();
          const ok = r.data.status === "delivered";
          this.makeToast(
            ok ? "success" : "danger",
            ok
              ? this.$t("TestDelivered") + " (HTTP " + r.data.response_status + ")"
              : (r.data.response_body || this.$t("TestFailed")),
            ok ? this.$t("Success") : this.$t("Failed")
          );
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    DeliverPending() {
      this.Processing = true;
      axios
        .post("webhook_deliveries/deliver", {})
        .then(r => {
          this.Processing = false;
          this.Get_Deliveries();
          this.Get_Data();
          this.makeToast("success",
            this.$t("Sent") + ": " + r.data.sent + " · " + this.$t("Failed") + ": " + r.data.failed,
            this.$t("Success"));
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    Remove(w) {
      this.$swal({
        title: this.$t("Are_you_sure"),
        text: w.name,
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: this.$t("Yes_delete")
      }).then(result => {
        if (!result.value) return;
        axios.delete("webhooks/" + w.id).then(() => {
          this.Get_Data();
          this.makeToast("success", this.$t("Successfully_Deleted"), this.$t("Success"));
        });
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
  }
};
</script>
