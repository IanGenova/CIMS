<template>
  <div class="main-content">
    <breadcumb :page="$t('Security')" :folder="$t('Settings')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <b-tabs card>
        <!-- ============ this account ============ -->
        <b-tab :title="$t('MyAccount')" active>
          <b-row>
            <b-col md="6">
              <b-card class="mb-3">
                <h5 class="mb-3">{{ $t('TwoFactorAuth') }}</h5>

                <div v-if="me.two_factor_enabled">
                  <p class="text-success mb-2">
                    <i class="i-Check"></i> {{ $t('TwoFactorOn') }}
                  </p>
                  <p class="text-muted small">
                    {{ $t('RecoveryCodesLeft') }}: {{ me.recovery_codes_left }}
                  </p>
                  <b-button size="sm" variant="outline-primary" class="mr-1" @click="Open_Regenerate">
                    {{ $t('NewRecoveryCodes') }}
                  </b-button>
                  <b-button size="sm" variant="outline-danger"
                    :disabled="me.two_factor_required" @click="Open_Disable">
                    {{ $t('TurnOff') }}
                  </b-button>
                  <p v-if="me.two_factor_required" class="text-muted small mt-2">
                    {{ $t('TwoFactorEnforced') }}
                  </p>
                </div>

                <div v-else>
                  <p class="text-muted">{{ $t('TwoFactorExplainer') }}</p>
                  <b-alert v-if="me.two_factor_required" variant="warning" show class="small">
                    {{ $t('TwoFactorRequiredHere') }}
                  </b-alert>
                  <b-button size="sm" variant="primary" :disabled="Processing" @click="Start2FA">
                    {{ $t('SetUpTwoFactor') }}
                  </b-button>
                </div>
              </b-card>

              <b-card>
                <h5 class="mb-3">{{ $t('ChangePassword') }}</h5>

                <b-alert v-if="me.password_expired" variant="warning" show class="small">
                  {{ $t('PasswordExpiredNotice') }}
                </b-alert>

                <b-form-group :label="$t('CurrentPassword')">
                  <b-form-input type="password" v-model="pw.current_password"/>
                </b-form-group>
                <b-form-group :label="$t('NewPassword')">
                  <b-form-input type="password" v-model="pw.password"/>
                </b-form-group>
                <b-form-group :label="$t('ConfirmPassword')">
                  <b-form-input type="password" v-model="pw.password_confirmation"/>
                </b-form-group>

                <ul class="text-muted small pl-3">
                  <li v-for="(r, i) in me.password_rules" :key="i">{{ r }}</li>
                </ul>

                <b-button variant="primary" :disabled="Processing || !canChangePassword" @click="ChangePassword">
                  {{ $t('Submit') }}
                </b-button>
              </b-card>
            </b-col>

            <b-col md="6">
              <b-card>
                <h5 class="mb-3">{{ $t('SignedInDevices') }}</h5>

                <b-alert v-if="sessionDriver !== 'database'" variant="warning" show class="small">
                  {{ $t('SessionDriverNotice') }} <code>SESSION_DRIVER=database</code>
                </b-alert>

                <div v-if="!sessions.length" class="text-muted py-2">{{ $t('NoOtherSessions') }}</div>

                <div v-else class="table-responsive">
                  <table class="table table-sm">
                    <thead class="bg-gray-300">
                      <tr>
                        <th>{{ $t('IPAddress') }}</th>
                        <th>{{ $t('LastActivity') }}</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="s in sessions" :key="s.id">
                        <td>
                          {{ s.ip_address || '—' }}
                          <br>
                          <small class="text-muted">{{ shortAgent(s.user_agent) }}</small>
                        </td>
                        <td>{{ s.last_activity }}</td>
                        <td>
                          <span v-if="s.is_current" class="badge badge-outline-success">{{ $t('ThisDevice') }}</span>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <b-button size="sm" variant="outline-danger" class="mt-2" @click="Open_SignOutOthers">
                  {{ $t('SignOutOtherDevices') }}
                </b-button>

                <hr>
                <p class="text-muted small mb-0">
                  {{ $t('LastSignIn') }}: {{ me.last_login_at || '—' }}
                  <span v-if="me.last_login_ip">({{ me.last_login_ip }})</span>
                </p>
              </b-card>
            </b-col>
          </b-row>
        </b-tab>

        <!-- ============ policy ============ -->
        <b-tab v-if="canManageUsers" :title="$t('PasswordPolicy')" @click="Get_Policy">
          <p class="text-muted">{{ $t('PolicyIntro') }}</p>

          <b-row v-if="policy">
            <b-col md="6">
              <b-form-group :label="$t('MinimumLength')">
                <b-form-input type="number" min="6" max="128" v-model.number="policy.min_length"/>
              </b-form-group>
              <b-form-checkbox v-model="policy.require_uppercase" switch>{{ $t('RequireUppercase') }}</b-form-checkbox>
              <b-form-checkbox v-model="policy.require_lowercase" switch>{{ $t('RequireLowercase') }}</b-form-checkbox>
              <b-form-checkbox v-model="policy.require_number" switch>{{ $t('RequireNumber') }}</b-form-checkbox>
              <b-form-checkbox v-model="policy.require_symbol" switch>{{ $t('RequireSymbol') }}</b-form-checkbox>
              <b-form-checkbox v-model="policy.require_two_factor" switch class="mt-2">
                {{ $t('RequireTwoFactorAll') }}
              </b-form-checkbox>
            </b-col>
            <b-col md="6">
              <b-form-group :label="$t('PasswordExpiryDays')">
                <b-form-input type="number" min="0" max="3650" v-model.number="policy.expiry_days"/>
                <small class="text-muted">{{ $t('ZeroMeansNever') }}</small>
              </b-form-group>
              <b-form-group :label="$t('PasswordHistory')">
                <b-form-input type="number" min="0" max="24" v-model.number="policy.history_count"/>
                <small class="text-muted">{{ $t('HistoryNote') }}</small>
              </b-form-group>
              <b-form-group :label="$t('MaxFailedAttempts')">
                <b-form-input type="number" min="0" max="100" v-model.number="policy.max_failed_attempts"/>
              </b-form-group>
              <b-form-group :label="$t('LockoutMinutes')">
                <b-form-input type="number" min="1" max="1440" v-model.number="policy.lockout_minutes"/>
              </b-form-group>
            </b-col>
          </b-row>

          <b-button variant="primary" :disabled="Processing" @click="Save_Policy">{{ $t('Submit') }}</b-button>
        </b-tab>

        <!-- ============ users ============ -->
        <b-tab v-if="canManageUsers" :title="$t('UserSecurity')" @click="Get_Users">
          <div class="table-responsive">
            <table class="table table-hover">
              <thead class="bg-gray-300">
                <tr>
                  <th>{{ $t('User') }}</th>
                  <th class="text-center">{{ $t('TwoFactor') }}</th>
                  <th class="text-center">{{ $t('EmailVerified') }}</th>
                  <th class="text-center">{{ $t('PasswordState') }}</th>
                  <th>{{ $t('LastSignIn') }}</th>
                  <th class="text-center">{{ $t('Action') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="u in users" :key="u.id" :class="u.locked ? 'table-danger' : ''">
                  <td>
                    <strong>{{ u.name }}</strong><br>
                    <small class="text-muted">{{ u.email }}</small>
                  </td>
                  <td class="text-center">
                    <span class="badge" :class="u.two_factor ? 'badge-outline-success' : 'badge-outline-secondary'">
                      {{ u.two_factor ? $t('On') : $t('Off') }}
                    </span>
                  </td>
                  <td class="text-center">
                    <i v-if="u.email_verified" class="i-Check text-20 text-success"></i>
                    <span v-else class="badge badge-outline-warning">{{ $t('Unverified') }}</span>
                  </td>
                  <td class="text-center">
                    <span v-if="u.locked" class="badge badge-outline-danger">
                      {{ $t('Locked') }} ({{ u.locked_minutes }}m)
                    </span>
                    <span v-else-if="u.password_expired" class="badge badge-outline-warning">{{ $t('Expired') }}</span>
                    <span v-else-if="u.must_change_password" class="badge badge-outline-warning">{{ $t('MustChange') }}</span>
                    <span v-else class="text-muted">—</span>
                    <br v-if="u.failed_attempts > 0">
                    <small v-if="u.failed_attempts > 0" class="text-muted">
                      {{ u.failed_attempts }} {{ $t('FailedAttempts') }}
                    </small>
                  </td>
                  <td>
                    {{ u.last_login_at || '—' }}
                    <br v-if="u.last_login_ip">
                    <small class="text-muted">{{ u.last_login_ip }}</small>
                  </td>
                  <td class="text-center">
                    <b-dropdown size="sm" variant="outline-primary" :text="$t('Manage')">
                      <b-dropdown-item v-if="u.locked" @click="UserAction(u, 'unlock')">
                        {{ $t('Unlock') }}
                      </b-dropdown-item>
                      <b-dropdown-item @click="UserAction(u, 'force_password_change')">
                        {{ $t('ForcePasswordChange') }}
                      </b-dropdown-item>
                      <b-dropdown-item v-if="u.two_factor" @click="UserAction(u, 'reset_two_factor')">
                        {{ $t('ResetTwoFactor') }}
                      </b-dropdown-item>
                    </b-dropdown>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </b-tab>
      </b-tabs>
    </div>

    <!-- 2FA setup -->
    <b-modal id="setup_2fa" :title="$t('SetUpTwoFactor')" hide-footer no-close-on-backdrop>
      <ol class="pl-3">
        <li class="mb-2">{{ $t('TwoFactorStep1') }}</li>
        <li class="mb-2">
          {{ $t('TwoFactorStep2') }}
          <div class="p-2 mt-2 bg-gray-100 rounded text-center">
            <code class="text-18">{{ setup.secret }}</code>
          </div>
          <small class="text-muted d-block mt-1">{{ $t('ManualEntryNote') }}</small>
        </li>
        <li>{{ $t('TwoFactorStep3') }}</li>
      </ol>

      <b-form-group :label="$t('AuthenticatorCode')" class="mt-3">
        <b-form-input v-model="setup.code" maxlength="6" placeholder="000000" autocomplete="one-time-code"/>
      </b-form-group>

      <b-button variant="primary" :disabled="Processing || !setup.code" @click="Confirm2FA">
        {{ $t('Submit') }}
      </b-button>
    </b-modal>

    <!-- recovery codes, shown once -->
    <b-modal id="recovery_codes" :title="$t('RecoveryCodes')" hide-footer no-close-on-backdrop>
      <b-alert variant="warning" show class="small">{{ $t('RecoveryCodesWarning') }}</b-alert>
      <div class="p-3 bg-gray-100 rounded">
        <div v-for="(c, i) in recoveryCodes" :key="i"><code>{{ c }}</code></div>
      </div>
      <b-button class="mt-3" variant="primary" @click="$bvModal.hide('recovery_codes')">{{ $t('Done') }}</b-button>
    </b-modal>

    <!-- password-confirm actions -->
    <b-modal id="confirm_password" :title="confirmTitle" hide-footer>
      <p class="text-muted">{{ confirmBody }}</p>
      <b-form-group :label="$t('CurrentPassword')">
        <b-form-input type="password" v-model="confirmPw"/>
      </b-form-group>
      <b-button variant="primary" :disabled="Processing || !confirmPw" @click="RunConfirmed">
        {{ $t('Submit') }}
      </b-button>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Security" },

  data() {
    return {
      isLoading: true,
      Processing: false,
      me: { password_rules: [] },
      sessions: [],
      sessionDriver: "",
      policy: null,
      users: [],
      setup: { secret: "", otpauth_url: "", code: "" },
      recoveryCodes: [],
      pw: { current_password: "", password: "", password_confirmation: "" },
      confirmPw: "",
      confirmTitle: "",
      confirmBody: "",
      confirmAction: null
    };
  },

  computed: {
    // The policy and other people's security are for those who manage users;
    // everyone else still gets their own password and two-factor (DATATEST K89).
    canManageUsers() {
      const p = this.$store.getters.currentUserPermissions;
      return !!(p && p.includes("users_view"));
    },
    canChangePassword() {
      return this.pw.current_password && this.pw.password &&
        this.pw.password === this.pw.password_confirmation;
    }
  },

  methods: {
    shortAgent(ua) {
      if (!ua) return "—";
      const m = ua.match(/(Chrome|Firefox|Safari|Edg|Opera)\/[\d.]+/);
      return m ? m[0] : ua.slice(0, 40);
    },

    Get_Me() {
      NProgress.start();
      NProgress.set(0.1);
      Promise.all([axios.get("security/me"), axios.get("security/sessions")])
        .then(([m, s]) => {
          this.me = m.data;
          this.sessions = s.data.sessions;
          this.sessionDriver = s.data.driver;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(() => {
          NProgress.done();
          setTimeout(() => { this.isLoading = false; }, 500);
        });
    },

    Get_Policy() {
      axios.get("security/policy").then(r => { this.policy = r.data.settings; });
    },

    Get_Users() {
      axios.get("security/users").then(r => { this.users = r.data.users; });
    },

    Start2FA() {
      this.Processing = true;
      axios
        .post("security/two_factor/start", {})
        .then(r => {
          this.Processing = false;
          this.setup = { secret: r.data.secret, otpauth_url: r.data.otpauth_url, code: "" };
          this.$bvModal.show("setup_2fa");
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    Confirm2FA() {
      this.Processing = true;
      axios
        .post("security/two_factor/confirm", { code: this.setup.code })
        .then(r => {
          this.Processing = false;
          this.$bvModal.hide("setup_2fa");
          this.recoveryCodes = r.data.recovery_codes;
          this.$bvModal.show("recovery_codes");
          this.Get_Me();
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    Open_Disable() {
      this.confirmTitle = this.$t("TurnOff");
      this.confirmBody = this.$t("DisableTwoFactorBody");
      this.confirmPw = "";
      this.confirmAction = "disable_2fa";
      this.$bvModal.show("confirm_password");
    },

    Open_SignOutOthers() {
      this.confirmTitle = this.$t("SignOutOtherDevices");
      this.confirmBody = this.$t("SignOutOthersBody");
      this.confirmPw = "";
      this.confirmAction = "sign_out_others";
      this.$bvModal.show("confirm_password");
    },

    RunConfirmed() {
      this.Processing = true;
      const url = this.confirmAction === "disable_2fa"
        ? "security/two_factor/disable"
        : "security/sessions/sign_out_others";

      axios
        .post(url, { password: this.confirmPw })
        .then(r => {
          this.Processing = false;
          this.$bvModal.hide("confirm_password");
          this.Get_Me();
          const msg = this.confirmAction === "sign_out_others"
            ? this.$t("SessionsEnded") + ": " + r.data.sessions_ended
            : this.$t("Successfully_Updated");
          this.makeToast("success", msg, this.$t("Success"));
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    Open_Regenerate() {
      this.Processing = true;
      axios
        .post("security/two_factor/recovery_codes", {})
        .then(r => {
          this.Processing = false;
          this.recoveryCodes = r.data.recovery_codes;
          this.$bvModal.show("recovery_codes");
          this.Get_Me();
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    ChangePassword() {
      this.Processing = true;
      axios
        .post("security/password", this.pw)
        .then(() => {
          this.Processing = false;
          this.pw = { current_password: "", password: "", password_confirmation: "" };
          this.Get_Me();
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    Save_Policy() {
      this.Processing = true;
      axios
        .put("security/policy", this.policy)
        .then(() => {
          this.Processing = false;
          this.Get_Me();
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        })
        .catch(e => {
          this.Processing = false;
          this.makeToast("danger", this.errorOf(e), this.$t("Failed"));
        });
    },

    UserAction(u, action) {
      axios
        .post("security/users/" + u.id + "/" + action, {})
        .then(() => {
          this.Get_Users();
          this.makeToast("success", this.$t("Successfully_Updated"), this.$t("Success"));
        })
        .catch(e => this.makeToast("danger", this.errorOf(e), this.$t("Failed")));
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
    this.Get_Me();
    if (this.canManageUsers) {
      this.Get_Policy();
    }
  }
};
</script>
