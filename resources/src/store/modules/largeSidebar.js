const state = {
  sidebarToggleProperties: {
    isSideNavOpen: true,
    isSecondarySideNavOpen: false,
    isActiveSecondarySideNav: false
  }
};

const getters = {
  getSideBarToggleProperties: state => state.sidebarToggleProperties
};

const actions = {
  changeSidebarProperties({commit}) {
    commit("toggleSidebarProperties");
  },
  // Set, not toggle: a caller that means "close it" must never open it.
  setSidebarOpen({commit}, open) {
    commit("setSidebarOpen", open);
  },
  // After a page change. At 1200px or less the sidebar is a drawer over the
  // page, so both drawers close; on a desktop only the flyout menu closes.
  // (This used to toggle the sidebar, which opened a closed drawer on every
  // other navigation.) See tests/js/largeSidebar.test.js.
  closeDrawersOnNarrow({commit, state}, width) {
    if (width <= 1200) {
      commit("setSidebarOpen", false);
    }
    if (state.sidebarToggleProperties.isSecondarySideNavOpen) {
      commit("toggleSecondarySidebarProperties");
    }
  },
  changeSecondarySidebarProperties({commit}) {
    commit("toggleSecondarySidebarProperties");
  },
  changeSecondarySidebarPropertiesViaMenuItem({commit}, data) {
    commit("toggleSecondarySidebarPropertiesViaMenuItem", data);
  },
  changeSecondarySidebarPropertiesViaOverlay({commit}) {
    commit("toggleSecondarySidebarPropertiesViaOverlay");
  }
};

const mutations = {
  toggleSidebarProperties: state =>
    (state.sidebarToggleProperties.isSideNavOpen = !state
      .sidebarToggleProperties.isSideNavOpen),

  setSidebarOpen: (state, open) => {
    state.sidebarToggleProperties.isSideNavOpen = !!open;
  },

  toggleSecondarySidebarProperties: state =>
    (state.sidebarToggleProperties.isSecondarySideNavOpen = !state
      .sidebarToggleProperties.isSecondarySideNavOpen),
  toggleSecondarySidebarPropertiesViaMenuItem(state, data) {
    state.sidebarToggleProperties.isSecondarySideNavOpen = data;
    state.sidebarToggleProperties.isActiveSecondarySideNav = data;
  },
  toggleSecondarySidebarPropertiesViaOverlay(state) {
    state.sidebarToggleProperties.isSecondarySideNavOpen = !state
      .sidebarToggleProperties.isSecondarySideNavOpen;
  }
};

export default {
  state,
  getters,
  actions,
  mutations
};
