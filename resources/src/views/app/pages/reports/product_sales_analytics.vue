<template>
  <div class="main-content product-sales-analytics">
    <breadcumb page="Product Sales Analytics" :folder="$t('Reports')" />

    <b-card class="mb-3">
      <div class="d-flex flex-wrap align-items-end justify-content-between">
        <div class="mr-3 mb-2">
          <h4 class="mb-1">Product sales forecast</h4>
          <p class="text-muted mb-0">Linear regression on completed weekly sales. Current, unfinished week is excluded.</p>
        </div>
        <div class="d-flex flex-wrap align-items-end">
          <b-form-group label="Sales history" class="mr-2 mb-2">
            <b-form-select v-model.number="historyWeeks" :options="historyOptions" @change="load" />
          </b-form-group>
          <b-form-group label="Forecast" class="mr-2 mb-2">
            <b-form-select v-model.number="horizonWeeks" :options="horizonOptions" @change="load" />
          </b-form-group>
          <b-form-group label="Warehouse" class="mb-2">
            <b-form-select v-model="warehouseId" @change="load">
              <option value="">All allowed warehouses</option>
              <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
            </b-form-select>
          </b-form-group>
        </div>
      </div>
      <small v-if="period" class="text-muted">
        Sales: {{ period.history_from }} to {{ period.history_through }} ·
        Forecast: {{ period.forecast_from }} to {{ period.forecast_through }}
        <span v-if="scope.own_sales_only"> · Showing your sales only</span>
      </small>
    </b-card>

    <b-alert v-if="error" show variant="danger">{{ error }}</b-alert>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <template v-else-if="!error">
      <b-row class="mb-2">
        <b-col md="4"><b-card class="mb-3"><div class="text-muted">Products with sales</div><div class="text-24">{{ summary.products_with_sales }}</div></b-card></b-col>
        <b-col md="4"><b-card class="mb-3"><div class="text-muted">Trending upward</div><div class="text-24 text-success">{{ summary.trending_products }}</div></b-card></b-col>
        <b-col md="4"><b-card class="mb-3"><div class="text-muted">Limited sales history</div><div class="text-24 text-warning">{{ summary.limited_data_products }}</div></b-card></b-col>
      </b-row>

      <b-alert show variant="info" class="mb-3">
        Top and bottom rank projected <strong>sales value</strong> only after at least four weeks with sales.
        Trending uses the slope of <strong>units sold</strong>. Forecasts are estimates, not guaranteed sales.
        Figures are gross completed sales; returns are not subtracted.
      </b-alert>

      <b-alert v-if="summary.limited_data_products" show variant="warning" class="mb-3">
        {{ summary.limited_data_products }} {{ summary.limited_data_products === 1 ? 'product has' : 'products have' }} fewer than four weeks with sales. Their forecast figures are withheld until more history is available.
      </b-alert>

      <b-card v-if="!products.length" class="mb-3">
        <p class="text-muted mb-0">No completed sales were found in these full weeks for the selected warehouse.</p>
      </b-card>

      <template v-else>
        <b-row>
          <b-col lg="4" class="mb-3">
            <b-card title="Most projected sales" class="h-100">
              <p v-if="!top.length" class="text-muted mb-0">No products have enough sales history to rank yet.</p>
              <div v-for="(row, index) in top" :key="row.product_id" class="rank-row" @click="select(row)">
                <span class="rank-number">{{ index + 1 }}</span>
                <span class="rank-name"><strong>{{ row.name }}</strong><small>{{ row.code }}</small></span>
                <span class="text-right"><strong>{{ money(row.predicted_revenue) }}</strong><small>{{ trendLabel(row.trend) }}</small></span>
              </div>
            </b-card>
          </b-col>
          <b-col lg="4" class="mb-3">
            <b-card title="Least projected sales" class="h-100">
              <p v-if="!bottom.length" class="text-muted mb-0">No products have enough sales history to rank yet.</p>
              <div v-for="(row, index) in bottom" :key="row.product_id" class="rank-row" @click="select(row)">
                <span class="rank-number">{{ index + 1 }}</span>
                <span class="rank-name"><strong>{{ row.name }}</strong><small>{{ row.code }}</small></span>
                <span class="text-right"><strong>{{ money(row.predicted_revenue) }}</strong><small>{{ trendLabel(row.trend) }}</small></span>
              </div>
            </b-card>
          </b-col>
          <b-col lg="4" class="mb-3">
            <b-card title="Trending products" class="h-100">
              <p v-if="!trending.length" class="text-muted mb-0">No clear upward unit trends yet. More weekly sales may improve the signal.</p>
              <div v-for="(row, index) in trending" :key="row.product_id" class="rank-row" @click="select(row)">
                <span class="rank-number">{{ index + 1 }}</span>
                <span class="rank-name"><strong>{{ row.name }}</strong><small>{{ row.code }}</small></span>
                <span class="text-right text-success"><strong>+{{ quantity(row.unit_slope_per_week) }}</strong><small>{{ row.unit }}/week</small></span>
              </div>
            </b-card>
          </b-col>
        </b-row>

        <b-card v-if="selected" class="mb-3">
          <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
            <div>
              <h5 class="mb-0">{{ selected.name }} <small class="text-muted">{{ selected.code }}</small></h5>
              <span class="text-muted">{{ selected.trend === 'limited' ? 'Weekly units sold; forecast requires four weeks with sales' : `Weekly units sold and the next ${horizonWeeks} weeks of projected demand` }}</span>
            </div>
            <div v-if="selected.trend !== 'limited'" class="text-right">
              <strong>{{ quantity(selected.predicted_units) }} {{ selected.unit }}</strong> projected ·
              <strong>{{ money(selected.predicted_revenue) }}</strong> projected sales value
            </div>
          </div>
          <svg class="forecast-chart" viewBox="0 0 760 240" role="img" :aria-label="(selected.trend === 'limited' ? 'Weekly unit sales for ' : 'Weekly unit sales and forecast for ') + selected.name">
            <line x1="45" y1="190" x2="730" y2="190" stroke="#c9d1db" />
            <line x1="45" y1="25" x2="45" y2="190" stroke="#c9d1db" />
            <line v-if="selected.trend !== 'limited'" :x1="splitX" y1="25" :x2="splitX" y2="190" stroke="#99a5b4" stroke-dasharray="4 4" />
            <text x="4" y="32" font-size="12" fill="#64748b">{{ quantity(chartMax) }}</text>
            <text x="19" y="194" font-size="12" fill="#64748b">0</text>
            <polyline :points="actualPoints" fill="none" stroke="#087a56" stroke-width="3" stroke-linejoin="round" />
            <polyline v-if="selected.trend !== 'limited'" :points="forecastPoints" fill="none" stroke="#6b48d7" stroke-width="3" stroke-dasharray="7 5" stroke-linejoin="round" />
            <text x="65" y="221" font-size="12" fill="#64748b">Completed weeks</text>
            <text v-if="selected.trend !== 'limited'" x="600" y="221" font-size="12" fill="#64748b">Forecast weeks</text>
          </svg>
          <div class="d-flex flex-wrap small text-muted">
            <span class="mr-3">Green: recorded sales</span>
            <span v-if="selected.trend !== 'limited'" class="mr-3">Purple dashed: linear forecast</span>
            <span class="mr-3">Weeks with sales: {{ selected.active_weeks }}/{{ historyWeeks }}</span>
            <span>Historical line fit (R²): {{ Math.round(selected.unit_fit_r_squared * 100) }}% — not a probability of future accuracy</span>
          </div>
        </b-card>

        <b-card title="All products with recorded sales">
          <b-form-input v-model.trim="search" placeholder="Search product name or code" class="mb-3" aria-label="Search products" />
          <div class="table-responsive">
            <table class="table table-hover">
              <thead class="bg-gray-300"><tr>
                <th>Product</th><th class="text-right">Historical units</th><th class="text-right">Projected units</th>
                <th class="text-right">Projected sales value</th><th class="text-right">Unit trend / week</th><th>Signal</th>
              </tr></thead>
              <tbody>
                <tr v-for="row in filteredProducts" :key="row.product_id" class="product-row" @click="select(row)">
                  <td><strong>{{ row.name }}</strong><br /><small class="text-muted">{{ row.code }}</small></td>
                  <td class="text-right">{{ quantity(row.observed_units) }} {{ row.unit }}</td>
                  <td class="text-right">{{ row.trend === 'limited' ? '—' : `${quantity(row.predicted_units)} ${row.unit}` }}</td>
                  <td class="text-right">{{ row.trend === 'limited' ? '—' : money(row.predicted_revenue) }}</td>
                  <td class="text-right">{{ row.trend === 'limited' ? '—' : `${row.unit_slope_per_week > 0 ? '+' : ''}${quantity(row.unit_slope_per_week)}` }}</td>
                  <td><b-badge :variant="trendVariant(row.trend)">{{ trendLabel(row.trend) }}</b-badge></td>
                </tr>
                <tr v-if="!filteredProducts.length"><td colspan="6" class="text-center text-muted">No matching products.</td></tr>
              </tbody>
            </table>
          </div>
        </b-card>
      </template>
    </template>
  </div>
</template>

<script>
export default {
  metaInfo: { title: "Product Sales Analytics" },
  data() {
    return {
      historyWeeks: 12,
      horizonWeeks: 4,
      historyOptions: [4, 8, 12, 26].map(value => ({ value, text: `${value} weeks` })),
      horizonOptions: [2, 4, 8].map(value => ({ value, text: `${value} weeks` })),
      warehouseId: "",
      warehouses: [],
      currencySymbol: "₱",
      period: null,
      scope: { own_sales_only: false },
      summary: { products_with_sales: 0, trending_products: 0, limited_data_products: 0, forecastable_products: 0 },
      top: [], bottom: [], trending: [], products: [],
      selectedProductId: null,
      search: "",
      isLoading: true,
      error: "",
      requestNumber: 0
    };
  },
  computed: {
    selected() { return this.products.find(row => row.product_id === this.selectedProductId) || null; },
    filteredProducts() {
      const term = this.search.toLowerCase();
      return !term ? this.products : this.products.filter(row =>
        String(row.name).toLowerCase().includes(term) || String(row.code).toLowerCase().includes(term));
    },
    chartMax() {
      if (!this.selected) return 1;
      return Math.max(1, ...this.selected.weekly_units, ...(this.selected.trend === 'limited' ? [] : this.selected.forecast_units)) * 1.1;
    },
    splitX() { return this.chartX(this.historyWeeks - 1); },
    actualPoints() {
      return this.selected ? this.selected.weekly_units.map((value, index) => this.chartPoint(index, value)).join(" ") : "";
    },
    forecastPoints() {
      if (!this.selected) return "";
      const actual = this.selected.weekly_units;
      return [this.chartPoint(actual.length - 1, actual[actual.length - 1])]
        .concat(this.selected.forecast_units.map((value, index) => this.chartPoint(actual.length + index, value))).join(" ");
    }
  },
  mounted() { this.load(); },
  methods: {
    load() {
      const requestNumber = ++this.requestNumber;
      this.isLoading = true;
      this.error = "";
      const params = { history_weeks: this.historyWeeks, horizon_weeks: this.horizonWeeks };
      if (this.warehouseId !== "") params.warehouse_id = this.warehouseId;
      axios.get("report/product-sales-analytics", { params }).then(response => {
        if (requestNumber !== this.requestNumber) return;
        const data = response.data;
        this.period = data.period;
        this.scope = data.scope;
        this.warehouses = data.warehouses;
        this.currencySymbol = data.currency_symbol;
        this.summary = data.summary;
        this.top = data.top;
        this.bottom = data.bottom;
        this.trending = data.trending;
        this.products = data.products;
        if (!this.products.some(row => row.product_id === this.selectedProductId)) {
          this.selectedProductId = this.products.length ? this.products[0].product_id : null;
        }
      }).catch(error => {
        if (requestNumber !== this.requestNumber) return;
        this.error = error && error.message ? error.message : "Could not load product sales analytics.";
      }).finally(() => {
        if (requestNumber === this.requestNumber) this.isLoading = false;
      });
    },
    select(row) { this.selectedProductId = row.product_id; },
    quantity(value) { return Number(value || 0).toLocaleString("en-PH", { maximumFractionDigits: 2 }); },
    money(value) {
      return `${this.currencySymbol} ${Number(value || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    },
    trendLabel(trend) { return { rising: "Rising", falling: "Falling", steady: "Steady", limited: "Limited data" }[trend] || "Steady"; },
    trendVariant(trend) { return { rising: "success", falling: "danger", steady: "secondary", limited: "warning" }[trend] || "secondary"; },
    chartX(index) { return 45 + index * 685 / Math.max(1, this.historyWeeks + this.horizonWeeks - 1); },
    chartPoint(index, value) { return `${this.chartX(index).toFixed(1)},${(190 - Number(value) * 160 / this.chartMax).toFixed(1)}`; }
  }
};
</script>

<style scoped>
.rank-row { display: flex; gap: 0.7rem; align-items: center; padding: 0.6rem 0; border-bottom: 1px solid #edf0f2; cursor: pointer; }
.rank-row:hover, .product-row:hover { background: #f3faf7; }
.rank-row:last-child { border-bottom: 0; }
.rank-number { width: 1.5rem; color: #087a56; font-weight: 700; flex: none; }
.rank-name { flex: 1; min-width: 0; }
.rank-name strong { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rank-row small { display: block; color: #64748b; }
.forecast-chart { width: 100%; max-height: 260px; }
.product-row { cursor: pointer; }
</style>
