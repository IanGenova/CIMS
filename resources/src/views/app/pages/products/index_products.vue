<template>
  <div class="main-content">
    <breadcumb :page="$t('productsList')" :folder="$t('Products')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <div v-else>
      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="products"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
        @on-sort-change="onSortChange"
        @on-search="onSearch"
        :select-options="{ 
          enabled: true ,
          clearSelectionText: '',
        }"
        @on-selected-rows-change="selectionChanged"
        :search-options="{
          enabled: true,
          placeholder: $t('Search_this_table'),  
        }"
        :pagination-options="{
        enabled: true,
        mode: 'records',
        nextLabel: 'next',
        prevLabel: 'prev',
      }"
        styleClass="tableOne vgt-table"
      >
        <div slot="selected-row-actions">
          <button class="btn btn-danger" @click="delete_by_selected()">{{$t('Del')}}</button>
        </div>
        <div slot="table-actions" class="mt-2 mb-3">
          <b-button variant="outline-info m-1" size="sm" v-b-toggle.sidebar-right>
            <i class="i-Filter-2"></i>
            {{ $t("Filter") }}
          </b-button>
          <b-button @click="Product_PDF()" size="sm" variant="outline-success m-1">
            <i class="i-File-Copy"></i> PDF
          </b-button>
          <b-button @click="Product_Excel()" size="sm" variant="outline-danger m-1">
            <i class="i-File-Excel"></i> EXCEL
          </b-button>
          <b-button
            @click="Show_import_products()"
            size="sm"
            variant="info m-1"
            v-if="currentUserPermissions && currentUserPermissions.includes('product_import')"
          >
            <i class="i-Download"></i>
            {{ $t("import_products") }}
          </b-button>
          <router-link
            class="btn-sm btn btn-primary btn-icon m-1"
            v-if="currentUserPermissions && currentUserPermissions.includes('products_add')"
            to="/app/products/store"
          >
            <span class="ul-btn__icon">
              <i class="i-Add"></i>
            </span>
            <span class="ul-btn__text ml-1">{{$t('Add')}}</span>
          </router-link>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'actions'" class="product-actions">
            <router-link
              class="product-table-action"
              v-if="currentUserPermissions && currentUserPermissions.includes('products_view')"
              v-b-tooltip.hover
              title="View"
              :aria-label="'View ' + props.row.name"
              :to="{ name:'detail_product', params: { id: props.row.id} }"
            >
              <i class="i-Eye text-25 text-info"></i>
            </router-link>
            <router-link
              class="product-table-action"
              v-if="currentUserPermissions && currentUserPermissions.includes('products_edit')"
              v-b-tooltip.hover
              title="Edit"
              :aria-label="'Edit ' + props.row.name"
              :to="{ name:'edit_product', params: { id: props.row.id } }"
            >
              <i class="i-Edit text-25 text-success"></i>
            </router-link>
            <button type="button" class="product-table-action product-delete-action"
              v-if="currentUserPermissions && currentUserPermissions.includes('products_delete')"
              @click="Remove_Product(props.row.id)"
              v-b-tooltip.hover
              title="Delete"
              :aria-label="'Delete ' + props.row.name"
            >
              <i class="i-Close-Window text-25 text-danger"></i>
            </button>
          </span>
          <span v-else-if="props.column.field == 'image'">
            <b-img
              thumbnail
              height="50"
              width="50"
              fluid
              :src="'/images/products/' + props.row.image"
              alt="image"
            ></b-img>
          </span>
        </template>
      </vue-good-table>

      <!-- Multiple filter -->
      <b-sidebar id="sidebar-right" :title="$t('Filter')" bg-variant="white" right shadow>
        <div class="px-3 py-2">
          <b-row>
            <!-- Code Product  -->
            <b-col md="12">
              <b-form-group :label="$t('CodeProduct')">
                <b-form-input
                  label="Code Product"
                  :placeholder="$t('SearchByCode')"
                  v-model="Filter_code"
                ></b-form-input>
              </b-form-group>
            </b-col>

            <!-- Name  -->
            <b-col md="12">
              <b-form-group :label="$t('ProductName')">
                <b-form-input
                  label="Name Product"
                  :placeholder="$t('SearchByName')"
                  v-model="Filter_name"
                ></b-form-input>
              </b-form-group>
            </b-col>

            <!-- Category  -->
            <b-col md="12">
              <b-form-group :label="$t('Categorie')">
                <v-select
                  :reduce="label => label.value"
                  :placeholder="$t('Choose_Category')"
                  v-model="Filter_category"
                  :options="categories.map(categories => ({label: categories.name, value: categories.id}))"
                />
              </b-form-group>
            </b-col>

            <!-- Brand  -->
            <b-col md="12">
              <b-form-group :label="$t('Brand')">
                <v-select
                  :reduce="label => label.value"
                  :placeholder="$t('Choose_Brand')"
                  v-model="Filter_brand"
                  :options="brands.map(brands => ({label: brands.name, value: brands.id}))"
                />
              </b-form-group>
            </b-col>

            <b-col md="6" sm="12">
              <b-button
                @click="Get_Products(serverParams.page)"
                variant="primary m-1"
                size="sm"
                block
              >
                <i class="i-Filter-2"></i>
                {{ $t("Filter") }}
              </b-button>
            </b-col>

            <b-col md="6" sm="12">
              <b-button @click="Reset_Filter()" variant="danger m-1" size="sm" block>
                <i class="i-Power-2"></i>
                {{ $t("Reset") }}
              </b-button>
            </b-col>
          </b-row>
        </div>
      </b-sidebar>

      <!-- Modal Show Import Products -->
      <b-modal
        ok-only
        ok-title="Close"
        size="lg"
        dialog-class="cims-product-import-dialog"
        id="importProducts"
        :title="$t('import_products')"
        @hidden="resetImport"
      >
        <div>
          <p class="text-muted">Download the CSV template, fill one product per row, then preview before importing. No products are saved during preview.</p>
          <div class="d-flex flex-wrap align-items-center mb-3">
            <b-button href="/import/exemples/import_products.csv" variant="outline-info" size="sm" class="mr-3 mb-2">Download CSV template</b-button>
            <small class="text-muted mb-2">Required: name, code, category, cost, price, unit. Use existing categories, brands and unit short names.</small>
          </div>
          <b-form-file v-model="import_products" accept=".csv,text/csv" placeholder="Choose a CSV file (maximum 1 MB)" class="mb-3" @input="resetImportPreview" />
          <b-alert v-if="importFileError" show variant="danger">{{ importFileError }}</b-alert>
          <b-button variant="primary" :disabled="ImportProcessing || !import_products" @click="previewImport">
            {{ ImportProcessing ? 'Checking file...' : 'Preview and validate' }}
          </b-button>

          <div v-if="importPreview" class="mt-4">
            <b-alert show :variant="importPreview.error_count ? 'warning' : 'success'">
              {{ importPreview.total_rows }} {{ importPreview.total_rows === 1 ? 'row' : 'rows' }} checked · {{ importPreview.valid_rows }} valid · {{ importPreview.error_count }} {{ importPreview.error_count === 1 ? 'issue' : 'issues' }}.
              <span v-if="importPreview.error_count">Correct the CSV and preview it again. Nothing has been imported.</span>
            </b-alert>
            <div v-if="importPreview.errors.length" class="import-errors mb-3" role="alert">
              <div v-for="(issue, index) in importPreview.errors" :key="index">
                <strong>{{ issue.line ? `Row ${issue.line}` : 'File' }}:</strong> {{ issue.message }}
              </div>
              <small v-if="importPreview.error_count > importPreview.errors.length">Showing the first {{ importPreview.errors.length }} issues.</small>
            </div>
            <div v-if="importPreview.preview_rows.length" class="table-responsive">
              <table class="table table-sm table-bordered">
                <thead><tr><th>Row</th><th>Code</th><th>Name</th><th>Category</th><th>Unit</th><th>Cost</th><th>Price</th><th>Status</th></tr></thead>
                <tbody>
                  <tr v-for="row in importPreview.preview_rows" :key="row.line">
                    <td>{{ row.line }}</td><td>{{ row.code }}</td><td>{{ row.name }}</td><td>{{ row.category }}</td><td>{{ row.unit }}</td>
                    <td>{{ row.cost }}</td><td>{{ row.price }}</td>
                    <td><b-badge :variant="row.errors.length ? 'danger' : 'success'">{{ row.errors.length ? 'Fix' : 'Ready' }}</b-badge></td>
                  </tr>
                </tbody>
              </table>
              <small class="text-muted">Showing up to 20 rows.</small>
            </div>
            <b-button v-if="!importPreview.error_count && importPreview.total_rows" variant="success" class="mt-3" :disabled="ImportProcessing" @click="Submit_import">
              {{ ImportProcessing ? 'Importing...' : `Import ${importPreview.total_rows} ${importPreview.total_rows === 1 ? 'product' : 'products'}` }}
            </b-button>
          </div>

        </div>
      </b-modal>
    </div>
  </div>
</template>


<script>
import { mapActions, mapGetters } from "vuex";
import NProgress from "nprogress";
import jsPDF from "jspdf";
import "jspdf-autotable";

export default {
  metaInfo: {
    title: "Products"
  },

  data() {
    return {
      serverParams: {
        sort: {
          field: "id",
          type: "desc"
        },
        page: 1,
        perPage: 10
      },
      selectedIds: [],
      ImportProcessing:false,
      import_products: null,
      importPreview: null,
      importFileError: "",
      search: "",
      totalRows: "",
      isLoading: true,
      spinner: false,
      limit: "10",
      Filter_brand: "",
      Filter_code: "",
      Filter_name: "",
      Filter_category: "",
      categories: [],
      brands: [],
      products: {},
      warehouses: []
    };
  },

  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    columns() {
      return [
        {
          label: this.$t("image"),
          field: "image",
          type: "image",
          html: true,
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("Name_product"),
          field: "name",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("Code"),
          field: "code",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("Categorie"),
          field: "category",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("Brand"),
          field: "brand",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("Price"),
          field: "price",
          formatFn: this.money,
          type: "decimal",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("Unit"),
          field: "unit",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("Quantity"),
          field: "quantity",
          type: "decimal",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("Action"),
          field: "actions",
          html: true,
          tdClass: "text-right",
          thClass: "text-right",
          sortable: false
        }
      ];
    }
  },
  methods: {
    /** Money in list tables, shown the same way the detail pages show it. */
    money(value) {
      // currentUser arrives after the first render, so the symbol is optional.
      var symbol = (this.currentUser && this.currentUser.currency) || "";
      return (symbol ? symbol + " " : "") + Number(value || 0).toFixed(2);
    },

    //-------------------------------------- Products PDF ------------------------------\\
    Product_PDF() {
      var self = this;

      let pdf = new jsPDF("p", "pt");
      let columns = [
        { title: "name", dataKey: "name" },
        { title: "code", dataKey: "code" },
        { title: "category", dataKey: "category" },
        { title: "brand", dataKey: "brand" },
        { title: "price", dataKey: "price" },
        { title: "unit", dataKey: "unit" },
        { title: "quantity", dataKey: "quantity" }
      ];
      pdf.autoTable(columns, self.products);
      pdf.text("Product List", 40, 25);
      pdf.save("Product_List.pdf");
    },

    //----------------------------------- Show import products -------------------------------\\
    Show_import_products() {
      this.$bvModal.show("importProducts");
    },

    resetImportPreview() {
      this.importPreview = null;
      this.importFileError = "";
    },
    resetImport() {
      this.import_products = null;
      this.resetImportPreview();
    },
    importBody(preview) {
      const body = new FormData();
      body.append("products", this.import_products);
      if (preview) body.append("preview", "1");
      return body;
    },
    showImportError(error) {
      const response = error && error.response && error.response.data;
      if (response && response.preview_rows) {
        this.importPreview = response;
        return;
      }
      this.importFileError = response && response.message
        ? response.message
        : "Could not check this file. Please use a CSV smaller than 1 MB.";
    },
    previewImport() {
      this.resetImportPreview();
      if (!this.import_products) return;
      if (!/\.csv$/i.test(this.import_products.name) || this.import_products.size > 1048576) {
        this.importFileError = "Choose a CSV file smaller than 1 MB.";
        return;
      }
      this.ImportProcessing = true;
      axios.post("Products/import/csv", this.importBody(true))
        .then(response => { this.importPreview = response.data; })
        .catch(this.showImportError)
        .finally(() => { this.ImportProcessing = false; });
    },

    Submit_import() {
      if (!this.importPreview || this.importPreview.error_count || !this.importPreview.total_rows) return;
      NProgress.start();
      NProgress.set(0.1);
      this.ImportProcessing = true;
      axios
        .post("Products/import/csv", this.importBody(false))
        .then(response => {
          this.makeToast("success", `${response.data.imported} ${response.data.imported === 1 ? 'product' : 'products'} imported.`, this.$t("Success"));
          Fire.$emit("Event_import");
        })
        .catch(error => {
          this.showImportError(error);
          if (!this.importPreview) this.makeToast("danger", this.importFileError, this.$t("Failed"));
        })
        .finally(() => {
          this.ImportProcessing = false;
          NProgress.done();
        });
    },

    //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },

    //----Update Params Table
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    //---- Event Page Change
    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) {
        this.updateParams({ page: currentPage });
        this.Get_Products(currentPage);
      }
    },

    //---- Event Per Page Change
    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) {
        this.limit = currentPerPage;
        this.updateParams({ page: 1, perPage: currentPerPage });
        this.Get_Products(1);
      }
    },

    //---- Event Select Rows
    selectionChanged({ selectedRows }) {
      this.selectedIds = [];
      selectedRows.forEach((row, index) => {
        this.selectedIds.push(row.id);
      });
    },

    //---- Event Sort Change
    onSortChange(params) {
      let field = "";
      if (params[0].field == "brand") {
        field = "brand_id";
      } else if (params[0].field == "category") {
        field = "category_id";
      } else {
        field = params[0].field;
      }
      this.updateParams({
        sort: {
          type: params[0].type,
          field: field
        }
      });
      this.Get_Products(this.serverParams.page);
    },

    //---- Event Search
    onSearch(value) {
      this.search = value.searchTerm;
      this.Get_Products(this.serverParams.page);
    },

    //------ Reset Filter
    Reset_Filter() {
      this.search = "";
      this.Filter_brand = "";
      this.Filter_code = "";
      this.Filter_name = "";
      this.Filter_category = "";
      this.Get_Products(this.serverParams.page);
    },

    //------------------------------------ Products Excel ------------------------------\\
    Product_Excel() {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("Products/export/Excel", {
          responseType: "blob", // important
          headers: {
            "Content-Type": "application/json"
          }
        })
        .then(response => {
          const url = window.URL.createObjectURL(new Blob([response.data]));
          const link = document.createElement("a");
          link.href = url;
          link.setAttribute("download", "List_Products.xlsx");
          document.body.appendChild(link);
          link.click();
          // Complete the animation of theprogress bar.
          NProgress.done();
        })
        .catch(() => {
          // Complete the animation of theprogress bar.
          NProgress.done();
        });
    },

    // Simply replaces null values with strings=''
    setToStrings() {
      if (this.Filter_category === null) {
        this.Filter_category = "";
      } else if (this.Filter_brand === null) {
        this.Filter_brand = "";
      }
    },

    //----------------------------------- Get All Products ------------------------------\\
    Get_Products(page) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      this.setToStrings();
      axios
        .get(
          "Products?page=" +
            page +
            "&code=" +
            this.Filter_code +
            "&name=" +
            this.Filter_name +
            "&category_id=" +
            this.Filter_category +
            "&brand_id=" +
            this.Filter_brand +
            "&SortField=" +
            this.serverParams.sort.field +
            "&SortType=" +
            this.serverParams.sort.type +
            "&search=" +
            this.search +
            "&limit=" +
            this.limit
        )
        .then(response => {
          this.products = response.data.products;
          this.warehouses = response.data.warehouses;
          this.categories = response.data.categories;
          this.brands = response.data.brands;
          this.totalRows = response.data.totalRows;

          // Complete the animation of theprogress bar.
          NProgress.done();
          this.isLoading = false;
        })
        .catch(response => {
          // Complete the animation of theprogress bar.
          NProgress.done();
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    //----------------------------------- Remove Product ------------------------------\\
    Remove_Product(id) {
      this.$swal({
        title: this.$t("Delete.Title"),
        text: this.$t("Delete.Text"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete.cancelButtonText"),
        confirmButtonText: this.$t("Delete.confirmButtonText")
      }).then(result => {
        if (result.value) {
          // Start the progress bar.
          NProgress.start();
          NProgress.set(0.1);
          axios
            .delete("Products/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete.Deleted"),
                this.$t("Delete.ProductDeleted"),
                "success"
              );

              Fire.$emit("Delete_Product");
            })
            .catch(() => {
              // Complete the animation of theprogress bar.
              setTimeout(() => NProgress.done(), 500);
              this.$swal(
                this.$t("Delete.Failed"),
                this.$t("Delete.AlreadyLinked"),
                "warning"
              );
            });
        }
      });
    },

    //---- Delete products by selection
    delete_by_selected() {
      this.$swal({
        title: this.$t("Delete.Title"),
        text: this.$t("Delete.Text"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete.cancelButtonText"),
        confirmButtonText: this.$t("Delete.confirmButtonText")
      }).then(result => {
        if (result.value) {
          // Start the progress bar.
          NProgress.start();
          NProgress.set(0.1);
          axios
            .post("Products/delete/by_selection", {
              selectedIds: this.selectedIds
            })
            .then(() => {
              this.$swal(
                this.$t("Delete.Deleted"),
                this.$t("Delete.ProductDeleted"),
                "success"
              );

              Fire.$emit("Delete_Product");
            })
            .catch(() => {
              // Complete the animation of theprogress bar.
              setTimeout(() => NProgress.done(), 500);
              this.$swal(
                this.$t("Delete.Failed"),
                this.$t("Delete.Therewassomethingwronge"),
                "warning"
              );
            });
        }
      });
    }
  }, //end Methods

  //-----------------------------Created function-------------------

  created: function() {
    this.Get_Products(1);

    Fire.$on("Delete_Product", () => {
      this.Get_Products(this.serverParams.page);
      // Complete the animation of theprogress bar.
      setTimeout(() => NProgress.done(), 500);
    });

    Fire.$on("Event_import", () => {
      setTimeout(() => {
        this.Get_Products(this.serverParams.page);
        this.$bvModal.hide("importProducts");
      }, 500);
    });
  }
};
</script>

<style scoped>
.product-actions { display: inline-flex; align-items: center; gap: .25rem; }
.product-table-action { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; min-height: 36px; border-radius: .35rem; }
.product-table-action:hover, .product-table-action:focus { background: #edf5f2; outline: 2px solid #0b6f52; outline-offset: 1px; }
.product-delete-action { border: 0; background: transparent; cursor: pointer; }
.import-errors {
  max-height: 12rem;
  overflow-y: auto;
  padding: 0.75rem;
  border: 1px solid #f3c6c6;
  border-radius: 0.25rem;
  background: #fff8f8;
}
</style>

<style>
.modal-dialog.cims-product-import-dialog {
  max-width: 900px;
  width: calc(100% - 2rem);
}
</style>
