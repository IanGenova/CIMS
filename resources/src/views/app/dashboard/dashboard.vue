<template>
  <!-- ============ Body content start ============= -->
  <div class="main-content dashboard-main">
    <b-row>
      <!-- ICON BG -->

      <b-col lg="3" md="6" sm="12">
        <b-card class="card-icon-bg card-icon-bg-primary o-hidden mb-30 text-center">
          <i class="i-Coins"></i>
          <div class="content">
            <p class="text-muted mt-2 mb-0">{{$t('Revenue')}} today</p>
            <p
              class="text-primary text-24 line-height-1 mb-2"
            >{{currentUser.currency}} {{formatNumber((report_today.revenue?report_today.revenue:0),2)}}</p>
          </div>
        </b-card>
      </b-col>

      <b-col lg="3" md="6" sm="12">
        <b-card class="card-icon-bg card-icon-bg-primary o-hidden mb-30 text-center">
          <i class="i-Add-Cart"></i>
          <div class="content">
            <p class="text-muted mt-2 mb-0">{{$t('Purchases')}} today</p>
            <p
              class="text-primary text-24 line-height-1 mb-2"
            >{{currentUser.currency}} {{formatNumber((report_today.today_purchases?report_today.today_purchases:0),2)}}</p>
          </div>
        </b-card>
      </b-col>

      <b-col lg="3" md="6" sm="12">
        <b-card class="card-icon-bg card-icon-bg-primary o-hidden mb-30 text-center">
          <i class="i-Right-4"></i>
          <div class="content">
            <p class="text-muted mt-2 mb-0">{{$t('SalesReturn')}} today</p>
            <p
              class="text-primary text-24 line-height-1 mb-2"
            >{{currentUser.currency}} {{formatNumber((report_today.return_sales?report_today.return_sales:0),2)}}</p>
          </div>
        </b-card>
      </b-col>
      <!-- Hidden when the server withholds profit from this user (DATATEST K56). -->
      <b-col lg="3" md="6" sm="12" v-if="report_today.profit !== null">
        <b-card class="card-icon-bg card-icon-bg-primary o-hidden mb-30 text-center">
          <i class="i-Financial"></i>
          <div class="content">
            <p class="text-muted mt-2 mb-0">{{$t('Profit')}} today</p>
            <p
              class="text-primary text-24 line-height-1 mb-2"
            >{{currentUser.currency}} {{formatNumber((report_today.profit?report_today.profit:0),2)}}</p>
          </div>
        </b-card>
      </b-col>
    </b-row>

    <b-row>
      <b-col lg="8" md="12" sm="12">
        <b-card class="mb-30">
          <h4 class="card-title m-0">{{$t('This_Week_Sales_Purchases')}}</h4>
          <div class="chart-wrapper">
            <div v-once class="typo__p text-right" v-if="loading">
              <div class="spinner sm spinner-primary mt-3"></div>
            </div>
            <v-chart v-if="!loading" :options="echartSales" :autoresize="true"></v-chart>
          </div>
        </b-card>
      </b-col>
      <b-col col lg="4" md="12" sm="12">
        <b-card class="mb-30">
          <h4 class="card-title m-0">{{$t('Top_Selling_Products')}} ({{new Date().getFullYear()}})</h4>
          <div v-if="loading" class="spinner sm spinner-primary mt-3"></div>
          <div v-else-if="!topYearProducts.length" class="text-muted py-5 text-center">No completed product sales this year.</div>
          <ol v-else class="top-products-list" aria-label="Top selling products by sales value">
            <li v-for="product in topYearProducts" :key="product.name">
              <span :title="product.name">{{ product.name }}</span>
              <strong>{{ currentUser.currency }} {{ Number(product.value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</strong>
            </li>
          </ol>
        </b-card>
      </b-col>
    </b-row>

    <b-row>
      <!-- Stock Alert -->
      <div class="col-md-8">
        <div class="card mb-30">
          <div class="card-body p-2">
            <h5 class="card-title border-bottom p-3 mb-2">{{$t('StockAlert')}}</h5>

            <vue-good-table
              :columns="columns_stock"
              styleClass="order-table vgt-table mb-3"
              row-style-class="text-left"
              :rows="stock_alerts"
            >
              <template slot="table-row" slot-scope="props">
                <div v-if="props.column.field == 'stock_alert'">
                  <span class="badge badge-outline-danger">{{props.row.stock_alert}}</span>
                </div>
              </template>
            </vue-good-table>
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="card mb-30">
          <div class="card-body p-3">
            <h5
              class="card-title border-bottom p-3 mb-2"
            >{{$t('Top_Selling_Products')}} ({{CurrentMonth}})</h5>

            <vue-good-table
              :columns="columns_products"
              styleClass="order-table vgt-table"
              row-style-class="text-left"
              :rows="products"
            >
              <template slot="table-row" slot-scope="props">
                <div v-if="props.column.field == 'quantity'">
                  <span>{{formatNumber(props.row.quantity ,2)}} {{props.row.unit_product}}</span>
                </div>
                <div v-else-if="props.column.field == 'total'">
                  <span>{{currentUser.currency}} {{formatNumber(props.row.total ,2)}}</span>
                </div>
              </template>
            </vue-good-table>
          </div>
        </div>
      </div>
    </b-row>

    <b-row>
      <b-col lg="8" md="12" sm="12">
        <b-card class="mb-30">
          <h4 class="card-title m-0">{{$t('Payment_Sent_Received')}}</h4>
          <div class="chart-wrapper">
            <v-chart :options="echartPayment" :autoresize="true"></v-chart>
          </div>
        </b-card>
      </b-col>
      <b-col col lg="4" md="12" sm="12">
        <b-card class="mb-30">
          <h4 class="card-title m-0">{{$t('TopCustomers')}} ({{CurrentMonth}})</h4>
          <div class="chart-wrapper">
            <v-chart :options="echartCustomer" :autoresize="true"></v-chart>
          </div>
        </b-card>
      </b-col>
    </b-row>

    <!-- Last Sales -->
    <b-row>
      <div class="col-md-12">
        <div class="card mb-30">
          <div class="card-body p-0">
            <h5 class="card-title border-bottom p-3 mb-2">{{$t('Recent_Sales')}}</h5>

            <vue-good-table
              v-if="!loading"
              :columns="columns_sales"
              styleClass="order-table vgt-table"
              row-style-class="text-left"
              :rows="sales"
            >
              <template slot="table-row" slot-scope="props">
                <div v-if="props.column.field == 'statut'">
                  <span
                    v-if="props.row.statut == 'completed'"
                    class="badge badge-outline-success"
                  >{{$t('complete')}}</span>
                  <span
                    v-else-if="props.row.statut == 'pending'"
                    class="badge badge-outline-info"
                  >{{$t('Pending')}}</span>
                  <span v-else class="badge badge-outline-warning">{{$t('Ordered')}}</span>
                </div>

                <div v-else-if="props.column.field == 'payment_status'">
                  <span
                    v-if="props.row.payment_status == 'paid'"
                    class="badge badge-outline-success"
                  >{{$t('Paid')}}</span>
                  <span
                    v-else-if="props.row.payment_status == 'partial'"
                    class="badge badge-outline-primary"
                  >{{$t('partial')}}</span>
                  <span v-else class="badge badge-outline-warning">{{$t('Unpaid')}}</span>
                </div>
              </template>
            </vue-good-table>
          </div>
        </div>
      </div>
    </b-row>
  </div>
  <!-- ============ Body content End ============= -->
</template>
<script>
import { mapGetters } from "vuex";

import ECharts from "vue-echarts/components/ECharts.vue";

// import ECharts modules manually to reduce bundle size
import "echarts/lib/chart/pie";
import "echarts/lib/chart/bar";
import "echarts/lib/chart/line";
import "echarts/lib/component/tooltip";
import "echarts/lib/component/legend";

export default {
  components: {
    "v-chart": ECharts
  },
  metaInfo: {
    // if no subcomponents specify a metaInfo.title, this title will be used
    title: "Dashboard"
  },
  data() {
    return {
      sales: [],
      stock_alerts: [],
      report_today: {
        revenue: 0,
        today_purchases: 0,
        return_sales: 0,
        profit: 0
      },
      products: [],
      topYearProducts: [],
      CurrentMonth: "",
      loading: true,
      echartSales: {},
      echartProduct: {},
      echartCustomer: {},
      echartPayment: {}
    };
  },
  watch: {
    // Re-skin every chart when the theme is switched.
    "getThemeMode.dark": function () {
      ["echartSales", "echartProduct", "echartPayment", "echartCustomer"].forEach(key => {
        if (this[key] && Object.keys(this[key]).length) {
          const patch = { textStyle: { color: this.chartTextColor } };
          if (this[key].legend) {
            patch.legend = Object.assign({}, this[key].legend, {
              textStyle: { color: this.chartTextColor }
            });
          }
          this[key] = Object.assign({}, this[key], patch);
        }
      });
    }
  },

  computed: {
    ...mapGetters(["currentUser", "getThemeMode"]),

    /**
     * ECharts draws its own text, so CSS cannot reach the legend or the axis
     * labels. They default to a near-black that vanishes on a dark ground, so
     * the colour has to be handed to the chart.
     */
    chartTextColor() {
      return this.getThemeMode.dark ? "#c9d8d2" : "#4B5563";
    },

    chartAxisLineColor() {
      return this.getThemeMode.dark ? "rgba(228,237,233,0.15)" : "rgba(0,0,0,0.12)";
    },
    columns_sales() {
      return [
        {
          label: this.$t("Reference"),
          field: "Ref",
          tdClass: "gull-border-none text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Customer"),
          field: "client_name",
          tdClass: "gull-border-none text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Status"),
          field: "statut",
          html: true,
          tdClass: "gull-border-none text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Total"),
          field: "GrandTotal",
          type: "decimal",
          tdClass: "gull-border-none text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Paid"),
          field: "paid_amount",
          type: "decimal",
          tdClass: "gull-border-none text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Due"),
          field: "due",
          type: "decimal",
          tdClass: "gull-border-none text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("PaymentStatus"),
          field: "payment_status",
          html: true,
          sortable: false,
          tdClass: "text-left gull-border-none",
          thClass: "text-left"
        }
      ];
    },
    columns_stock() {
      return [
        {
          label: this.$t("ProductCode"),
          field: "code",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("ProductName"),
          field: "name",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("warehouse"),
          field: "warehouse",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Quantity"),
          field: "quantity",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("AlertQuantity"),
          field: "stock_alert",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        }
      ];
    },
    columns_products() {
      return [
        {
          label: this.$t("ProductName"),
          field: "name",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Quantity"),
          field: "quantity",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Total"),
          field: "total",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        }
      ];
    }
  },
  methods: {
    //---------------------------------- Report Dashboard With Echart
    report_with_echart() {
      axios
        .get(`chart/report_with_echart`)
        .then(response => {
          const responseData = response.data;

          this.report_today = response.data.report_dashboard.original.report;
          this.stock_alerts =
            response.data.report_dashboard.original.stock_alert;
          this.products = response.data.report_dashboard.original.products;
          this.sales = response.data.report_dashboard.original.last_sales;
          this.topYearProducts = (responseData.product_report.original || [])
            .slice().sort((a, b) => Number(b.value) - Number(a.value)).slice(0, 5);
          var dark_heading = "#c2c6dc";

          this.echartCustomer = {
            textStyle: { color: this.chartTextColor },
            color: ["#6D28D9", "#8B5CF6", "#A78BFA", "#C4B5FD", "#7C3AED"],
            tooltip: {
              show: true,
              backgroundColor: "rgba(0, 0, 0, .8)"
            },

            formatter: function(params) {
              return `${params.name}: (${params.data.value} sales) (${
                params.percent
              }%)`;
            },

            series: [
              {
                name: "Top Customers",
                type: "pie",
                radius: "50%",
                center: "50%",

                data: responseData.customers.original,
                itemStyle: {
                  emphasis: {
                    shadowBlur: 10,
                    shadowOffsetX: 0,
                    shadowColor: "rgba(0, 0, 0, 0.5)"
                  }
                }
              }
            ]
          };
          this.echartPayment = {
            textStyle: { color: this.chartTextColor },
            tooltip: {
              trigger: "axis"
            },
            legend: {
              data: ["Payment sent", "Payment received"],
              textStyle: { color: this.chartTextColor }
            },
            grid: {
              left: "3%",
              right: "4%",
              bottom: "3%",
              containLabel: true
            },
            toolbox: {
              feature: {
                saveAsImage: {}
              }
            },
            xAxis: {
              type: "category",
              boundaryGap: false,
              data: responseData.payments.original.days
            },
            yAxis: {
              type: "value"
            },
            series: [
              {
                name: "Payment sent",
                type: "line",
                data: responseData.payments.original.payment_sent
              },
              {
                name: "Payment received",
                type: "line",
                data: responseData.payments.original.payment_received
              }
            ]
          };
          this.echartProduct = {
            textStyle: { color: this.chartTextColor },
            color: ["#6D28D9", "#8B5CF6", "#A78BFA", "#C4B5FD", "#7C3AED"],
            tooltip: {
              show: true,
              backgroundColor: "rgba(0, 0, 0, .8)"
            },
            formatter: function(params) {
              return `${params.name}: (${params.percent}%)`;
            },
            series: [
              {
                name: "Top Selling Products",
                type: "pie",
                radius: "50%",
                center: "50%",

                data: responseData.product_report.original,
                itemStyle: {
                  emphasis: {
                    shadowBlur: 10,
                    shadowOffsetX: 0,
                    shadowColor: "rgba(0, 0, 0, 0.5)"
                  }
                }
              }
            ]
          };
          this.echartSales = {
            textStyle: { color: this.chartTextColor },
            legend: {
              borderRadius: 0,
              orient: "horizontal",
              x: "right",
              data: ["Sales", "Purchases"],
              textStyle: { color: this.chartTextColor }
            },
            grid: {
              left: "8px",
              right: "8px",
              bottom: "0",
              containLabel: true
            },
            tooltip: {
              show: true,

              backgroundColor: "rgba(0, 0, 0, .8)"
            },

            xAxis: [
              {
                type: "category",
                data: responseData.sales.original.days,
                axisTick: {
                  alignWithLabel: true
                },
                splitLine: {
                  show: false
                },
                axisLabel: {
                  color: dark_heading,
                  interval: 0,
                  rotate: 30
                },
                axisLine: {
                  show: true,
                  color: dark_heading,

                  lineStyle: {
                    color: dark_heading
                  }
                }
              }
            ],
            yAxis: [
              {
                type: "value",

                axisLabel: {
                  color: dark_heading
                  // formatter: "${value}"
                },
                axisLine: {
                  show: false,
                  color: dark_heading,

                  lineStyle: {
                    color: dark_heading
                  }
                },
                min: 0,
                splitLine: {
                  show: true,
                  interval: "auto"
                }
              }
            ],

            series: [
              {
                name: "Sales",
                data: responseData.sales.original.data,
                label: { show: false, color: "#8B5CF6" },
                type: "bar",
                color: "#A78BFA",
                smooth: true,
                itemStyle: {
                  emphasis: {
                    shadowBlur: 10,
                    shadowOffsetX: 0,
                    shadowOffsetY: -2,
                    shadowColor: "rgba(0, 0, 0, 0.3)"
                  }
                }
              },
              {
                name: "Purchases",
                data: responseData.purchases.original.data,

                label: { show: false, color: "#0168c1" },
                type: "bar",
                barGap: 0,
                color: "#DDD6FE",
                smooth: true,
                itemStyle: {
                  emphasis: {
                    shadowBlur: 10,
                    shadowOffsetX: 0,
                    shadowOffsetY: -2,
                    shadowColor: "rgba(0, 0, 0, 0.3)"
                  }
                }
              }
            ]
          };
          this.loading = false;
        })
        .catch(response => {});
    },

    //------------------------------Get Month -------------------------\\
    GetMonth() {
      var months = [
        "January",
        "February",
        "March",
        "April",
        "May",
        "June",
        "July",
        "August",
        "September",
        "October",
        "November",
        "December"
      ];
      var now = new Date();
      this.CurrentMonth = months[now.getMonth()];
    },

    //------------------------------Formetted Numbers -------------------------\\
    formatNumber(number, dec) {
      // A null here used to throw inside the render and blank the rest of the
      // page -- the API returns one for, among other things, a warehouse the
      // product has no stock row in. Nothing is a better answer than a crash.
      if (number === null || number === undefined || number === "") number = 0;
      const value = (typeof number === "string"
        ? number
        : number.toString()
      ).split(".");
      if (dec <= 0) return value[0];
      let formated = value[1] || "";
      if (formated.length > dec)
        return `${value[0]}.${formated.substr(0, dec)}`;
      while (formated.length < dec) formated += "0";
      return `${value[0]}.${formated}`;
    }
  },
  async mounted() {
    await this.report_with_echart();
    this.GetMonth();
  }
};
</script>
<style scoped>
.top-products-list { padding: 0; margin: 1.25rem 0 0; list-style: none; }
.top-products-list li { display: flex; flex-direction: column; gap: .2rem; padding: .55rem 0; border-bottom: 1px solid #e8edf0; }
.top-products-list li:last-child { border-bottom: 0; }
.top-products-list span { overflow-wrap: anywhere; }
.top-products-list strong { color: #087a56; }
</style>
<style>
@media (min-width: 992px) and (max-width: 1300px) {
  .main-content-wrap.secondary-sidenav-open .dashboard-main > .row:first-child > .col-lg-3 {
    flex: 0 0 50%;
    max-width: 50%;
  }
  .main-content-wrap.secondary-sidenav-open .dashboard-main > .row:nth-child(2) > .col-lg-8,
  .main-content-wrap.secondary-sidenav-open .dashboard-main > .row:nth-child(2) > .col-lg-4 {
    flex: 0 0 100%;
    max-width: 100%;
  }
}
</style>
