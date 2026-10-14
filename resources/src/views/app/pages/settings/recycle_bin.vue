<template>
  <div class="main-content">
    <breadcumb :page="$t('RecycleBin')" :folder="$t('Settings')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-alert variant="info" show class="small">{{ $t('RecycleBinIntro') }}</b-alert>

      <b-row>
        <b-col md="3">
          <b-card>
            <h6 class="mb-3">{{ $t('Types') }} <span class="badge badge-outline-primary">{{ total }}</span></h6>
            <div class="list-group">
              <a
                v-for="t in types"
                :key="t.table"
                class="list-group-item list-group-item-action d-flex justify-content-between align-items-center cursor-pointer"
                :class="{ active: current === t.table }"
                @click="Open(t)"
              >
                {{ t.label }}
                <span class="badge" :class="t.count ? 'badge-primary' : 'badge-light'">{{ t.count }}</span>
              </a>
            </div>
          </b-card>
        </b-col>

        <b-col md="9">
          <b-card>
            <div v-if="!current" class="text-center text-muted py-5">{{ $t('ChooseATypeToRestore') }}</div>

            <template v-else>
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">{{ currentLabel }}</h5>
                <b-form-input class="w-auto" v-model="search" @input="Load" :placeholder="$t('Search')"/>
              </div>

              <div v-if="!rows.length" class="text-center text-muted py-4">{{ $t('NothingDeletedHere') }}</div>

              <div v-else class="table-responsive">
                <table class="table table-hover">
                  <thead class="bg-gray-300">
                    <tr>
                      <th>{{ $t('Record') }}</th>
                      <th>{{ $t('DeletedOn') }}</th>
                      <th class="text-center">{{ $t('Action') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="r in rows" :key="r.id">
                      <td>
                        <strong>{{ r.title }}</strong>
                        <br v-if="r.subtitle">
                        <small class="text-muted" v-if="r.subtitle">{{ r.subtitle }}</small>
                      </td>
                      <td>{{ r.deleted_at }}</td>
                      <td class="text-center">
                        <b-button size="sm" variant="outline-success" class="mr-1"
                          :disabled="Processing" @click="Restore(r)">
                          {{ $t('Restore') }}
                        </b-button>
                        <!-- Deliberately separate from restore, and final. -->
                        <b-button size="sm" variant="outline-danger"
                          :disabled="Processing" @click="Purge(r)">
                          {{ $t('DeleteForever') }}
                        </b-button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </template>
          </b-card>

          <b-card class="mt-3">
            <h6 class="mb-3">{{ $t('RestoreLog') }}</h6>
            <div v-if="!log.length" class="text-muted small">{{ $t('NothingRestoredYet') }}</div>
            <div v-else class="table-responsive">
              <table class="table table-sm">
                <thead class="bg-gray-300">
                  <tr>
                    <th>{{ $t('Record') }}</th>
                    <th>{{ $t('Type') }}</th>
                    <th>{{ $t('User') }}</th>
                    <th>{{ $t('date') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="l in log" :key="l.id">
                    <td>{{ l.label || ('#' + l.record_id) }}</td>
                    <td><code>{{ l.model }}</code></td>
                    <td>{{ l.username || '—' }}</td>
                    <td>{{ l.created_at }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </b-card>
        </b-col>
      </b-row>
    </div>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Recycle Bin" },

  data() {
    return {
      isLoading: true,
      Processing: false,
      types: [],
      total: 0,
      current: null,
      currentLabel: "",
      rows: [],
      log: [],
      search: ""
    };
  },

  methods: {
    Get_Summary() {
      NProgress.start();
      NProgress.set(0.1);
      Promise.all([axios.get("recycle_bin/summary"), axios.get("recycle_bin/log")])
        .then(([s, l]) => {
          this.types = s.data.types;
          this.total = s.data.total;
          this.log = l.data.log;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    Open(t) {
      this.current = t.table;
      this.currentLabel = t.label;
      this.search = "";
      this.Load();
    },

    Load() {
      axios
        .get("recycle_bin", { params: { table: this.current, search: this.search } })
        .then(r => {
          this.rows = r.data.rows;
          this.currentLabel = r.data.label;
        })
        .catch(e => this.makeToast("danger", this.errorOf(e), this.$t("Failed")));
    },

    Restore(r) {
      this.Processing = true;
      axios
        .post("recycle_bin/restore", { table: this.current, id: r.id })
        .then(() => {
          this.Processing = false;
          this.Load();
          this.Get_Summary();
          this.makeToast("success", this.$t("Restored"), this.$t("Success"));
        })
        .catch(e => {
          this.Processing = false;
          // The server explains what is blocking the restore, e.g. a parent
          // that is itself still deleted. Show that, not a generic failure.
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    Purge(r) {
      this.$swal({
        title: this.$t("Are_you_sure"),
        text: this.$t("DeleteForeverBody"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        cancelButtonColor: "#3085d6",
        confirmButtonText: this.$t("DeleteForever")
      }).then(result => {
        if (!result.value) return;
        this.Processing = true;
        axios
          .post("recycle_bin/purge", { table: this.current, id: r.id })
          .then(() => {
            this.Processing = false;
            this.Load();
            this.Get_Summary();
            this.makeToast("success", this.$t("Successfully_Deleted"), this.$t("Success"));
          })
          .catch(e => {
            this.Processing = false;
            this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
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
    this.Get_Summary();
  }
};
</script>
