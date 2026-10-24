 <template>
  <div class="footer_wrap">
    <!-- Footer Start -->
    <div class="app-footer">
      <span v-if="currentUser && currentUser.footer">{{ currentUser.footer }}</span>
      <span>&copy; {{currentYear}} {{currentUser && currentUser.developed_by}}</span>
    </div>
  </div>
</template>
<script>
import { mapGetters, mapActions } from "vuex";

export default {
  data() {
    return {};
  },
  computed: {
     ...mapGetters([
       "currentUser",
    ]),

    // The year was hard-coded to 2022; keep the notice current on its own.
    currentYear() {
      return new Date().getFullYear();
    },
  },

  methods: {}
};
</script>


<style lang="scss" scoped>
</style>
