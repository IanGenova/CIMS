<template>
  <!--
    Which units a line moves, for a serial-tracked product (appliances,
    phones, power tools). A badge on the line shows how many are named; it
    opens a window to name them.

      mode "pick"  -- units leaving a shelf (sale, transfer, supplier return,
                      write-off): tick them, or scan one to tick it.
      mode "enter" -- units arriving (goods receipt, adjustment, customer
                      return): scan or type one per line.

    The check is utils/serials.js; the server repeats it (SerialService).
  -->
  <span class="serial-entry">
    <b-badge
      href="#"
      :variant="problem ? 'warning' : 'success'"
      class="serial-entry-badge"
      @click.prevent="open"
    >
      <i class="i-Bar-Code mr-1"></i>{{$t('SerialNumbers')}} {{ list.length }}/{{ need }}
    </b-badge>

    <b-modal
      :id="modalId"
      :title="name"
      size="md"
      centered
      ok-only
      :ok-title="$t('Done')"
      @shown="focusInput"
    >
      <p class="text-muted mb-2">
        {{ mode === 'pick' ? $t('SerialPickHelp', { count: need }) : $t('SerialEnterHelp', { count: need }) }}
      </p>

      <template v-if="mode === 'pick'">
        <b-form-input
          ref="scan"
          v-model="scan"
          size="sm"
          class="mb-2"
          :placeholder="$t('SerialScanOrSearch')"
          @keydown.enter.prevent="scanned"
        ></b-form-input>
        <div v-if="loading" class="text-center py-3"><div class="spinner sm spinner-primary"></div></div>
        <p v-else-if="!available.length" class="text-danger">{{$t('SerialNoneOnShelf')}}</p>
        <div v-else class="serial-entry-list">
          <b-form-checkbox
            v-for="s in shown"
            :key="s"
            :checked="isChosen(s)"
            @change="toggle(s)"
          >{{ s }}</b-form-checkbox>
        </div>
      </template>

      <b-form-textarea
        v-else
        ref="scan"
        :value="text"
        rows="6"
        :placeholder="'SN-0001\nSN-0002'"
        @input="typed"
      ></b-form-textarea>

      <p v-if="note" class="text-danger mt-2 mb-0">{{ note }}</p>
      <p class="mt-2 mb-0" :class="problem ? 'text-warning' : 'text-success'">
        {{ problem || $t('SerialAllNamed', { count: need }) }}
      </p>
    </b-modal>
  </span>
</template>

<script>
import { parseSerials, serialProblem } from "../utils/serials";

let uid = 0;

export default {
  name: "SerialEntry",
  props: {
    value: { type: Array, default: () => [] },
    mode: { type: String, default: "pick" },
    productId: { type: [Number, String], default: null },
    variantId: { type: [Number, String], default: null },
    warehouseId: { type: [Number, String], default: null },
    units: { type: [Number, String], default: 0 },
    name: { type: String, default: "" }
  },
  data() {
    uid += 1;
    return {
      modalId: "serial_entry_" + uid,
      available: [],
      loading: false,
      scan: "",
      note: "",
      text: (this.value || []).join("\n"),
      // Our own copy of the line's serials. The screens add `serials` to a
      // line Vue already watches, so it is not reactive there: the line gets
      // every change, but would never re-render this badge from it.
      chosen: parseSerials(this.value)
    };
  },
  watch: {
    // A new array from the screen (cart reset, line replaced) wins.
    value(v) {
      this.chosen = parseSerials(v);
    }
  },
  computed: {
    list() {
      return this.chosen;
    },
    need() {
      return Number(this.units) || 0;
    },
    problem() {
      return serialProblem(this.list, this.need, this.name);
    },
    shown() {
      const q = this.scan.trim().toUpperCase();
      return q ? this.available.filter(s => s.toUpperCase().includes(q)) : this.available;
    }
  },
  methods: {
    open() {
      this.note = "";
      this.text = this.list.join("\n");
      this.$bvModal.show(this.modalId);
      if (this.mode === "pick") {
        this.load();
      }
    },
    load() {
      if (!this.productId || !this.warehouseId) {
        this.available = [];
        this.note = this.$t("SerialChooseWarehouse");
        return;
      }
      this.loading = true;
      const variant = this.variantId ? "&product_variant_id=" + this.variantId : "";
      axios
        .get("serials/available?product_id=" + this.productId + "&warehouse_id=" + this.warehouseId + variant)
        .then(({ data }) => {
          this.available = data.serials || [];
          // A unit picked earlier but since sold elsewhere is dropped.
          const kept = this.list.filter(s => this.available.includes(s));
          if (kept.length !== this.list.length) {
            this.choose(kept);
          }
        })
        .catch(error => {
          this.available = [];
          this.note = (error.response && error.response.data && error.response.data.message) || this.$t("InvalidData");
        })
        .finally(() => (this.loading = false));
    },
    focusInput() {
      if (this.$refs.scan) {
        this.$refs.scan.focus();
      }
    },
    isChosen(s) {
      return this.list.includes(s);
    },
    toggle(s) {
      this.note = "";
      this.choose(this.isChosen(s) ? this.list.filter(x => x !== s) : this.list.concat([s]));
    },
    // A scanner types the serial and presses Enter.
    scanned() {
      const code = this.scan.trim();
      if (!code) {
        return;
      }
      const match = this.available.find(s => s.toUpperCase() === code.toUpperCase());
      if (!match) {
        this.note = this.$t("SerialNotOnShelf", { serial: code });
      } else if (!this.isChosen(match)) {
        this.note = "";
        this.choose(this.list.concat([match]));
      }
      this.scan = "";
    },
    typed(value) {
      this.text = value;
      this.choose(parseSerials(value));
    },
    choose(list) {
      this.chosen = list;
      this.$emit("input", list);
    }
  }
};
</script>

<style scoped>
.serial-entry-badge {
  cursor: pointer;
  font-size: 0.75rem;
}
.serial-entry-list {
  max-height: 260px;
  overflow-y: auto;
  border: 1px solid #eee;
  border-radius: 4px;
  padding: 6px 10px;
}
</style>
