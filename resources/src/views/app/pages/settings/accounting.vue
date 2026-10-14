<template>
  <div class="main-content">
    <breadcumb :page="$t('Accounting')" :folder="$t('Settings')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-tabs card>
        <!-- Chart of accounts -->
        <b-tab :title="$t('ChartOfAccounts')" active>
          <div class="d-flex justify-content-between align-items-center mb-3">
            <p class="text-muted mb-0">{{ $t('ChartIntro') }}</p>
            <b-button variant="primary" size="sm" @click="Open_Create_Account">
              <i class="i-Add"></i> {{ $t('Add') }}
            </b-button>
          </div>

          <div class="table-responsive">
            <table class="table table-hover">
              <thead class="bg-gray-300">
                <tr>
                  <th>{{ $t('AccountCode') }}</th>
                  <th>{{ $t('AccountName') }}</th>
                  <th>{{ $t('AccountType') }}</th>
                  <th>{{ $t('NormalBalance') }}</th>
                  <th class="text-right">{{ $t('Balance') }}</th>
                  <th class="text-center">{{ $t('Action') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="a in accounts" :key="a.id">
                  <td><code>{{ a.code }}</code></td>
                  <td>
                    {{ a.name }}
                    <span v-if="a.is_system" class="badge badge-outline-secondary ml-1">{{ $t('System') }}</span>
                    <span v-if="!a.is_active" class="badge badge-outline-warning ml-1">{{ $t('Inactive') }}</span>
                  </td>
                  <td>{{ $t(typeKey(a.type)) }}</td>
                  <td>{{ a.normal_balance === 'debit' ? $t('Debit') : $t('Credit') }}</td>
                  <td class="text-right"><strong>{{ money(a.balance) }}</strong></td>
                  <td class="text-center">
                    <a @click="Open_Edit_Account(a)" class="cursor-pointer mr-2" title="Edit">
                      <i class="i-Pen-2 text-25 text-success"></i>
                    </a>
                    <a v-if="!a.is_system" @click="Remove_Account(a)" class="cursor-pointer" title="Delete">
                      <i class="i-Close-Window text-25 text-danger"></i>
                    </a>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </b-tab>

        <!-- Journal -->
        <b-tab :title="$t('Journal')" @click="Get_Journal">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
              <p class="text-muted mb-1">{{ $t('JournalIntro') }}</p>
              <!-- Posting looks after itself; what is worth showing is whether
                   anything is stuck, not whether somebody pressed the button. -->
              <p class="mb-0 small">
                <span v-if="!autoPost" class="post-off">{{ $t('AutoPostOff') }}</span>
                <span v-else-if="pending.total > 0" class="post-waiting">
                  {{ $t('DocumentsWaiting', { n: pending.total }) }}
                </span>
                <span v-else class="post-current">{{ $t('BooksUpToDate') }}</span>
                <span class="text-muted ml-2" v-if="lastPostedAt">
                  {{ $t('LastPosted') }}: {{ lastPostedAt }}
                </span>
              </p>
            </div>
            <div>
              <label class="switch switch-primary mr-3" :title="$t('AutoPostHint')">
                {{ $t('AutoPostLedger') }}
                <input type="checkbox" v-model="autoPost" @change="Set_AutoPost">
                <span class="slider"></span>
              </label>
              <b-button variant="outline-primary" size="sm" class="mr-1"
                :disabled="Processing" @click="Post_Pending">
                {{ $t('PostPending') }}
              </b-button>
              <b-button variant="primary" size="sm" @click="Open_Entry">
                <i class="i-Add"></i> {{ $t('ManualEntry') }}
              </b-button>
            </div>
          </div>

          <div v-if="!entries.length" class="text-center text-muted py-4">{{ $t('NoEntriesYet') }}</div>

          <div v-for="e in entries" :key="e.id" class="mb-3 p-3 border rounded">
            <div class="d-flex justify-content-between">
              <div>
                <strong>{{ e.Ref }}</strong>
                <span class="text-muted ml-2">{{ e.date }}</span>
                <span v-if="e.source_type" class="badge badge-outline-secondary ml-2">{{ e.source_type }}</span>
                <span v-if="e.is_reversal" class="badge badge-outline-warning ml-1">{{ $t('Reversal') }}</span>
                <span v-if="e.is_reversed" class="badge badge-outline-danger ml-1">{{ $t('Reversed') }}</span>
                <div class="text-muted small">{{ e.memo }}</div>
              </div>
              <div>
                <!-- History is corrected by a reversal, never by an edit. -->
                <b-button v-if="!e.is_reversed && !e.is_reversal" size="sm" variant="outline-danger"
                  :disabled="Processing" @click="Reverse(e)">
                  {{ $t('Reverse') }}
                </b-button>
              </div>
            </div>

            <table class="table table-sm mt-2 mb-0">
              <thead>
                <tr>
                  <th>{{ $t('AccountName') }}</th>
                  <th class="text-right">{{ $t('Debit') }}</th>
                  <th class="text-right">{{ $t('Credit') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(l, i) in e.lines" :key="i">
                  <td><code>{{ l.code }}</code> {{ l.account }}</td>
                  <td class="text-right">{{ l.debit ? money(l.debit) : '' }}</td>
                  <td class="text-right">{{ l.credit ? money(l.credit) : '' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </b-tab>
      </b-tabs>
    </div>

    <!-- account -->
    <b-modal id="account_modal" :title="editingAccount ? $t('EditAccount') : $t('NewAccount')" hide-footer>
      <b-row>
        <b-col md="4">
          <b-form-group :label="$t('AccountCode')">
            <b-form-input v-model="accountForm.code"/>
          </b-form-group>
        </b-col>
        <b-col md="8">
          <b-form-group :label="$t('AccountName')">
            <b-form-input v-model="accountForm.name"/>
          </b-form-group>
        </b-col>
      </b-row>
      <b-form-group :label="$t('AccountType')">
        <v-select
          v-model="accountForm.type"
          :reduce="label => label.value"
          :options="types.map(t => ({label: $t(typeKey(t)), value: t}))"
          @input="Default_Balance"
        />
      </b-form-group>
      <b-form-group :label="$t('NormalBalance')">
        <v-select
          v-model="accountForm.normal_balance"
          :reduce="label => label.value"
          :options="[{label: $t('Debit'), value: 'debit'}, {label: $t('Credit'), value: 'credit'}]"
        />
      </b-form-group>
      <b-form-checkbox v-model="accountForm.is_active" switch>{{ $t('Active') }}</b-form-checkbox>
      <b-button class="mt-3" variant="primary"
        :disabled="Processing || !accountForm.code || !accountForm.name || !accountForm.type"
        @click="Save_Account">{{ $t('Submit') }}</b-button>
    </b-modal>

    <!-- manual entry -->
    <b-modal id="entry_modal" :title="$t('ManualEntry')" hide-footer size="lg">
      <b-row>
        <b-col md="4">
          <b-form-group :label="$t('date')">
            <b-form-input type="date" v-model="entryForm.date"/>
          </b-form-group>
        </b-col>
        <b-col md="8">
          <b-form-group :label="$t('Memo')">
            <b-form-input v-model="entryForm.memo"/>
          </b-form-group>
        </b-col>
      </b-row>

      <table class="table table-sm">
        <thead>
          <tr>
            <th>{{ $t('AccountName') }}</th>
            <th style="width:140px">{{ $t('Debit') }}</th>
            <th style="width:140px">{{ $t('Credit') }}</th>
            <th style="width:40px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(l, i) in entryForm.lines" :key="i">
            <td>
              <v-select
                v-model="l.account_id"
                :reduce="label => label.value"
                :placeholder="$t('Choose')"
                :options="accounts.map(a => ({label: a.code + ' — ' + a.name, value: a.id}))"
              />
            </td>
            <td><b-form-input size="sm" type="number" step="any" min="0" v-model.number="l.debit"/></td>
            <td><b-form-input size="sm" type="number" step="any" min="0" v-model.number="l.credit"/></td>
            <td>
              <a v-if="entryForm.lines.length > 2" @click="entryForm.lines.splice(i, 1)" class="cursor-pointer">
                <i class="i-Close-Window text-20 text-danger"></i>
              </a>
            </td>
          </tr>
        </tbody>
      </table>

      <b-button size="sm" variant="outline-primary"
        @click="entryForm.lines.push({account_id: null, debit: 0, credit: 0})">
        {{ $t('AddLine') }}
      </b-button>

      <!-- An unbalanced entry is refused by the server; say so before they try. -->
      <div class="mt-3 p-2 rounded" :class="balanced ? 'bg-gray-100' : 'bg-warning-soft'">
        {{ $t('Debit') }}: <strong>{{ money(totalDebit) }}</strong> ·
        {{ $t('Credit') }}: <strong>{{ money(totalCredit) }}</strong>
        <span v-if="!balanced" class="text-danger ml-2">{{ $t('DoesNotBalance') }}</span>
        <span v-else class="text-success ml-2">{{ $t('Balances') }}</span>
      </div>

      <b-button class="mt-3" variant="primary" :disabled="Processing || !balanced || totalDebit === 0"
        @click="Save_Entry">{{ $t('Submit') }}</b-button>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";

export default {
  metaInfo: { title: "Accounting" },

  data() {
    return {
      autoPost: true,
      pending: { total: 0, sale: 0, purchase: 0, expense: 0 },
      lastPostedAt: null,
      isLoading: true,
      Processing: false,
      accounts: [],
      entries: [],
      types: [],
      editingAccount: null,
      accountForm: { code: "", name: "", type: null, normal_balance: "debit", is_active: true },
      entryForm: {
        date: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10),
        memo: "",
        lines: [
          { account_id: null, debit: 0, credit: 0 },
          { account_id: null, debit: 0, credit: 0 }
        ]
      }
    };
  },

  computed: {
    ...mapGetters(["currentUser"]),

    totalDebit() {
      return this.entryForm.lines.reduce((s, l) => s + (Number(l.debit) || 0), 0);
    },
    totalCredit() {
      return this.entryForm.lines.reduce((s, l) => s + (Number(l.credit) || 0), 0);
    },
    balanced() {
      return Math.round((this.totalDebit - this.totalCredit) * 100) === 0;
    }
  },

  methods: {
    money(v) {
      const symbol = (this.currentUser && this.currentUser.currency) || "";
      return (symbol ? symbol + " " : "") + Number(v || 0).toFixed(2);
    },

    typeKey(t) {
      return "AccountType_" + t;
    },

    Get_Accounts() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("accounting/accounts")
        .then(r => {
          this.accounts = r.data.accounts;
          this.types = r.data.types;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    Get_Journal() {
      axios.get("accounting/journal", { params: { limit: 25, page: 1 } }).then(r => {
        this.entries = r.data.entries;
      });
    },

    Default_Balance(type) {
      // Assets and expenses normally sit on the debit side; the rest on credit.
      this.accountForm.normal_balance =
        type === "asset" || type === "expense" ? "debit" : "credit";
    },

    Open_Create_Account() {
      this.editingAccount = null;
      this.accountForm = { code: "", name: "", type: null, normal_balance: "debit", is_active: true };
      this.$bvModal.show("account_modal");
    },

    Open_Edit_Account(a) {
      this.editingAccount = a;
      this.accountForm = {
        code: a.code, name: a.name, type: a.type,
        normal_balance: a.normal_balance, is_active: a.is_active
      };
      this.$bvModal.show("account_modal");
    },

    Save_Account() {
      this.Processing = true;
      const req = this.editingAccount
        ? axios.put("accounting/accounts/" + this.editingAccount.id, this.accountForm)
        : axios.post("accounting/accounts", this.accountForm);
      req
        .then(() => {
          this.Processing = false;
          this.$bvModal.hide("account_modal");
          this.Get_Accounts();
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    Remove_Account(a) {
      this.$swal({
        title: this.$t("Are_you_sure"),
        text: a.code + " " + a.name,
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: this.$t("Yes_delete")
      }).then(result => {
        if (!result.value) return;
        axios
          .delete("accounting/accounts/" + a.id)
          .then(() => {
            this.Get_Accounts();
            this.makeToast("success", this.$t("Successfully_Deleted"), this.$t("Success"));
          })
          .catch(e => this.makeToast("danger", this.errorOf(e), this.$t("Failed")));
      });
    },

    Open_Entry() {
      this.entryForm = {
        date: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10),
        memo: "",
        lines: [
          { account_id: null, debit: 0, credit: 0 },
          { account_id: null, debit: 0, credit: 0 }
        ]
      };
      this.$bvModal.show("entry_modal");
    },

    Save_Entry() {
      this.Processing = true;
      axios
        .post("accounting/journal", this.entryForm)
        .then(() => {
          this.Processing = false;
          this.$bvModal.hide("entry_modal");
          this.Get_Journal();
          this.Get_Accounts();
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    Reverse(e) {
      this.$swal({
        title: this.$t("Are_you_sure"),
        text: this.$t("ReverseExplainer"),
        type: "question",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: this.$t("Reverse")
      }).then(result => {
        if (!result.value) return;
        this.Processing = true;
        axios
          .post("accounting/journal/" + e.id + "/reverse", {})
          .then(() => {
            this.Processing = false;
            this.Get_Journal();
            this.Get_Accounts();
            this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
          })
          .catch(err => {
            this.Processing = false;
            this.makeToast("danger", this.errorOf(err), this.$t("Failed"));
          });
      });
    },

    Post_Pending() {
      this.Processing = true;
      axios
        .post("accounting/post_pending", {})
        .then(r => {
          this.Processing = false;
          const p = r.data.posted;
          this.pending = r.data.pending || this.pending;
          this.lastPostedAt = r.data.last_posted_at;
          this.Get_Journal();
          this.Get_Accounts();
          this.makeToast(
            "success",
            this.$t("Sales") + ": " + p.sale + " · " +
            this.$t("Purchases") + ": " + p.purchase + " · " +
            this.$t("Expenses") + ": " + p.expense,
            this.$t("Success")
          );
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    Get_Posting_Status() {
      axios
        .get("accounting/posting_status")
        .then(r => {
          this.autoPost = r.data.enabled;
          this.pending = r.data.pending;
          this.lastPostedAt = r.data.last_posted_at;
        })
        .catch(() => {});
    },

    Set_AutoPost() {
      axios
        .post("accounting/auto_post", { enabled: this.autoPost })
        .then(r => {
          this.autoPost = r.data.enabled;
          this.Get_Posting_Status();
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        })
        .catch(e => {
          this.Get_Posting_Status();
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
    this.Get_Accounts();
    this.Get_Posting_Status();
    this.Get_Journal();
  }
};
</script>

<style scoped>
.bg-warning-soft {
  background: rgba(245, 158, 11, 0.12);
}

/* Measured: the theme's own success and danger tones sit near 2.5:1 as small
   text on the card, so these are the equivalents that clear 4.5:1. */
.post-current {
  color: #087453;
  font-weight: 600;
}

.post-waiting,
.post-off {
  color: #c62828;
  font-weight: 600;
}

.dark-theme .post-current {
  color: #10b981;
}

.dark-theme .post-waiting,
.dark-theme .post-off {
  color: #f87171;
}
</style>
