<template>
  <div class="main-content">
    <breadcumb :page="$t('SystemSettings')" :folder="$t('Settings')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <!-- System Settings -->
    <validation-observer ref="form_setting" v-if="!isLoading">
      <b-form @submit.prevent="Submit_Setting">
        <b-row>
          <b-col lg="12" md="12" sm="12">
            <b-card no-body :header="$t('SystemSettings')">
              <b-card-body>
                <b-row>
                  <!-- Default Currency -->
                  <b-col lg="4" md="4" sm="12">
                    <b-form-group :label="$t('DefaultCurrency')">
                      <v-select
                        v-model="setting.currency_id"
                        :reduce="label => label.value"
                        :placeholder="$t('Choose_Currency')"
                        :options="currencies.map(currencies => ({label: currencies.name, value: currencies.id}))"
                      />
                    </b-form-group>
                  </b-col>

                  <!-- Email  -->
                  <b-col lg="4" md="4" sm="12">
                    <validation-provider
                      name="Email"
                      :rules="{ required: true}"
                      v-slot="validationContext"
                    >
                      <b-form-group :label="$t('DefaultEmail')">
                        <b-form-input
                          :state="getValidationState(validationContext)"
                          aria-describedby="Email-feedback"
                          label="Email"
                          :placeholder="$t('DefaultEmail')"
                          v-model="setting.email"
                        ></b-form-input>
                        <b-form-invalid-feedback
                          id="Email-feedback"
                        >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                  <!-- - Logo -->
                  <b-col lg="4" md="4" sm="12">
                    <validation-provider name="Logo" ref="Logo" rules="mimes:image/*|size:200">
                      <b-form-group
                        slot-scope="{validate, valid, errors }"
                        :label="$t('ChangeLogo')"
                      >
                        <input
                          :state="errors[0] ? false : (valid ? true : null)"
                          :class="{'is-invalid': !!errors.length}"
                          @change="onFileSelected"
                          label="Choose Logo"
                          type="file"
                        >
                        <b-form-invalid-feedback id="Logo-feedback">{{ errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                  <!-- Company Name  -->
                  <b-col lg="4" md="4" sm="12">
                    <validation-provider
                      name="Company Name"
                      :rules="{ required: true}"
                      v-slot="validationContext"
                    >
                      <b-form-group :label="$t('CompanyName')">
                        <b-form-input
                          :state="getValidationState(validationContext)"
                          aria-describedby="Company-feedback"
                          label="Company Name"
                          :placeholder="$t('CompanyName')"
                          v-model="setting.CompanyName"
                        ></b-form-input>
                        <b-form-invalid-feedback
                          id="Company-feedback"
                        >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                  <!-- Company Phone  -->
                  <b-col lg="4" md="4" sm="12">
                    <validation-provider
                      name="Company Phone"
                      :rules="{ required: true}"
                      v-slot="validationContext"
                    >
                      <b-form-group :label="$t('CompanyPhone')">
                        <b-form-input
                          :state="getValidationState(validationContext)"
                          aria-describedby="Phone-feedback"
                          label="Company Phone"
                          :placeholder="$t('CompanyPhone')"
                          v-model="setting.CompanyPhone"
                        ></b-form-input>
                        <b-form-invalid-feedback
                          id="Phone-feedback"
                        >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                  <!-- developed by -->
                  <b-col lg="4" md="4" sm="12">
                    <validation-provider
                      name="developed by"
                      :rules="{ required: true}"
                      v-slot="validationContext"
                    >
                      <b-form-group :label="$t('developed_by')">
                         <b-form-input
                          :state="getValidationState(validationContext)"
                          aria-describedby="developed_by-feedback"
                          v-model="setting.developed_by"
                          class="form-control"
                        ></b-form-input>
                        <b-form-invalid-feedback
                          id="developed_by-feedback"
                        >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                   <!-- Footer -->
                  <b-col lg="4" md="4" sm="12">
                    <validation-provider
                      name="footer"
                      :rules="{ required: true}"
                      v-slot="validationContext"
                    >
                      <b-form-group :label="$t('footer')">
                         <b-form-input
                          :state="getValidationState(validationContext)"
                          aria-describedby="footer-feedback"
                          v-model="setting.footer"
                          class="form-control"
                        ></b-form-input>
                        <b-form-invalid-feedback
                          id="footer-feedback"
                        >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                   <!-- Default Language -->
                  <b-col lg="4" md="4" sm="12">
                    <validation-provider name="DefaultLanguage" :rules="{ required: true}">
                    <b-form-group slot-scope="{ valid, errors }" :label="$t('DefaultLanguage')">
                      <v-select
                        :class="{'is-invalid': !!errors.length}"
                        :state="errors[0] ? false : (valid ? true : null)"
                        v-model="setting.default_language"
                        :reduce="label => label.value"
                        :placeholder="$t('DefaultLanguage')"
                              :options="
                                  [
                                  {label: 'English', value: 'en'},
                                  {label: 'French', value: 'fr'},
                                  {label: 'Arabic', value: 'ar'},
                                  {label: 'Turkish', value: 'tur'},
                                  {label: 'Simplified Chinese', value: 'sm_ch'},
                                  {label: 'Thaï', value: 'thai'},
                                  {label: 'Hindi', value: 'hn'},
                                  {label: 'German', value: 'de'},
                                  {label: 'Spanish', value: 'es'},
                                  {label: 'Italien', value: 'it'},
                                  {label: 'Indonesian', value: 'Ind'},
                                  {label: 'Traditional Chinese', value: 'tr_ch'},
                                  {label: 'Russian', value: 'ru'},
                                  {label: 'Vietnamese', value: 'vn'},
                              ]"                     
                      ></v-select>
                        <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                         </b-form-group>
                    </validation-provider>
                  </b-col>

                  <!-- Purchases at or above this wait for a second person's approval. -->
                  <b-col lg="4" md="4" sm="12">
                    <b-form-group :label="$t('PurchaseApprovalLimit')">
                      <b-input-group prepend="₱">
                        <b-form-input
                          type="number"
                          min="0"
                          step="0.01"
                          v-model="setting.purchase_approval_threshold"
                          :placeholder="$t('ApprovalOff')"
                        ></b-form-input>
                      </b-input-group>
                      <small class="text-muted">{{ $t('PurchaseApprovalLimitHelp') }}</small>
                    </b-form-group>
                  </b-col>

                    <!-- Default Customer -->
                  <b-col lg="4" md="4" sm="12">
                    <b-form-group :label="$t('DefaultCustomer')">
                      <v-select
                        v-model="setting.client_id"
                        :reduce="label => label.value"
                        :placeholder="$t('Choose_Customer')"
                        :options="clients.map(clients => ({label: clients.name, value: clients.id}))"
                      />
                    </b-form-group>
                  </b-col>

                   <!-- Default Warehouse -->
                  <b-col lg="4" md="4" sm="12">
                    <b-form-group :label="$t('DefaultWarehouse')">
                      <v-select
                        v-model="setting.warehouse_id"
                        :reduce="label => label.value"
                        :placeholder="$t('Choose_Warehouse')"
                        :options="warehouses.map(warehouses => ({label: warehouses.name, value: warehouses.id}))"
                      />
                    </b-form-group>
                  </b-col>

                   <!-- Company Adress -->
                  <b-col lg="12" md="12" sm="12">
                    <validation-provider
                      name="Adress"
                      :rules="{ required: true}"
                      v-slot="validationContext"
                    >
                      <b-form-group :label="$t('Adress')">
                         <textarea
                          :state="getValidationState(validationContext)"
                          aria-describedby="Adress-feedback"
                          v-model="setting.CompanyAdress"
                          class="form-control"
                          :placeholder="$t('Afewwords')"
                         ></textarea>
                        <b-form-invalid-feedback
                          id="Adress-feedback"
                        >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>


                  <b-col md="12">
                    <b-form-group>
                      <b-button variant="primary" type="submit">{{$t('submit')}}</b-button>
                    </b-form-group>
                  </b-col>
                </b-row>
              </b-card-body>
            </b-card>
          </b-col>
        </b-row>
      </b-form>
    </validation-observer>


     <!-- Reciept Pos Settings -->
    <validation-observer ref="Submit_Pos_Settings" v-if="!isLoading">
      <b-form @submit.prevent="Submit_Pos_Settings">
        <b-row class="mt-5">
          <b-col lg="12" md="12" sm="12">
            <b-card no-body :header="$t('Pos_Settings')">
              <b-card-body>
                <b-row>

                   <!-- Note to customer  -->
                  <b-col lg="12" md="12" sm="12">
                    <validation-provider
                      name="note"
                      :rules="{ required: true}"
                      v-slot="validationContext"
                    >
                      <b-form-group :label="$t('Note_to_customer')">
                        <b-form-input
                          :state="getValidationState(validationContext)"
                          aria-describedby="note-feedback"
                          label="Note to customer"
                          :placeholder="$t('Note_to_customer')"
                          v-model="pos_settings.note_customer"
                        ></b-form-input>
                        <b-form-invalid-feedback
                          id="note-feedback"
                        >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                 

                   <!-- Show Phone-->
                    <b-col md="4" class="mt-3 mb-3">
                       <label class="switch switch-primary mr-3">
                         {{$t('Show_Phone')}}
                          <input  type="checkbox" v-model="pos_settings.show_phone">
                          <span class="slider"></span>
                        </label>
                    </b-col>

                     <!-- Show Address -->
                    <b-col md="4" class="mt-3 mb-3">
                       <label class="switch switch-primary mr-3">
                         {{$t('Show_Address')}}
                          <input  type="checkbox" v-model="pos_settings.show_address">
                          <span class="slider"></span>
                        </label>
                    </b-col>

                      <!-- Show Email  -->
                    <b-col md="4" class="mt-3 mb-3">
                       <label class="switch switch-primary mr-3">
                         {{$t('Show_Email')}}
                          <input  type="checkbox" v-model="pos_settings.show_email">
                          <span class="slider"></span>
                        </label>
                    </b-col>

                    <!-- Show Customer  -->
                    <b-col md="4" class="mt-3 mb-3">
                       <label class="switch switch-primary mr-3">
                         {{$t('Show_Customer')}}
                          <input  type="checkbox" v-model="pos_settings.show_customer">
                          <span class="slider"></span>
                        </label>
                    </b-col>

                     <!-- Show Tax & Discount  -->
                    <b-col md="4" class="mt-3 mb-3">
                       <label class="switch switch-primary mr-3">
                         {{$t('Show_Tax_and_Discount')}}
                          <input  type="checkbox" v-model="pos_settings.show_discount">
                          <span class="slider"></span>
                        </label>
                    </b-col>

                     <!-- Show barcode  -->
                    <b-col md="4" class="mt-3 mb-3">
                       <label class="switch switch-primary mr-3">
                         {{$t('Show_barcode')}}
                          <input  type="checkbox" v-model="pos_settings.show_barcode">
                          <span class="slider"></span>
                        </label>
                    </b-col>

                      <!-- Show Note_to_customer  -->
                    <b-col md="4" class="mt-3 mb-3">
                       <label class="switch switch-primary mr-3">
                         {{$t('Show_Note_to_customer')}}
                          <input  type="checkbox" v-model="pos_settings.show_note">
                          <span class="slider"></span>
                        </label>
                    </b-col>

                    <!-- Receipt printing. The paper size matters most: an 80mm
                         layout sent to a 58mm roll loses its right-hand column,
                         which is where every figure sits. -->
                    <b-col md="4" class="mt-3">
                      <b-form-group :label="$t('ReceiptPaperSize')">
                        <b-form-select v-model="pos_settings.paper_size">
                          <option value="58mm">58 mm ({{$t('ThermalRoll')}})</option>
                          <option value="80mm">80 mm ({{$t('ThermalRoll')}})</option>
                          <option value="a4">A4</option>
                        </b-form-select>
                      </b-form-group>
                    </b-col>

                    <b-col md="4" class="mt-3">
                      <b-form-group :label="$t('PrinterName')">
                        <b-form-input v-model="pos_settings.printer_name"
                          :placeholder="$t('PrinterNamePlaceholder')"></b-form-input>
                        <small class="text-muted">{{$t('PrinterNameHelp')}}</small>
                      </b-form-group>
                    </b-col>

                    <b-col md="4" class="mt-3 mb-3">
                      <label class="switch switch-primary mr-3">
                        {{$t('AutoPrintReceipt')}}
                        <input type="checkbox" v-model="pos_settings.auto_print">
                        <span class="slider"></span>
                      </label>
                      <br>
                      <label class="switch switch-primary mr-3 mt-2">
                        {{$t('OpenCashDrawer')}}
                        <input type="checkbox" v-model="pos_settings.open_cash_drawer">
                        <span class="slider"></span>
                      </label>
                      <br>
                      <label class="switch switch-primary mr-3 mt-2">
                        {{$t('AutoCutPaper')}}
                        <input type="checkbox" v-model="pos_settings.auto_cut">
                        <span class="slider"></span>
                      </label>
                      <br>
                      <label class="switch switch-primary mr-3 mt-2">
                        {{$t('RequireTillSession')}}
                        <input type="checkbox" v-model="pos_settings.require_till_session">
                        <span class="slider"></span>
                      </label>
                      <small class="d-block text-muted mt-1">{{$t('RequireTillSessionHint')}}</small>
                    </b-col>

                    <!-- Which pin carries the drawer pulse is not standardised.
                         Most are pin 2; some are pin 5. Guessing wrong means the
                         drawer simply never opens. -->
                    <b-col md="4" class="mt-3" v-if="pos_settings.open_cash_drawer">
                      <b-form-group :label="$t('DrawerPin')">
                        <b-form-select v-model="pos_settings.drawer_pin">
                          <option :value="0">{{$t('DrawerPin2')}}</option>
                          <option :value="1">{{$t('DrawerPin5')}}</option>
                        </b-form-select>
                      </b-form-group>
                    </b-col>

                    <b-col md="4" class="mt-3" v-if="pos_settings.open_cash_drawer">
                      <b-form-group :label="$t('TestHardware')">
                        <b-button variant="outline-primary" size="sm"
                          @click="testCashDrawer()">{{$t('TestCashDrawer')}}</b-button>
                        <br>
                        <small class="text-muted">{{$t('TestCashDrawerHelp')}}</small>
                      </b-form-group>
                    </b-col>

                    <b-col md="12" class="mb-2">
                      <b-alert show variant="light" class="border">
                        <small>{{$t('PrintingHelp')}}</small>
                      </b-alert>
                    </b-col>

                  <b-col md="12">
                    <b-form-group>
                      <b-button variant="primary" type="submit">{{$t('submit')}}</b-button>
                    </b-form-group>
                  </b-col>
                </b-row>
              </b-card-body>
            </b-card>
          </b-col>
        </b-row>
      </b-form>
    </validation-observer>

    <!-- Payment Gateway -->
    <validation-observer ref="form_payment" v-if="!isLoading">
      <b-form @submit.prevent="Submit_Payment">
        <b-row class="mt-5">
          <b-col lg="12" md="12" sm="12">
            <b-card no-body :header="$t('Payment_Gateway') + ' — Xendit'">
              <b-card-body>
                <!-- Where it stands, in one line. -->
                <p class="mb-3">
                  <b-badge v-if="gateway.mode === 'live'" variant="success">{{$t('XenditLive')}}</b-badge>
                  <b-badge v-else-if="gateway.mode === 'test'" variant="warning">{{$t('XenditTestMode')}}</b-badge>
                  <b-badge v-else variant="secondary">{{$t('XenditNotSetUp')}}</b-badge>
                  <span class="text-muted ml-2 text-small">{{$t('XenditExplain')}}</span>
                </p>
                <b-row>
                  <!-- Secret API key: write-only. Once saved it is never sent back. -->
                  <b-col lg="6" md="6" sm="12">
                    <b-form-group :label="$t('XenditSecretKey')">
                      <b-form-input
                        type="password"
                        autocomplete="new-password"
                        v-model="gateway.secret_key"
                        :placeholder="gateway.secret_key_hint ? $t('SavedEndingIn', {hint: gateway.secret_key_hint}) : 'xnd_development_…'"
                      ></b-form-input>
                      <small class="text-muted">{{$t('XenditSecretKeyHelp')}}</small>
                    </b-form-group>
                  </b-col>

                  <!-- Webhook verification token: proves a "paid" message really came from Xendit. -->
                  <b-col lg="6" md="6" sm="12">
                    <b-form-group :label="$t('XenditWebhookToken')">
                      <b-form-input
                        type="password"
                        autocomplete="new-password"
                        v-model="gateway.webhook_token"
                        :placeholder="gateway.has_webhook_token ? $t('SavedLeaveBlank') : ''"
                      ></b-form-input>
                      <small class="text-muted">{{$t('XenditWebhookTokenHelp')}}</small>
                    </b-form-group>
                  </b-col>

                  <b-col md="12" v-if="gateway.webhook_url">
                    <b-form-group :label="$t('XenditWebhookUrl')">
                      <b-input-group>
                        <b-form-input readonly :value="gateway.webhook_url"></b-form-input>
                        <b-input-group-append>
                          <b-button variant="outline-primary" @click="copyWebhookUrl">{{$t('Copy')}}</b-button>
                        </b-input-group-append>
                      </b-input-group>
                      <small class="text-muted">{{$t('XenditWebhookUrlHelp')}}</small>
                    </b-form-group>
                  </b-col>

                  <b-col md="6" class="mt-2 mb-3" v-if="gateway.has_secret_key">
                    <label class="switch switch-primary mr-3">
                      {{$t('XenditDisconnect')}}
                      <input type="checkbox" v-model="gateway.remove">
                      <span class="slider"></span>
                    </label>
                  </b-col>

                  <b-col md="12">
                    <b-form-group>
                      <b-button variant="primary" type="submit" :disabled="gatewaySaving">{{$t('submit')}}</b-button>
                      <b-button
                        v-if="gateway.has_secret_key"
                        variant="outline-secondary"
                        class="ml-2"
                        :disabled="gatewayTesting"
                        @click="Test_Payment_Gateway"
                      >{{$t('XenditTestConnection')}}</b-button>
                    </b-form-group>
                  </b-col>
                </b-row>
              </b-card-body>
            </b-card>
          </b-col>
        </b-row>
      </b-form>
    </validation-observer>

    <!-- SMS API -->
    <validation-observer ref="form_sms" v-if="!isLoading">
      <b-form @submit.prevent="Submit_sms">
        <b-row class="mt-5">
          <b-col lg="12" md="12" sm="12">
            <b-card no-body :header="$t('SMS_Configuration')">
              <b-card-body>
                <b-row>

                  <!-- Gateway  -->
                <b-col md="6">
                  <validation-provider name="Gateway">
                    <b-form-group :label="$t('Gateway')">
                      <v-select
                        v-model="sms.gateway"
                        :reduce="label => label.value"
                        :placeholder="$t('Choose_Gateway')"
                        :options="
                                [
                                  {label: 'Twilio', value: 'Twilio'},
                                ]"
                      ></v-select>
                    </b-form-group>
                  </validation-provider>
                </b-col>
                  
                   <!-- TWILIO_SID  -->
                  <b-col lg="6" md="6" sm="12">
                    <validation-provider
                      name="TWILIO_SID"
                      :rules="{ required: true}"
                      v-slot="validationContext"
                    >
                      <b-form-group label="TWILIO_SID">
                        <b-form-input
                          :state="getValidationState(validationContext)"
                          aria-describedby="TWILIO_SID-feedback"
                          label="TWILIO_SID"
                          v-model="sms.TWILIO_SID"
                        ></b-form-input>
                        <b-form-invalid-feedback
                          id="TWILIO_SID-feedback"
                        >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                   <!-- TWILIO_TOKEN  -->
                  <b-col lg="6" md="6" sm="12">
                      <b-form-group label="TWILIO_TOKEN">
                        <b-form-input
                          label="TWILIO_TOKEN"
                          v-model="sms.TWILIO_TOKEN"
                          :placeholder="$t('LeaveBlank')"
                        ></b-form-input>
                      </b-form-group>
                  </b-col>

                    <!-- TWILIO_FROM  -->
                  <b-col lg="6" md="6" sm="12">
                    <validation-provider
                      name="TWILIO_FROM"
                      :rules="{ required: true}"
                      v-slot="validationContext"
                    >
                      <b-form-group label="TWILIO_FROM">
                        <b-form-input
                          :state="getValidationState(validationContext)"
                          aria-describedby="TWILIO_FROM-feedback"
                          label="TWILIO_FROM"
                          v-model="sms.TWILIO_FROM"
                        ></b-form-input>
                        <b-form-invalid-feedback
                          id="TWILIO_FROM-feedback"
                        >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                  <b-col md="12">
                    <b-form-group>
                      <b-button variant="primary" type="submit">{{$t('submit')}}</b-button>
                    </b-form-group>
                  </b-col>
                </b-row>
              </b-card-body>
            </b-card>
          </b-col>
        </b-row>
      </b-form>
    </validation-observer>

    <!-- Server SMTP -->
    <validation-observer ref="form_smtp" v-if="!isLoading">
      <b-form @submit.prevent="Submit_SMTP">
        <b-row class="mt-5">
          <b-col lg="12" md="12" sm="12">
            <b-card no-body :header="$t('SMTPConfiguration')">
              <b-card-body>
                <b-row>
                  <!-- HOST  -->
                  <b-col lg="4" md="4" sm="12">
                    <validation-provider
                      name="HOST"
                      :rules="{ required: true}"
                      v-slot="validationContext"
                    >
                      <b-form-group :label="$t('HOST')">
                        <b-form-input
                          :state="getValidationState(validationContext)"
                          aria-describedby="HOST-feedback"
                          label="HOST"
                          :placeholder="$t('HOST')"
                          v-model="server.host"
                        ></b-form-input>
                        <b-form-invalid-feedback
                          id="HOST-feedback"
                        >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                  <!-- PORT  -->
                  <b-col lg="4" md="4" sm="12">
                    <validation-provider
                      name="PORT"
                      :rules="{ required: true}"
                      v-slot="validationContext"
                    >
                      <b-form-group :label="$t('PORT')">
                        <b-form-input
                          :state="getValidationState(validationContext)"
                          aria-describedby="PORT-feedback"
                          label="PORT"
                          :placeholder="$t('PORT')"
                          v-model="server.port"
                        ></b-form-input>
                        <b-form-invalid-feedback
                          id="PORT-feedback"
                        >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                  <!-- Username  -->
                  <b-col lg="4" md="4" sm="12">
                    <validation-provider
                      name="Username"
                      :rules="{ required: true}"
                      v-slot="validationContext"
                    >
                      <b-form-group :label="$t('username')">
                        <b-form-input
                          :state="getValidationState(validationContext)"
                          aria-describedby="Username-feedback"
                          label="Username"
                          :placeholder="$t('username')"
                          v-model="server.username"
                        ></b-form-input>
                        <b-form-invalid-feedback
                          id="Username-feedback"
                        >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                  <!-- Password  -->
                  <b-col lg="4" md="4" sm="12">
                    <!-- The saved password is never sent here; blank keeps it. -->
                    <validation-provider
                      name="Password"
                      :rules="{ required: !server.has_password }"
                      v-slot="validationContext"
                    >
                      <b-form-group :label="$t('password')">
                        <b-form-input
                          :state="getValidationState(validationContext)"
                          aria-describedby="Password-feedback"
                          label="Password"
                          type="password"
                          autocomplete="new-password"
                          :placeholder="server.has_password ? $t('SavedLeaveBlank') : $t('password')"
                          v-model="server.password"
                        ></b-form-input>
                        <b-form-invalid-feedback
                          id="Password-feedback"
                        >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                  <!-- encryption  -->
                  <b-col lg="4" md="4" sm="12">
                    <validation-provider
                      name="encryption"
                      :rules="{ required: true}"
                      v-slot="validationContext"
                    >
                      <b-form-group :label="$t('encryption')">
                        <b-form-input
                          :state="getValidationState(validationContext)"
                          aria-describedby="encryption-feedback"
                          label="encryption"
                          :placeholder="$t('encryption')"
                          v-model="server.encryption"
                        ></b-form-input>
                        <b-form-invalid-feedback
                          id="encryption-feedback"
                        >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                      </b-form-group>
                    </validation-provider>
                  </b-col>

                  <b-col md="12">
                    <b-form-group>
                      <b-button variant="primary" type="submit">{{$t('submit')}}</b-button>
                    </b-form-group>
                  </b-col>
                </b-row>
              </b-card-body>
            </b-card>
          </b-col>
        </b-row>
      </b-form>
    </validation-observer>

    <!-- Clear Cache -->
      <b-form @submit.prevent="Clear_Cache" v-if="!isLoading">
        <b-row class="mt-5">
          <b-col lg="12" md="12" sm="12">
            <b-card no-body :header="$t('Clear_Cache')">
              <b-card-body>
                <b-row>

                  <b-col md="12">
                    <b-form-group>
                      <b-button variant="primary" @click="Clear_Cache()" >{{$t('Clear_Cache')}}</b-button>
                    </b-form-group>
                  </b-col>
                </b-row>
              </b-card-body>
            </b-card>
          </b-col>
        </b-row>
      </b-form>
  </div>
</template>

<script>
import { mapActions, mapGetters } from "vuex";
import NProgress from "nprogress";

export default {
  metaInfo: {
    title: "System Settings"
  },
  data() {
    return {
      
      isLoading: true,
      data: new FormData(),
      settings: [],
      currencies: [],
      clients: [],
      warehouses: [],
      setting: {
        client_id: "",
        warehouse_id: "",
        currency_id: "",
        email: "",
        logo: "",
        CompanyName: "",
        CompanyPhone: "",
        CompanyAdress: "",
        footer:"",
        developed_by:"",
        default_language:"",
        purchase_approval_threshold: null,
      },

      pos_settings:{
        note_customer:"",
        show_note:"",
        show_barcode:"",
        show_discount:"",
        show_phone:"",
        show_email:"",
        show_address:"",
        show_customer:"",
      },

      gateway:{
        secret_key:"",
        webhook_token:"",
        remove:false,
        has_secret_key:false,
        secret_key_hint:"",
        has_webhook_token:false,
        mode:null,
        webhook_url:"",
      },
      gatewaySaving: false,
      gatewayTesting: false,
      sms:{
        gateway:'Twilio',
        TWILIO_SID:'',
        TWILIO_TOKEN:'',
        TWILIO_FROM:'',
      },
      server: {
        host: "",
        port: "",
        username: "",
        password: "",
        encryption: "",
        has_password: false
      }
    };
  },

  methods: {
    ...mapActions(["refreshUserPermissions"]),

    //------------------- Cash drawer test (QZ Tray) -------------------\\
    //
    // Fires the drawer pulse on its own so the hardware can be proved from the
    // settings screen, rather than by ringing up a sale and hoping. It is the
    // same command the till sends after a cash sale, so a drawer that opens here
    // will open there.
    //
    // Written as hex rather than JavaScript escapes: "\x1B" is one editing slip
    // away from becoming the literal text backslash-x-1-B, which prints as
    // gibberish and opens nothing -- and you only find out in front of a queue.
    drawerCommand() {
      const pin = Number(this.pos_settings.drawer_pin) === 1 ? "01" : "00";

      return "1B70" + pin + "19FA";
    },

    loadQz() {
      if (typeof window.qz !== "undefined") {
        return Promise.resolve(window.qz);
      }
      if (this._qzLoading) {
        return this._qzLoading;
      }

      this._qzLoading = new Promise((resolve, reject) => {
        const el = document.createElement("script");
        el.src = "/js/vendor/qz-tray.js";
        el.onload = () => resolve(window.qz);
        el.onerror = () => reject(new Error("qz-tray.js could not be loaded"));
        document.head.appendChild(el);
      });

      return this._qzLoading;
    },

    testCashDrawer() {
      const name = this.pos_settings.printer_name;

      if (!name) {
        this.makeToast("warning", this.$t("PrinterNameRequired"), this.$t("Warning"));

        return;
      }

      this.loadQz()
        .then(qz => (qz.websocket.isActive()
          ? Promise.resolve(qz)
          : qz.websocket.connect({ retries: 1, delay: 1 }).then(() => qz)))
        .then(qz => qz.printers.find(name).then(found => ({ qz, found })))
        .then(({ qz, found }) => qz.print(qz.configs.create(found), [
          { type: "raw", format: "hex", data: this.drawerCommand() }
        ]))
        .then(() => this.makeToast("success", this.$t("CashDrawerOpened"), this.$t("Success")))
        .catch(err => {
          this.makeToast("warning",
            this.$t("PrintAgentFailed") + " " + (err && err.message ? err.message : err),
            this.$t("Warning"));
        });
    },


      SetLocal(locale) {
      this.$i18n.locale = locale;
      this.$store.dispatch("language/setLanguage", locale);
      Fire.$emit("ChangeLanguage");
    },

    //------------- Submit Validation Setting
    Submit_Setting() {
      this.$refs.form_setting.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Update_Settings();
        }
      });
    },


     //------------- Submit Validation Pos Setting
    Submit_Pos_Settings() {
      this.$refs.Submit_Pos_Settings.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Update_Pos_Settings();
        }
      });
    },

    //------------- Submit Validation SMTP
    Submit_SMTP() {
      this.$refs.form_smtp.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
            this.Update_Smtp();
        }
      });
    },

     //------------- Submit Validation Payment
    Submit_Payment() {
      this.$refs.form_payment.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
            this.Update_Payment();
        }
      });
    },

      //------------- Submit Validation SMS
    Submit_sms() {
      this.$refs.form_sms.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
            this.Update_SMS();
        }
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

    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    //------------------------------ Event Upload Logo -------------------------------\\
    async onFileSelected(e) {
      const { valid } = await this.$refs.Logo.validate(e);

      if (valid) {
        this.setting.logo = e.target.files[0];
      } else {
        this.setting.logo = "";
      }
    },


  
    //---------------------------------- Update Settings ----------------\\
    Update_Settings() {
      NProgress.start();
      NProgress.set(0.1);
      var self = this;
      self.data.append("client", self.setting.client_id);
      self.data.append("warehouse", self.setting.warehouse_id);
      self.data.append("currency", self.setting.currency_id);
      self.data.append("email", self.setting.email);
      self.data.append("logo", self.setting.logo);
      self.data.append("CompanyName", self.setting.CompanyName);
      self.data.append("CompanyPhone", self.setting.CompanyPhone);
      self.data.append("CompanyAdress", self.setting.CompanyAdress);
      self.data.append("footer", self.setting.footer);
      self.data.append("developed_by", self.setting.developed_by);
      self.data.append("default_language", self.setting.default_language);
      // Blank switches purchase approval off.
      const limit = self.setting.purchase_approval_threshold;
      self.data.append("purchase_approval_threshold", limit === null || limit === undefined ? "" : limit);
      self.data.append("_method", "put");

      axios
        .post("settings/" + self.setting.id, self.data)
        .then(response => {
          Fire.$emit("Event_Setting");
          this.makeToast(
            "success",
            this.$t("Successfully_Updated"),
            this.$t("Success")
          );
          this.refreshUserPermissions();
          NProgress.done();
          this.SetLocal(self.setting.default_language);
        })
        .catch(error => {
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
          NProgress.done();
        });
    }, 


      //---------------------------------- Update_Pos_Settings ----------------\\
    Update_Pos_Settings() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .put("pos_settings/" + this.pos_settings.id, {
          note_customer: this.pos_settings.note_customer,
          show_note: this.pos_settings.show_note,
          show_barcode: this.pos_settings.show_barcode,
          show_discount: this.pos_settings.show_discount,
          show_phone: this.pos_settings.show_phone,
          show_email: this.pos_settings.show_email,
          show_address: this.pos_settings.show_address,
          show_customer: this.pos_settings.show_customer,
          paper_size: this.pos_settings.paper_size,
          auto_print: this.pos_settings.auto_print,
          printer_name: this.pos_settings.printer_name,
          open_cash_drawer: this.pos_settings.open_cash_drawer,
          drawer_pin: this.pos_settings.drawer_pin,
          auto_cut: this.pos_settings.auto_cut,
          require_till_session: this.pos_settings.require_till_session,
        })
        .then(response => {
          Fire.$emit("Event_Pos_Settings");
          this.makeToast(
            "success",
            this.$t("Successfully_Updated"),
            this.$t("Success")
          );
          NProgress.done();
        })
        .catch(error => {
          NProgress.done();
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    //---------------------------------- Update SMTP ----------------\\
    Update_Smtp() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .put("SMTP/" + this.server.id, {
          host: this.server.host,
          port: this.server.port,
          username: this.server.username,
          password: this.server.password,
          encryption: this.server.encryption
        })
        .then(response => {
          Fire.$emit("Event_Smtp");
          this.makeToast(
            "success",
            this.$t("Successfully_Updated"),
            this.$t("Success")
          );
          NProgress.done();
        })
        .catch(error => {
          NProgress.done();
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    //---------------------------------- Update Payment Gateway ----------------\\
    Update_Payment() {
      NProgress.start();
      NProgress.set(0.1);
      this.gatewaySaving = true;
      axios
        .post("payment_gateway",{
          secret_key: this.gateway.secret_key || null,
          webhook_token: this.gateway.webhook_token || null,
          remove: !!this.gateway.remove,
        })
        .then(response => {
          this.gatewaySaving = false;
          this.Get_Payment_Gateway();
          this.makeToast(
            "success",
            this.$t("Successfully_Updated"),
            this.$t("Success")
          );
          NProgress.done();
        })
        .catch(error => {
          this.gatewaySaving = false;
          NProgress.done();
          this.makeToast("danger", (error && error.message) || this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    // Asks Xendit whether the saved key works, without moving any money.
    Test_Payment_Gateway() {
      this.gatewayTesting = true;
      axios
        .post("payment_gateway/test", {})
        .then(response => {
          this.gatewayTesting = false;
          this.makeToast("success", response.data.message, "Xendit");
        })
        .catch(error => {
          this.gatewayTesting = false;
          this.makeToast("danger", (error && error.message) || this.$t("Failed"), "Xendit");
        });
    },

    copyWebhookUrl() {
      const done = () => this.makeToast("success", this.$t("Copied"), "Xendit");
      try {
        navigator.clipboard.writeText(this.gateway.webhook_url).then(done, () => {});
      } catch (e) {
        // Older browsers: the field is selectable by hand.
      }
    },

     //---------------------------------- Update sms ----------------\\
    Update_SMS() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .post("sms_config",{
          TWILIO_SID: this.sms.TWILIO_SID,
          TWILIO_TOKEN: this.sms.TWILIO_TOKEN,
          TWILIO_FROM: this.sms.TWILIO_FROM,
        })
        .then(response => {
          Fire.$emit("Event_sms");
          this.makeToast(
            "success",
            this.$t("Successfully_Updated"),
            this.$t("Success")
          );
          NProgress.done();
        })
        .catch(error => {
          NProgress.done();
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

     //---------------------------------- get_sms_config ----------------\\
    get_sms_config() {
      axios
        .get("get_sms_config")
        .then(response => {
          this.sms = response.data.sms;
        })
        .catch(error => {
        });
    },   


    //---------------------------------- Clear_Cache ----------------\\
    Clear_Cache() {
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get("Clear_Cache")
        .then(response => {
          this.makeToast(
            "success",
            this.$t("Cache_cleared_successfully"),
            this.$t("Success")
          );
          NProgress.done();
        })
        .catch(error => {
          NProgress.done();
          this.makeToast("danger", this.$t("Failed_to_clear_cache"), this.$t("Failed"));
        });
    },   

     //---------------------------------- GET Payment_Gateway ----------------\\
    Get_Payment_Gateway() {
      axios
        .get("Get_payment_gateway")
        .then(response => {
          // The keys themselves never come back; the boxes start empty.
          this.gateway = Object.assign({ secret_key: "", webhook_token: "", remove: false }, response.data.gateway);
        })
        .catch(error => {
        });
    },

    //---------------------------------- Get SETTINGS ----------------\\
    Get_Settings() {
      axios
        .get("getSettings")
        .then(response => {
          this.setting    = response.data.settings;
          this.currencies = response.data.currencies;
          this.clients    = response.data.clients;
          this.warehouses = response.data.warehouses;
          this.Get_SMTP();
          this.get_pos_Settings();
          this.Get_Payment_Gateway();
          this.get_sms_config();
          this.isLoading = false;
        })
        .catch(error => {
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    //---------------------------------- GET SMTP ----------------\\ 
    Get_SMTP() {
      axios
        .get("getSMTP")
        .then(response => {
          // The password itself never comes back; the box starts empty.
          this.server = Object.assign({ password: "" }, response.data.server);
        })
        .catch(error => {
        });
    },


 //---------------------------------- Get_pos_Settings ----------------\\ 
    get_pos_Settings() {
      axios
        .get("get_pos_Settings")
        .then(response => {
          this.pos_settings = response.data.pos_settings;
        })
        .catch(error => {
        });
    },

   
  }, //end Methods

  //----------------------------- Created function-------------------

  created: function() {
    this.Get_Settings();

    Fire.$on("Event_Smtp", () => {
      this.Get_SMTP(); 
    });

     Fire.$on("Event_payment", () => {
      this.Get_Payment_Gateway();
    });

    Fire.$on("Event_Setting", () => {
      this.Get_Settings();
    });

     Fire.$on("Event_Pos_Settings", () => {
      this.get_pos_Settings();
    });

    Fire.$on("Event_sms", () => {
      this.get_sms_config();
    });
  }
};
</script>