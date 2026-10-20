<template>
  <!--
    A customer walks in with a unit: is it ours, who bought it, is it still
    under warranty? Scan or type its serial number (GET serials/lookup).
  -->
  <div class="main-content">
    <breadcumb :page="$t('SerialLookup')" :folder="$t('Products')"/>

    <b-card>
      <p class="text-muted">{{ $t('SerialLookupHelp') }}</p>
      <b-form @submit.prevent="Lookup">
        <b-input-group>
          <b-form-input
            ref="serial"
            v-model.trim="serial"
            :placeholder="$t('SerialScanOrSearch')"
            autofocus
          ></b-form-input>
          <b-input-group-append>
            <b-button type="submit" variant="primary" :disabled="!serial || loading">
              <i class="i-Magnifi-Glass1"></i> {{ $t('Search') }}
            </b-button>
          </b-input-group-append>
        </b-input-group>
      </b-form>

      <div v-if="loading" class="spinner sm spinner-primary mt-3"></div>
      <p v-else-if="searched && !results.length" class="mt-3 text-danger">{{ $t('SerialNotFound') }}</p>

      <div v-for="unit in results" :key="unit.id" class="mt-4 serial-unit">
        <h5 class="mb-1">{{ unit.serial_number }}</h5>
        <p class="mb-2">
          <strong>{{ unit.product }}</strong>
          <span class="text-muted">({{ unit.product_code }})</span>
        </p>
        <p v-if="unit.source" class="mb-2"><strong>QC:</strong> {{ unit.source.qc_status }}<span v-if="unit.source.qc_decider"> · {{ unit.source.qc_decider }} · {{ unit.source.qc_decided_at }}</span></p>

        <div class="d-flex flex-wrap serial-facts mb-3">
          <span class="badge" :class="statusClass(unit.status)">{{ serialStatus(unit.status) }}</span>
          <span v-if="unit.status === 'in_stock' && unit.warehouse">{{ unit.warehouse }}</span>
          <span v-if="unit.customer">{{ $t('Customer') }}: {{ unit.customer }}</span>
          <span v-if="unit.sale_ref">
            <router-link :to="'/app/sales/detail/' + unit.sale_id">{{ unit.sale_ref }}</router-link>
          </span>
          <span v-if="unit.received_at">{{ $t('ReceivedOn') }}: {{ day(unit.received_at) }}</span>
          <span v-if="unit.sold_at">{{ $t('SoldOn') }}: {{ day(unit.sold_at) }}</span>
          <span v-if="unit.warranty_until" :class="unit.under_warranty ? 'text-success' : 'text-danger'">
            {{ unit.under_warranty ? $t('UnderWarranty', { date: day(unit.warranty_until) }) : $t('WarrantyEnded', { date: day(unit.warranty_until) }) }}
          </span>
          <span v-else-if="unit.status === 'sold'" class="text-muted">{{ $t('NoWarranty') }}</span>
        </div>

        <p class="mb-2">
          <strong>Receipt source:</strong>
          <span v-if="unit.source">
            {{ unit.source.goods_receipt_ref }} · {{ unit.source.purchase_ref }} · {{ unit.source.supplier || 'Supplier unknown' }}
          </span>
          <span v-else class="text-muted">Unknown / historical</span>
        </p>

        <h6>{{ $t('SerialHistory') }}</h6>
        <div class="table-responsive">
          <table class="table table-sm">
            <tbody>
              <tr v-for="(e, i) in unit.history" :key="i">
                <td class="text-nowrap">{{ when(e.created_at) }}</td>
                <td>{{ eventLabel(e.event) }}</td>
                <td>{{ e.warehouse }}</td>
                <td class="text-muted">{{ e.reference_type }} #{{ e.reference_id }}</td>
                <td class="text-muted">{{ e.by }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </b-card>
  </div>
</template>

<script>
export default {
  metaInfo: {
    title: "Serial Number Lookup"
  },
  data() {
    return {
      serial: this.$route.query.serial || "",
      results: [],
      loading: false,
      searched: false
    };
  },
  methods: {
    Lookup() {
      if (!this.serial) {
        return;
      }
      this.loading = true;
      axios
        .get("serials/lookup", { params: { serial: this.serial } })
        .then(({ data }) => {
          this.results = data.serials || [];
        })
        .catch(() => {
          this.results = [];
        })
        .finally(() => {
          this.loading = false;
          this.searched = true;
        });
    },
    statusClass(status) {
      return {
        in_stock: "badge-success",
        qc_hold: "badge-warning",
        qc_rejected: "badge-danger",
        sold: "badge-primary",
        written_off: "badge-danger",
        returned_to_supplier: "badge-warning"
      }[status] || "badge-secondary";
    },
    serialStatus(status) {
      if (status === "qc_hold") return "Awaiting QC";
      if (status === "qc_rejected") return "Failed QC";
      return this.$t("SerialStatus_" + status);
    },
    eventLabel(event) {
      const text = String(event || "").replace(/_/g, " ");
      return text.charAt(0).toUpperCase() + text.slice(1);
    },
    day(value) {
      return value ? String(value).slice(0, 10) : "";
    },
    when(value) {
      return value ? String(value).slice(0, 16) : "";
    }
  },
  mounted() {
    if (this.serial) {
      this.Lookup();
    }
  }
};
</script>

<style scoped>
.serial-unit {
  border-top: 1px solid #eee;
  padding-top: 1rem;
}
.serial-facts {
  gap: 0.5rem 1.25rem;
  align-items: center;
}
</style>
