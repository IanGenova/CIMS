<template>
  <div class="main-header">
    <div class="logo">
       <router-link to="/app/dashboard">
        <img :src="'/images/'+currentUser.logo" alt width="60" height="60">
       </router-link>
    </div>

    <div @click="sideBarToggle" class="menu-toggle">
      <div></div>
      <div></div>
      <div></div>
    </div>

    <div style="margin: auto"></div>

    <div class="header-part-right">

      <!-- Full screen toggle -->
      <i class="i-Full-Screen header-icon d-none d-sm-inline-block" @click="handleFullScreen"></i>
      <!-- Grid menu Dropdown -->


      <!-- Notificaiton -->
      <div class="dropdown">
        <!-- On every screen size: it was hidden below 576px, so phones had no
             bell at all. Its own id: it shared "dropdown-1" with the profile menu. -->
        <b-dropdown
          id="notifications-dropdown"
          text="Dropdown Button"
          right
          class="m-md-2 badge-top-container"
          toggle-class="text-decoration-none"
          no-caret
          variant="link"
        >
          <template slot="button-content" >
            <span class="badge badge-primary" v-if="unreadCount > 0">{{ unreadCount > 99 ? '99+' : unreadCount }}</span>
            <i class="i-Bell text-muted header-icon"></i>
          </template>
          <!-- Notification dropdown -->
          <vue-perfect-scrollbar
            :settings="{ suppressScrollX: true, wheelPropagation: false }"
            :class="{ open: getSideBarToggleProperties.isSideNavOpen }"
            ref="myData"
            class="dropdown-menu-right rtl-ps-none notification-dropdown ps scroll"
          >
            <div v-if="!notifications.length" class="dropdown-item notification-footer text-center text-muted py-3">
              {{$t('NoNotifications')}}
            </div>

            <div
              v-for="n in notifications"
              :key="n.id"
              class="dropdown-item d-flex"
              :class="{ 'bg-gray-100': !n.is_read }"
            >
              <div class="notification-icon">
                <i class="i-Bell mr-1" :class="levelClass(n.level)"></i>
              </div>
              <div class="notification-details flex-grow-1" @click="openNotification(n)">
                <p class="text-small text-muted m-0 cursor-pointer">
                  <span :class="{ 'font-weight-bold': !n.is_read }">{{ n.title }}</span>
                </p>
                <p v-if="n.body" class="text-small text-muted m-0">{{ n.body }}</p>
                <p class="notification-time m-0">{{ whenSaid(n.created_at) }}</p>
              </div>
            </div>

            <div v-if="notifications.length" class="dropdown-item notification-footer text-center">
              <a class="text-small cursor-pointer" @click="markAllRead">{{$t('MarkAllRead')}}</a>
            </div>

            <!-- Windows/Chrome notifications, so a notice is seen while the
                 system sits in a background tab. A browser only asks from a click. -->
            <div v-if="desktopPermission === 'default'" class="dropdown-item notification-footer text-center">
              <a class="text-small cursor-pointer text-primary" @click.stop="enableDesktop">
                <i class="i-Bell mr-1"></i>{{$t('EnableDesktopNotifications')}}
              </a>
            </div>
            <div v-else-if="desktopPermission === 'denied'" class="dropdown-item notification-footer text-center">
              <span class="text-small text-muted">{{$t('DesktopNotificationsBlocked')}}</span>
            </div>
            <div v-else-if="pushOn" class="dropdown-item notification-footer text-center">
              <span class="text-small text-muted"><i class="i-Smartphone-4 mr-1"></i>{{$t('PushNotificationsOn')}}</span>
            </div>

          </vue-perfect-scrollbar>
        </b-dropdown>
      </div>
      <!-- Notificaiton End -->

      <!-- User avatar dropdown -->
      <div class="dropdown">
        <b-dropdown
          id="dropdown-1"
          text="Dropdown Button"
          class="m-md-2 user col align-self-end d-md-block"
          toggle-class="text-decoration-none"
          no-caret
          variant="link"
        >
          <template slot="button-content" >
            <img
              :src="'/images/avatar/'+currentUser.avatar"
              id="userDropdown"
              alt
              data-toggle="dropdown"
              aria-haspopup="true"
              aria-expanded="false"
            >
          </template>

          <div class="dropdown-menu-right" aria-labelledby="userDropdown">
            <div class="dropdown-header">
              <i class="i-Lock-User mr-1"></i> <span >{{currentUser.username}}</span>
            </div>
            <router-link to="/app/profile" class="dropdown-item">{{$t('profil')}}</router-link>
            <!-- Everyone's own password and two-factor (DATATEST K89). -->
            <router-link to="/app/settings/Security" class="dropdown-item">{{$t('Security')}}</router-link>
            <router-link
              v-if="currentUserPermissions && currentUserPermissions.includes('setting_system')"
              to="/app/settings/System_settings"
              class="dropdown-item"
            >{{$t('Settings')}}</router-link>
            <!-- Only while the browser offers it: not once installed, not inside the app. -->
            <a v-if="canInstall" class="dropdown-item" href="#" @click.prevent="installApp">
              <i class="i-Download mr-1"></i>{{$t('InstallApp')}}
            </a>
            <a class="dropdown-item" href="#" @click.prevent="logoutUser">{{$t('logout')}}</a>
          </div>
        </b-dropdown>
      </div>
    </div>
  </div>

  <!-- header top menu end -->
</template>
<script>
import Util from "./../../../utils";
// import Sidebar from "./Sidebar";
import { isMobile } from "mobile-device-detect";
import { mapGetters, mapActions } from "vuex";
import { mixin as clickaway } from "vue-clickaway";
// import { setTimeout } from 'timers';
import FlagIcon from "vue-flag-icon";
import { arrivals, whenSaid } from "./../../../utils/notifyDiff";
import { subscribeDevice, unsubscribeDevice } from "./../../../utils/webPush";

export default {
  mixins: [clickaway],
  components: {
    FlagIcon
  },

  data() {

    return {
      langs: [
        "en",
        "fr",
        "ar",
        "de",
        "es",
        "it",
        "Ind",
        "thai",
        "tr_ch",
        "sm_ch",
        "tur",
        "ru",
        "hn",
        "vn"
      ],

      isDisplay: true,
      isStyle: true,
      isSearchOpen: false,
      isMouseOnMegaMenu: true,
      isMegaMenuOpen: false,
      is_Load:false,
      // alerts:0,

      // Real notifications, replacing the badge that was hard-wired to "1".
      notifications: [],
      unreadCount: 0,
      notificationTimer: null,
      // The newest notice already shown; null until the first look.
      lastSeenId: null,
      // Windows/Chrome notifications: "default" (not asked yet), "granted", "denied",
      // or null when this browser has none.
      desktopPermission: typeof window !== "undefined" && "Notification" in window
        ? window.Notification.permission : null,
      // This device also gets notices while the system is closed (Web Push).
      pushOn: false,
      // The browser is offering to install CIMS as an app (utils/installApp.js).
      canInstall: this.$installer ? this.$installer.state().canInstall : false,
      stopInstallWatch: null,

    };
  },

   computed: {

     ...mapGetters([
       "currentUser",
      "getSideBarToggleProperties",
      "currentUserPermissions",
      "notifs_alert",
    ]),


  },

  mounted() {
    this.loadNotifications();
    // Poll rather than push: there is no websocket here. Every activity now
    // raises its own notice ("PR_1116 needs approval"), so twenty seconds,
    // and at once when the person comes back to the tab.
    this.notificationTimer = setInterval(() => this.loadNotifications(), 20000);
    document.addEventListener("visibilitychange", this.onVisible);
    // Already allowed on this device: keep it signed up, for whoever is signed in.
    this.syncPush();
    if ("serviceWorker" in navigator) {
      navigator.serviceWorker.addEventListener("message", this.onWorkerMessage);
    }
  },

  created() {
    if (this.$installer) {
      this.stopInstallWatch = this.$installer.onChange(s => (this.canInstall = s.canInstall));
    }
  },

  beforeDestroy() {
    if (this.stopInstallWatch) {
      this.stopInstallWatch();
    }
    if (this.notificationTimer) {
      clearInterval(this.notificationTimer);
    }
    document.removeEventListener("visibilitychange", this.onVisible);
    if ("serviceWorker" in navigator) {
      navigator.serviceWorker.removeEventListener("message", this.onWorkerMessage);
    }
  },

  methods: {
    // The browser's own "Install CIMS?" dialog.
    installApp() {
      this.$installer.install();
    },

    ...mapActions([
      "changeSecondarySidebarProperties",
      "changeSidebarProperties",
      "changeThemeMode",
      "logout",
    ]),

    logoutUser() {
      this.$store.dispatch("logout");
    },

    whenSaid(stamp) {
      return whenSaid(stamp);
    },

    levelClass(level) {
      const map = {
        info: "text-primary",
        success: "text-success",
        warning: "text-warning",
        danger: "text-danger"
      };
      return map[level] || "text-primary";
    },

    loadNotifications() {
      axios
        .get("notifications")
        .then(r => {
          this.notifications = r.data.notifications;
          this.unreadCount = r.data.unread;

          const seen = arrivals(this.notifications, this.lastSeenId);
          this.lastSeenId = seen.lastSeenId;
          this.announce(seen.fresh);
        })
        .catch(() => {
          // The bell is not worth an error toast; leave it as it was.
        });
    },

    onVisible() {
      if (document.visibilityState === "visible") {
        this.loadNotifications();
      }
    },

    // New notices: a pop-up in the app, and a Windows/Chrome notification when
    // the person has allowed them. A handful at most, so a busy minute does
    // not bury the screen; the rest wait in the bell.
    announce(fresh) {
      const shown = fresh.slice(-3);
      shown.forEach(n => {
        this.$root.$bvToast.toast(n.body || " ", {
          title: n.title,
          variant: { danger: "danger", warning: "warning", success: "success" }[n.level] || "info",
          toaster: "b-toaster-top-right",
          solid: true,
          autoHideDelay: 8000,
          to: n.link || undefined
        });
        this.desktop(n);
      });
      if (fresh.length > shown.length) {
        this.$root.$bvToast.toast(this.$t("AndMoreNotifications", { count: fresh.length - shown.length }), {
          title: this.$t("Notifications"),
          variant: "info",
          toaster: "b-toaster-top-right",
          solid: true,
          autoHideDelay: 8000
        });
      }
    },

    desktop(n) {
      if (this.desktopPermission !== "granted") {
        return;
      }
      try {
        const note = new window.Notification(n.title, {
          body: n.body || "",
          tag: "cims-" + n.id,
          icon: "/images/favicon.ico"
        });
        note.onclick = () => {
          window.focus();
          if (n.link && this.$route.path !== n.link) {
            this.$router.push(n.link).catch(() => {});
          }
          note.close();
        };
      } catch (e) {
        // Some browsers only allow these from a service worker; the pop-up
        // in the app has already been shown.
      }
    },

    // Browsers only ask from a click, so this sits in the bell's menu.
    enableDesktop() {
      if (!("Notification" in window)) {
        return;
      }
      window.Notification.requestPermission().then(p => {
        this.desktopPermission = p;
        if (p === "granted") {
          this.desktop({ id: 0, title: this.$t("DesktopNotificationsOn"), body: "" });
          this.syncPush();
        }
      });
    },

    syncPush() {
      subscribeDevice(axios, window, navigator).then(on => { this.pushOn = on; });
    },

    // The service worker had a notice while the system was on screen: look now
    // rather than at the next poll, so the pop-up is immediate.
    onWorkerMessage(e) {
      if (e.data && e.data.type === "cims-notification") {
        this.loadNotifications();
      }
    },

    openNotification(n) {
      axios.post("notifications/" + n.id + "/read", {}).then(() => {
        this.loadNotifications();
        if (n.link && this.$route.path !== n.link) {
          this.$router.push(n.link);
        }
      });
    },

    markAllRead() {
      axios.post("notifications/read_all", {}).then(() => this.loadNotifications());
    },

    SetLocal(locale) {
      this.$i18n.locale = locale;
      this.$store.dispatch("language/setLanguage", locale);
      Fire.$emit("ChangeLanguage");
    },

    handleFullScreen() {
      Util.toggleFullScreen();
    },
    logoutUser() {
      // Stop this device getting the notices of the person signing out.
      unsubscribeDevice(axios, window, navigator).then(() => this.logout());
    },

    closeMegaMenu() {
      this.isMegaMenuOpen = false;
    },
    toggleMegaMenu() {
      this.isMegaMenuOpen = !this.isMegaMenuOpen;
    },
    toggleSearch() {
      this.isSearchOpen = !this.isSearchOpen;
    },

    sideBarToggle(el) {
      if (
        this.getSideBarToggleProperties.isSideNavOpen &&
        this.getSideBarToggleProperties.isSecondarySideNavOpen &&
        isMobile
      ) {
        this.changeSidebarProperties();
        this.changeSecondarySidebarProperties();
      } else if (
        this.getSideBarToggleProperties.isSideNavOpen &&
        this.getSideBarToggleProperties.isSecondarySideNavOpen
      ) {
        this.changeSecondarySidebarProperties();
      } else if (this.getSideBarToggleProperties.isSideNavOpen) {
        this.changeSidebarProperties();
      } else if (
        !this.getSideBarToggleProperties.isSideNavOpen &&
        !this.getSideBarToggleProperties.isSecondarySideNavOpen &&
        !this.getSideBarToggleProperties.isActiveSecondarySideNav
      ) {
        this.changeSidebarProperties();
      } else if (
        !this.getSideBarToggleProperties.isSideNavOpen &&
        !this.getSideBarToggleProperties.isSecondarySideNavOpen
      ) {

        this.changeSidebarProperties();
        this.changeSecondarySidebarProperties();
      }
    }
  }
};
</script>



