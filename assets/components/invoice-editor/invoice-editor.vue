<script>
import { unwrapApiEnvelope } from '@wexample/js-api-entity/Common/ApiEnvelope';
import { numberFormatMoney } from '@wexample/symfony-design-system/js/Helper/NumberHelper';
import InvoiceItemGrid from '../invoice-item-grid/invoice-item-grid.vue';

const T = 'WexampleSymfonyAccountingDsBundle.components.invoice-editor.invoice-editor::';

// A draft written in place, against the API of the books: its head saved as
// each field is left, its lines in the entity grid under it. What the server
// answers replaces what is shown — its totals, read again whenever a line
// changes, are the ones that count.
export default {
  template: '#vue-template-wexample-symfony-accounting-ds-bundle-components-invoice-editor-invoice-editor',

  components: {
    InvoiceItemGrid
  },

  props: {
    invoice: { type: String, required: true },
    ledger: { type: String, required: true },
    showUrl: { type: String, required: true },
    updateUrl: { type: String, required: true },
    pdfUrl: { type: String, required: true },
    // [{ id, name }]
    parties: { type: Array, default: () => [] },
    // In basis points, the first one the default.
    vatRates: { type: Array, default: () => [] },
    currency: { type: String, default: 'EUR' },
    locale: { type: String, default: null },
    quotation: { type: Boolean, default: false }
  },

  data() {
    return {
      document: null,
      // What the server computes of it: its amounts, the term it follows.
      computed: {},
      head: { party: '', title: '', note: '', dateInvoice: '', dateValidUntil: '', paymentTermDays: '', currencyCode: '', exchangeRate: '' },
      busy: false
    };
  },

  computed: {
    totals() {
      return {
        net: this.computed.amountNet ?? 0,
        vat: this.computed.amountVat ?? 0,
        total: this.computed.amountTotal ?? 0
      };
    }
  },

  async mounted() {
    await this.load();
  },

  methods: {
    t(key, parameters = {}) {
      return this.trans(`${T}${key}`, parameters);
    },

    // In the document's own currency, which may not be the ledger's.
    money(minor) {
      return numberFormatMoney(minor, this.document?.currencyCode || this.currency, this.locale);
    },

    // Another currency than the ledger's: its rate is asked for, or fixed at
    // emission when left empty.
    foreign() {
      return !!this.head.currencyCode && this.head.currencyCode !== this.currency;
    },

    async request(url, options = {}) {
      this.busy = true;

      try {
        const response = await fetch(url, { headers: { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json' } : {}) }, ...options });

        return unwrapApiEnvelope(await response.json());
      } catch (error) {
        this.app.getServiceOrFail('toast').show({ type: 'error', message: error.message });

        return null;
      } finally {
        this.busy = false;
      }
    },

    // What the server holds becomes what is shown: the document under
    // `entity`, what is computed of it under `metadata`.
    apply(data) {
      if (!data) {
        return false;
      }

      const document = data.entity ?? data;

      this.document = document;
      this.computed = data.metadata ?? {};
      this.head = {
        party: document.party ?? '',
        title: document.title ?? '',
        note: document.note ?? '',
        dateInvoice: document.dateInvoice ?? '',
        dateValidUntil: document.dateValidUntil ?? '',
        // Empty while it follows the party's or the ledger's term, shown as
        // the field's placeholder (`paymentTermDaysDefault`).
        paymentTermDays: document.paymentTermDays ?? '',
        currencyCode: document.currencyCode ?? this.currency,
        exchangeRate: document.exchangeRate ?? ''
      };

      return true;
    },

    async load() {
      this.apply(await this.request(this.showUrl));
    },

    // One field of the head, saved as it is left: only it is sent.
    async saveHead(field) {
      let value = this.head[field];

      if (field === 'currencyCode') {
        value = String(value).trim().toUpperCase() || null;
      } else if (field === 'party' || field === 'dateValidUntil' || field === 'title' || field === 'note' || field === 'exchangeRate') {
        value = value === '' ? null : String(value).replace(',', '.');
      } else if (field === 'paymentTermDays') {
        value = value === '' ? null : Number(value);
      }

      this.apply(await this.request(this.updateUrl, { method: 'POST', body: JSON.stringify({ [field]: value }) }));
    }
  }
};
</script>
