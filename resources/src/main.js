import store from "./store";
import Vue from "vue";
import App from "./App.vue";
import router from "./router";
import Auth from './auth/index.js';
window.auth = new Auth();
import { ValidationObserver, ValidationProvider, extend, localize } from 'vee-validate';
import * as rules from "vee-validate/dist/rules";

localize({
  en: {
    messages: {
      required: 'This field is required',
      required_if: 'This field is required',
      regex: 'This field must be a valid',
      mimes: `This field must have a valid file type.`,
      size: (_, { size }) => `This field size must be less than ${size}.`,
      min: 'This field must have no less than {length} characters',
      max: (_, { length }) => `This field must have no more than ${length} characters`
    }
  },
});
// Install VeeValidate rules and localization
Object.keys(rules).forEach(rule => {
  extend(rule, rules[rule]);
});

// Register it globally
Vue.component("ValidationObserver", ValidationObserver);
Vue.component('ValidationProvider', ValidationProvider);

import NaspinKit from "./plugins/naspin.kit";
Vue.use(NaspinKit);

import VueCookies from 'vue-cookies'
Vue.use(VueCookies);

var VueCookie = require('vue-cookie');
Vue.use(VueCookie);

window.axios = require('axios');
window.axios.defaults.baseURL = '/api/';

window.axios.defaults.withCredentials = true;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

axios.interceptors.response.use((response) => {

  return response;
}, (error) => {
  if (error.response && error.response.data) {
    if (error.response.status === 401) {
      window.location.href='/login';
    }

    if (error.response.status === 404) {
      router.push({ name: 'NotFound' });
    }
    if (error.response.status === 403) {
      // A password to change or two-factor to set up: the Security page is
      // where that is done, and the only page that works until it is
      // (DATATEST K86/K87).
      if (error.response.data.account_action) {
        if (router.currentRoute.name !== 'security') {
          router.push({ name: 'security' }).catch(() => {});
        }
      } else {
        router.push({ name: 'not_authorize' });
      }
    }

    // The body, which also carries `response` -- see utils/apiError.js.
    return Promise.reject(rejection(error));
  }
  return Promise.reject(rejection(error));
});

import vSelect from 'vue-select'
Vue.component('v-select', vSelect)
import 'vue-select/dist/vue-select.css';

// import Autocomplete from '@trevoreyre/autocomplete-vue';
import '@trevoreyre/autocomplete-vue/dist/style.css';
// Vue.use(Autocomplete);

window.Fire = new Vue();

import Breadcumb from "./components/breadcumb";
import { rejection } from "./utils/apiError";
import { i18n } from "./plugins/i18n";

Vue.component("breadcumb", Breadcumb);

// Installable as a Windows app (public/manifest.webmanifest). The browser's
// install offer arrives early, so it is caught here, before any screen mounts.
import { createInstaller, registerAppWorker } from "./utils/installApp";
Vue.prototype.$installer = createInstaller(window);
window.addEventListener("load", () => registerAppWorker(navigator));
Vue.component("serial-entry", () => import(/* webpackChunkName: "serial_entry" */ "./components/SerialEntry"));

Vue.config.productionTip = true;
Vue.config.silent = true;
Vue.config.devtools = false;

new Vue({
  store,
  router,
  VueCookie,
  i18n,
  render: h => h(App),
}).$mount("#app");
