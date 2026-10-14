<template>
  <div class="main-content">
    <breadcumb :page="$t('TaxGroups')" :folder="$t('Settings')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-card>
        <div class="d-flex justify-content-between align-items-center mb-3">
          <p class="text-muted mb-0">{{ $t('TaxGroupIntro') }}</p>
          <b-button variant="primary" size="sm" @click="Open_Create">
            <i class="i-Add"></i> {{ $t('Add') }}
          </b-button>
        </div>

        <div v-if="!groups.length" class="text-center text-muted py-4">
          {{ $t('NoTaxGroupsYet') }}
        </div>

        <div v-else class="table-responsive">
          <table class="table table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>{{ $t('Name') }}</th>
                <th>{{ $t('Components') }}</th>
                <th class="text-right">{{ $t('EffectiveRate') }}</th>
                <th class="text-center">{{ $t('Status') }}</th>
                <th class="text-center">{{ $t('Action') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="g in groups" :key="g.id">
                <td>
                  <strong>{{ g.name }}</strong>
                  <br v-if="g.description">
                  <small class="text-muted" v-if="g.description">{{ g.description }}</small>
                </td>
                <td>
                  <span v-for="(r, i) in g.rates" :key="r.id" class="badge badge-outline-primary mr-1">
                    {{ r.name }} {{ r.rate }}%<span v-if="r.is_compound"> ({{ $t('Compound') }})</span>
                  </span>
                </td>
                <!-- The one number a sale line is actually charged. -->
                <td class="text-right"><strong>{{ g.effective_percent }}%</strong></td>
                <td class="text-center">
                  <span class="badge" :class="g.is_active ? 'badge-outline-success' : 'badge-outline-secondary'">
                    {{ g.is_active ? $t('Active') : $t('Inactive') }}
                  </span>
                </td>
                <td class="text-center">
                  <a @click="Open_Edit(g)" class="cursor-pointer mr-2" title="Edit">
                    <i class="i-Pen-2 text-25 text-success"></i>
                  </a>
                  <a @click="Remove(g)" class="cursor-pointer" title="Delete">
                    <i class="i-Close-Window text-25 text-danger"></i>
                  </a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </b-card>
    </div>

    <b-modal id="tax_group_modal" :title="editing ? $t('EditTaxGroup') : $t('NewTaxGroup')" hide-footer size="lg">
      <b-form-group :label="$t('Name')">
        <b-form-input v-model="form.name" :placeholder="$t('TaxGroupNamePlaceholder')"/>
      </b-form-group>

      <b-form-group :label="$t('Description')">
        <b-form-input v-model="form.description"/>
      </b-form-group>

      <label class="mt-2">{{ $t('Components') }}</label>
      <p class="text-muted small">{{ $t('CompoundExplainer') }}</p>

      <table class="table table-sm">
        <thead>
          <tr>
            <th>{{ $t('Name') }}</th>
            <th style="width:130px">{{ $t('Rate') }} %</th>
            <th class="text-center" style="width:120px">{{ $t('Compound') }}</th>
            <th style="width:50px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(r, i) in form.rates" :key="i">
            <td><b-form-input size="sm" v-model="r.name" :placeholder="$t('ComponentNamePlaceholder')"/></td>
            <td><b-form-input size="sm" type="number" step="any" min="0" v-model.number="r.rate"/></td>
            <td class="text-center"><b-form-checkbox v-model="r.is_compound" switch class="mt-1"/></td>
            <td>
              <a @click="form.rates.splice(i, 1)" class="cursor-pointer" v-if="form.rates.length > 1">
                <i class="i-Close-Window text-20 text-danger"></i>
              </a>
            </td>
          </tr>
        </tbody>
      </table>

      <b-button size="sm" variant="outline-primary" @click="form.rates.push({name:'', rate:0, is_compound:false})">
        {{ $t('AddComponent') }}
      </b-button>

      <div class="mt-3 p-2 bg-gray-100 rounded">
        {{ $t('EffectiveRate') }}: <strong>{{ preview }}%</strong>
        <small class="text-muted d-block">{{ $t('EffectivePreviewNote') }}</small>
      </div>

      <b-form-checkbox v-model="form.is_active" switch class="mt-3">{{ $t('Active') }}</b-form-checkbox>

      <b-button class="mt-3" variant="primary" :disabled="Processing || !canSave" @click="Save">
        {{ $t('Submit') }}
      </b-button>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Tax Groups" },

  data() {
    return {
      isLoading: true,
      Processing: false,
      groups: [],
      editing: null,
      form: { name: "", description: "", is_active: true, rates: [{ name: "", rate: 0, is_compound: false }] }
    };
  },

  computed: {
    canSave() {
      return this.form.name && this.form.rates.length &&
        this.form.rates.every(r => r.name && r.rate !== "" && r.rate !== null);
    },

    /**
     * Mirrors the server's fold: simple components charge on the net, a
     * compound one charges on the net plus everything before it.
     */
    preview() {
      const base = 100;
      let running = base;
      let charged = 0;
      this.form.rates.forEach(r => {
        const on = r.is_compound ? running : base;
        const amount = (on * (Number(r.rate) || 0)) / 100;
        charged += amount;
        running += amount;
      });
      return Math.round(charged * 10000) / 10000;
    }
  },

  methods: {
    Get_Groups() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("tax_groups?page=1&limit=100&SortField=id&SortType=desc")
        .then(response => {
          this.groups = response.data.taxgroups;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    Open_Create() {
      this.editing = null;
      this.form = { name: "", description: "", is_active: true, rates: [{ name: "", rate: 0, is_compound: false }] };
      this.$bvModal.show("tax_group_modal");
    },

    Open_Edit(g) {
      this.editing = g;
      this.form = {
        name: g.name,
        description: g.description || "",
        is_active: g.is_active,
        rates: g.rates.map(r => ({ name: r.name, rate: r.rate, is_compound: r.is_compound }))
      };
      this.$bvModal.show("tax_group_modal");
    },

    Save() {
      this.Processing = true;
      const req = this.editing
        ? axios.put("tax_groups/" + this.editing.id, this.form)
        : axios.post("tax_groups", this.form);

      req
        .then(() => {
          this.Processing = false;
          this.$bvModal.hide("tax_group_modal");
          this.Get_Groups();
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        })
        .catch(error => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(error), this.$t("Failed"));
        });
    },

    Remove(g) {
      this.$swal({
        title: this.$t("Are_you_sure"),
        text: g.name,
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: this.$t("Yes_delete")
      }).then(result => {
        if (!result.value) return;
        axios
          .delete("tax_groups/" + g.id)
          .then(() => {
            this.Get_Groups();
            this.makeToast("success", this.$t("Successfully_Deleted"), this.$t("Success"));
          })
          .catch(error => {
            this.makeToast("danger", this.errorOf(error), this.$t("Failed"));
          });
      });
    },

    /** Surface the server's own message; it explains why a delete was refused. */
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
    this.Get_Groups();
  }
};
</script>
