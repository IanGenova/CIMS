<template>
  <div class="main-content">
    <breadcumb :page="$t('Tenants')" :folder="$t('Settings')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-alert :variant="enabled ? 'success' : 'info'" show>
        <strong>{{ enabled ? $t('MultiTenancyOn') : $t('MultiTenancyOff') }}</strong> — {{ hint }}
      </b-alert>

      <b-card class="mb-3">
        <h6 class="mb-2">{{ $t('HowTenancyWorks') }}</h6>
        <p class="text-muted small mb-2">{{ $t('TenancyExplainer') }}</p>
        <p class="text-muted small mb-0">
          {{ $t('LandlordDatabase') }}: <code>{{ landlord }}</code>
        </p>
      </b-card>

      <b-card>
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="mb-0">{{ $t('Tenants') }}</h5>
          <b-button variant="outline-primary" size="sm" @click="$bvModal.show('provision_help')">
            {{ $t('AddTenant') }}
          </b-button>
        </div>

        <div v-if="!tenants.length" class="text-center text-muted py-4">{{ $t('NoTenantsYet') }}</div>

        <div v-else class="table-responsive">
          <table class="table table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('Name') }}</th>
                <th>{{ $t('Slug') }}</th>
                <th>{{ $t('Database') }}</th>
                <th>{{ $t('Domain') }}</th>
                <th class="text-center">{{ $t('Status') }}</th>
                <th class="text-center">{{ $t('Action') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="t in tenants" :key="t.id">
                <td>
                  <strong>{{ t.name }}</strong>
                  <br v-if="t.contact_email">
                  <small class="text-muted" v-if="t.contact_email">{{ t.contact_email }}</small>
                </td>
                <td><code>{{ t.slug }}</code></td>
                <td><code class="text-small">{{ t.database }}</code></td>
                <td>{{ t.domain || '—' }}</td>
                <td class="text-center">
                  <span class="badge" :class="badge(t.status)">{{ t.status }}</span>
                  <br v-if="t.status === 'failed' && t.notes">
                  <small v-if="t.status === 'failed' && t.notes" class="text-danger">{{ t.notes }}</small>
                </td>
                <td class="text-center">
                  <b-button v-if="t.status === 'active'" size="sm" variant="outline-danger"
                    :disabled="Processing" @click="SetStatus(t, 'suspend')">
                    {{ $t('Suspend') }}
                  </b-button>
                  <b-button v-else-if="t.status === 'suspended'" size="sm" variant="outline-success"
                    :disabled="Processing" @click="SetStatus(t, 'activate')">
                    {{ $t('Activate') }}
                  </b-button>
                  <span v-else class="text-muted small">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </b-card>
    </div>

    <!-- Provisioning is a command-line job, and the screen says why. -->
    <b-modal id="provision_help" :title="$t('AddTenant')" hide-footer size="lg">
      <p>{{ $t('ProvisionExplainer') }}</p>
      <pre class="p-3 bg-gray-100 rounded"><code>php artisan tenant:manage create --name="Acme Trading" --slug=acme --email=admin@acme.com</code></pre>
      <p class="text-muted small">{{ $t('ProvisionSteps') }}</p>
      <pre class="p-3 bg-gray-100 rounded"><code>php artisan tenant:manage list
php artisan tenant:manage migrate
php artisan tenant:manage suspend --tenant=acme</code></pre>
      <b-button variant="primary" @click="$bvModal.hide('provision_help')">{{ $t('Done') }}</b-button>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Tenants" },

  data() {
    return {
      isLoading: true,
      Processing: false,
      tenants: [],
      enabled: false,
      hint: "",
      landlord: ""
    };
  },

  methods: {
    badge(s) {
      const map = {
        active: "badge-outline-success",
        suspended: "badge-outline-warning",
        provisioning: "badge-outline-primary",
        failed: "badge-outline-danger"
      };
      return map[s] || "badge-outline-secondary";
    },

    Get_Data() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("tenants")
        .then(r => {
          this.tenants = r.data.tenants;
          this.enabled = r.data.enabled;
          this.hint = r.data.hint;
          this.landlord = r.data.landlord_database;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    SetStatus(t, action) {
      this.Processing = true;
      axios
        .post("tenants/" + t.id + "/" + action, {})
        .then(() => {
          this.Processing = false;
          this.Get_Data();
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
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
    this.Get_Data();
  }
};
</script>
