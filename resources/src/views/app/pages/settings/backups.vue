<template>
  <div class="main-content">
    <breadcumb :page="$t('Backups')" :folder="$t('Settings')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-card class="mb-3">
        <b-row class="align-items-end">
          <b-col md="8">
            <h5 class="mb-1">{{ $t('ScheduledBackups') }}</h5>
            <p class="text-muted small mb-0">{{ schedulerHint }}</p>
          </b-col>
          <b-col md="4" class="text-right">
            <b-button variant="primary" size="sm" :disabled="Processing" @click="RunNow">
              <i class="i-Data-Backup"></i> {{ $t('BackUpNow') }}
            </b-button>
          </b-col>
        </b-row>

        <hr>

        <b-row v-if="settings">
          <b-col md="3">
            <b-form-checkbox v-model="settings.scheduled_enabled" switch>
              {{ $t('EnableSchedule') }}
            </b-form-checkbox>
          </b-col>
          <b-col md="3">
            <b-form-group :label="$t('Frequency')" class="mb-2">
              <v-select
                v-model="settings.frequency"
                :reduce="label => label.value"
                :options="[
                  {label: $t('Hourly'), value: 'hourly'},
                  {label: $t('Daily'), value: 'daily'},
                  {label: $t('Weekly'), value: 'weekly'}
                ]"
              />
            </b-form-group>
          </b-col>
          <b-col md="3">
            <b-form-group :label="$t('KeepHowMany')" class="mb-2">
              <b-form-input type="number" min="1" max="365" v-model.number="settings.retention_count"/>
            </b-form-group>
          </b-col>
          <b-col md="3">
            <b-form-checkbox v-model="settings.verify_after_backup" switch>
              {{ $t('VerifyAfterBackup') }}
            </b-form-checkbox>
          </b-col>
        </b-row>

        <b-row v-if="settings" class="mt-2">
          <b-col md="3">
            <b-form-checkbox v-model="settings.offsite_enabled" switch>
              {{ $t('CopyOffsite') }}
            </b-form-checkbox>
          </b-col>
          <b-col md="4">
            <b-form-group :label="$t('OffsiteDisk')" class="mb-2">
              <v-select
                v-model="settings.offsite_disk"
                :reduce="label => label.value"
                :placeholder="$t('Choose')"
                :options="disks.map(d => ({label: d, value: d}))"
                :disabled="!settings.offsite_enabled"
              />
              <small class="text-muted">{{ $t('OffsiteDiskNote') }}</small>
            </b-form-group>
          </b-col>
          <b-col md="5" class="text-right">
            <b-button variant="primary" size="sm" :disabled="Processing" @click="SaveSettings">
              {{ $t('Submit') }}
            </b-button>
          </b-col>
        </b-row>
      </b-card>

      <b-card>
        <h5 class="mb-3">{{ $t('BackupHistory') }}</h5>

        <div v-if="!backups.length" class="text-center text-muted py-4">{{ $t('NoBackupsYet') }}</div>

        <div v-else class="table-responsive">
          <table class="table table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('File') }}</th>
                <th>{{ $t('Taken') }}</th>
                <th class="text-right">{{ $t('Size') }}</th>
                <th class="text-center">{{ $t('Trigger') }}</th>
                <th class="text-center">{{ $t('Verified') }}</th>
                <th class="text-center">{{ $t('Offsite') }}</th>
                <th class="text-center">{{ $t('Action') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="b in backups" :key="b.id">
                <td>
                  <code>{{ b.filename }}</code>
                  <br v-if="b.error">
                  <small v-if="b.error" class="text-danger">{{ b.error }}</small>
                </td>
                <td>{{ b.created_at }}</td>
                <td class="text-right">{{ b.size_human }}</td>
                <td class="text-center">
                  <span class="badge badge-outline-secondary">{{ b.trigger }}</span>
                </td>
                <td class="text-center">
                  <!-- A backup nobody has checked is a hope, not a backup. -->
                  <i v-if="b.verified" class="i-Check text-20 text-success"></i>
                  <span v-else class="badge badge-outline-warning">{{ $t('Unverified') }}</span>
                </td>
                <td class="text-center">
                  <i v-if="b.offsite" class="i-Check text-20 text-success"></i>
                  <span v-else class="text-muted">—</span>
                </td>
                <td class="text-center">
                  <a @click="Verify(b)" class="cursor-pointer mr-2" :title="$t('Verify')">
                    <i class="i-Shield-Check text-25 text-primary"></i>
                  </a>
                  <a v-if="b.exists" @click="Download(b)" class="cursor-pointer mr-2" :title="$t('Download')">
                    <i class="i-Download text-25 text-success"></i>
                  </a>
                  <a @click="Remove(b)" class="cursor-pointer" title="Delete">
                    <i class="i-Close-Window text-25 text-danger"></i>
                  </a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </b-card>
    </div>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Backups" },

  data() {
    return {
      isLoading: true,
      Processing: false,
      backups: [],
      settings: null,
      disks: [],
      schedulerHint: ""
    };
  },

  methods: {
    Get_Data() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("backups")
        .then(r => {
          this.backups = r.data.backups;
          this.settings = r.data.settings;
          this.disks = r.data.available_disks;
          this.schedulerHint = r.data.scheduler_hint;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    RunNow() {
      this.Processing = true;
      axios
        .post("backups/run", {})
        .then(r => {
          this.Processing = false;
          this.Get_Data();
          this.makeToast("success", r.data.filename + " — " + r.data.size_human, this.$t("Success"));
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    Verify(b) {
      axios
        .post("backups/" + b.id + "/verify", {})
        .then(r => {
          this.Get_Data();
          this.makeToast(
            r.data.verified ? "success" : "danger",
            r.data.verified ? this.$t("BackupIntact") : (r.data.error || this.$t("BackupFailedVerification")),
            r.data.verified ? this.$t("Success") : this.$t("Failed")
          );
        })
        .catch(e => this.makeToast("danger", this.errorOf(e), this.$t("Failed")));
    },

    Download(b) {
      axios
        .get("backups/" + b.id + "/download", { responseType: "blob" })
        .then(r => {
          const url = window.URL.createObjectURL(new Blob([r.data]));
          const link = document.createElement("a");
          link.href = url;
          link.setAttribute("download", b.filename);
          document.body.appendChild(link);
          link.click();
          link.remove();
          window.URL.revokeObjectURL(url);
        })
        .catch(e => this.makeToast("danger", this.errorOf(e), this.$t("Failed")));
    },

    Remove(b) {
      this.$swal({
        title: this.$t("Are_you_sure"),
        text: b.filename,
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: this.$t("Yes_delete")
      }).then(result => {
        if (!result.value) return;
        axios.delete("backups/" + b.id).then(() => {
          this.Get_Data();
          this.makeToast("success", this.$t("Successfully_Deleted"), this.$t("Success"));
        });
      });
    },

    SaveSettings() {
      this.Processing = true;
      axios
        .put("backups/settings", this.settings)
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
