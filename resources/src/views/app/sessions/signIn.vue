<template>
  <div class="auth-layout-wrap">
    <div class="auth-content">
      <div class="card o-hidden">
        <div class="row">
          <div class="col-md-12">
            <div class="p-4">
              <div class="auth-logo text-center mb-30">
                <img :src="'/images/logo.png'">
              </div>
              <h1 class="mb-3 text-18">{{$t('SignIn')}}</h1>
              <!-- Second step, when two-factor is on: the password was right,
                   but nobody is signed in until the code is. -->
              <b-form v-if="step === 'code'" @submit.prevent="Submit_Code">
                <p class="text-muted text-12">{{ $t('TwoFactorHint') }}</p>
                <b-form-group :label="$t('TwoFactorCode')" class="text-12">
                  <b-form-input
                    ref="codeInput"
                    class="form-control-rounded text-center"
                    v-model="code"
                    autocomplete="one-time-code"
                    inputmode="numeric"
                    autofocus
                  ></b-form-input>
                </b-form-group>
                <b-button
                  type="submit"
                  class="btn-rounded btn-block mt-2"
                  variant="primary mt-2"
                  :disabled="loading || !code"
                >{{ $t('Verify') }}</b-button>
                <div class="mt-3 text-center">
                  <a href="#" class="text-muted" @click.prevent="Start_Over"><u>{{ $t('UseAnotherAccount') }}</u></a>
                </div>
              </b-form>

              <validation-observer v-else ref="submit_login">
                <b-form @submit.prevent="Submit_Login">
                  <validation-provider
                    name="Email Address"
                    :rules="{ required: true}"
                    v-slot="validationContext"
                  >
                    <b-form-group :label="$t('Email_Address')" class="text-12">
                      <b-form-input
                        :state="getValidationState(validationContext)"
                        aria-describedby="Email-feedback"
                        class="form-control-rounded"
                        type="text"
                        v-model="email"
                        email
                      ></b-form-input>
                      <b-form-invalid-feedback id="Email-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>

                  <validation-provider
                    name="Password"
                    :rules="{ required: true}"
                    v-slot="validationContext"
                  >
                    <b-form-group :label="$t('password')" class="text-12">
                      <b-form-input
                        :state="getValidationState(validationContext)"
                        aria-describedby="Password-feedback"
                        class="form-control-rounded"
                        type="password"
                        v-model="password"
                      ></b-form-input>
                      <b-form-invalid-feedback
                        id="Password-feedback"
                      >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>

                  <b-button
                    type="submit"
                    tag="button"
                    class="btn-rounded btn-block mt-2"
                    variant="primary mt-2"
                    :disabled="loading"
                  >{{$t('SignIn')}}</b-button>
                  <div v-once class="typo__p" v-if="loading">
                    <div class="spinner sm spinner-primary mt-3"></div>
                  </div>
                </b-form>
              </validation-observer>

              <div class="mt-3 text-center">
                <a href="/password/reset"  class="text-muted">
                  <u>{{$t('Forgot_Password')}}</u>
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import { mapGetters, mapActions } from "vuex";
import NProgress from "nprogress";
import { afterPassword, refusal } from "../../../utils/signInFlow";

export default {
  metaInfo: {
    title: "SignIn"
  },
  data() {
    return {
      email: "",
      password: "",
      userId: "",
      bgImage: require("./../../../assets/images/photo-wide-4.jpg"),
      loading: false,
      // "password", then "code" when the account has two-factor on.
      step: "password",
      code: ""
    };
  },
  computed: {
    ...mapGetters(["isAuthenticated", "error"])
  },

  methods: {
    //------------- Submit Form login
    Submit_Login() {
      this.$refs.submit_login.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Login();
        }
      });
    },

    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    Login() {
      let self = this;
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      self.loading = true;
      axios
        .post("/login",{
          email: self.email,
          password: self.password
        },
        {
          baseURL: '',
        })
        .then(response => {
          NProgress.done();
          this.loading = false;

          // Two-factor on: the password was right, but no one is signed in
          // until the code is. Reloading here used to drop them back at sign-in.
          if (afterPassword(response.data) === "code") {
            this.step = "code";
            this.code = "";
            return;
          }
          this.Signed_In();
        })
        .catch(error => {
          NProgress.done();
          this.loading = false;
          this.makeToast("danger", refusal(this.bodyOf(error), this.$t("Incorrect_Login")), this.$t("Failed"));
        });
    },

    Submit_Code() {
      NProgress.start();
      this.loading = true;
      axios
        .post("/two-factor-challenge", { code: this.code }, { baseURL: "" })
        .then(() => {
          NProgress.done();
          this.loading = false;
          this.Signed_In();
        })
        .catch(error => {
          NProgress.done();
          this.loading = false;
          const body = this.bodyOf(error);
          this.makeToast("danger", refusal(body, this.$t("Incorrect_Login")), this.$t("Failed"));
          // The held sign-in ran out: start again from the password.
          if (/expired|Start again/i.test(refusal(body, ""))) {
            this.Start_Over();
          }
        });
    },

    Start_Over() {
      this.step = "password";
      this.code = "";
      this.password = "";
    },

    Signed_In() {
      this.makeToast("success", this.$t("Successfully_Logged_In"), this.$t("Success"));
      window.location = "/";
    },

    // The refused request's body, however this axios hands errors over.
    bodyOf(error) {
      if (error && error.response && error.response.data) {
        return error.response.data;
      }
      return error && (error.errors || error.message) ? error : null;
    },

    //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
         toaster: "b-toaster-top-center",
        solid: true
      });
    }
  }
};
</script>
