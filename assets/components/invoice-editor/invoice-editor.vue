<script>
import { unwrapApiEnvelope } from '@wexample/js-api-entity/Common/ApiEnvelope';
import { numberFormatMoney, numberParse } from '@wexample/symfony-design-system/js/Helper/NumberHelper';

const T = 'WexampleSymfonyAccountingDsBundle.components.invoice-editor.invoice-editor::';

// Quantities are kept scaled by 100, prices in minor units, VAT rates in
// basis points: what the books store, what the API takes.
const SCALE = 100;

// The fields a column of a pasted or dropped table may feed.
const IMPORT_FIELDS = ['title', 'description', 'quantity', 'unitPrice', 'vatRate'];

// A draft written in place, against the API of the books: its head saved as
// each field is left, its lines added one by one — the line being typed shows
// what it comes to before it is sent —, or many at once from a table pasted
// out of a spreadsheet or a file dropped, whose columns the reader names.
// Everything the server answers replaces what is shown: its totals are the
// ones that count.
export default {
  template: '#vue-template-wexample-symfony-accounting-ds-bundle-components-invoice-editor-invoice-editor',

  props: {
    invoice: { type: String, required: true },
    ledger: { type: String, required: true },
    showUrl: { type: String, required: true },
    updateUrl: { type: String, required: true },
    itemCreateUrl: { type: String, required: true },
    // Holds `__id__`, where the line's id goes.
    itemDeleteUrl: { type: String, required: true },
    importUrl: { type: String, required: true },
    readTableUrl: { type: String, required: true },
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
      head: { party: '', title: '', note: '', dateInvoice: '', dateValidUntil: '', paymentTermDays: '', currencyCode: '', exchangeRate: '' },
      line: this.emptyLine(),
      // The line being changed: sent again, then the old one removed.
      replacing: null,
      busy: false,
      // A table waiting for its columns: { rows, columns: [field|''], header }.
      table: null
    };
  },

  computed: {
    items() {
      return this.document?.items ?? [];
    },

    // What the line being typed comes to, before it is sent.
    preview() {
      const quantity = numberParse(String(this.line.quantity), this.locale) ?? 0;
      const unit = numberParse(String(this.line.unitPrice), this.locale);

      if (unit === null) {
        return null;
      }

      const net = Math.round(unit * 100 * quantity);
      const vat = Math.round(net * Number(this.line.vatRate) / 10000);

      return { net, vat };
    },

    totals() {
      const base = {
        net: this.document?.amountNet ?? 0,
        vat: this.document?.amountVat ?? 0,
        total: this.document?.amountTotal ?? 0
      };
      const replaced = this.replacing ? this.items.find((item) => item.id === this.replacing) : null;
      const pending = this.preview && this.line.title.trim() ? this.preview : null;

      if (!pending && !replaced) {
        return { ...base, pending: false };
      }

      const net = base.net + (pending?.net ?? 0) - (replaced?.total ?? 0);
      const vat = base.vat + (pending?.vat ?? 0) - (replaced ? Math.round(replaced.total * replaced.vatRate / 10000) : 0);

      return { net, vat, total: net + vat, pending: true };
    },

    importFields() {
      return IMPORT_FIELDS;
    },

    // What the table's chosen columns give, read as the server will.
    tableReady() {
      return this.table && this.table.columns.includes('title') && this.table.columns.includes('unitPrice');
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

    rate(basisPoints) {
      return `${(Number(basisPoints) / 100).toLocaleString(this.locale || undefined)} %`;
    },

    quantity(scaled) {
      return (Number(scaled) / SCALE).toLocaleString(this.locale || undefined);
    },

    emptyLine() {
      return { title: '', description: '', quantity: '1', unitPrice: '', vatRate: this.vatRates[0] ?? 0, reverseCharge: false };
    },

    async request(url, options = {}) {
      this.busy = true;

      try {
        const response = await fetch(url, { headers: { Accept: 'application/json', ...(options.body && !(options.body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}) }, ...options });

        return unwrapApiEnvelope(await response.json());
      } catch (error) {
        this.app.getServiceOrFail('toast').show({ type: 'error', message: error.message });

        return null;
      } finally {
        this.busy = false;
      }
    },

    // What the server holds becomes what is shown. The API answers the
    // document under `entity`, its lines and party as ids found again in
    // `relationships`.
    apply(data) {
      if (!data) {
        return false;
      }

      const relationships = data.relationships ?? {};
      const resolve = (reference) => (typeof reference === 'string' && relationships[reference] ? relationships[reference].entity : reference);
      const entity = data.entity ?? data;
      const document = { ...entity, items: (entity.items ?? []).map(resolve), party: resolve(entity.party) };

      this.document = document;
      this.head = {
        party: document.party?.id ?? document.party ?? '',
        title: document.title ?? '',
        note: document.note ?? '',
        dateInvoice: document.dateInvoice ?? '',
        dateValidUntil: document.dateValidUntil ?? '',
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
    },

    async addLine() {
      const unit = numberParse(String(this.line.unitPrice), this.locale);
      const quantity = numberParse(String(this.line.quantity), this.locale);

      if (!this.line.title.trim() || unit === null || quantity === null) {
        return;
      }

      const sent = this.apply(await this.request(this.itemCreateUrl, {
        method: 'POST',
        body: JSON.stringify({
          invoice: this.invoice,
          title: this.line.title.trim(),
          description: this.line.description.trim() || null,
          unitPrice: Math.round(unit * 100),
          quantity: Math.round(quantity * SCALE),
          vatRate: Number(this.line.vatRate),
          domesticReverseCharge: !!this.line.reverseCharge
        })
      }));

      if (!sent) {
        return;
      }

      if (this.replacing) {
        await this.removeLine({ id: this.replacing });
      }

      this.replacing = null;
      this.line = this.emptyLine();
      this.$nextTick(() => this.$refs.title?.focus());
    },

    async removeLine(item) {
      this.apply(await this.request(this.itemDeleteUrl.replace('__id__', item.id), { method: 'DELETE' }));
    },

    // A line taken back into the one being typed; added again, it replaces
    // the old one.
    editLine(item) {
      this.replacing = item.id;
      this.line = {
        title: item.title,
        description: item.description ?? '',
        quantity: String(item.quantity / SCALE),
        unitPrice: String(item.unitPrice / 100),
        vatRate: item.vatRate
      };
      this.$nextTick(() => this.$refs.title?.focus());
    },

    cancelLine() {
      this.replacing = null;
      this.line = this.emptyLine();
    },

    // Rows out of a spreadsheet arrive as tab separated lines: more than one
    // cell, and they are a table to read rather than a title.
    onPaste(event) {
      const text = event.clipboardData?.getData('text/plain') ?? '';

      if (!text.includes('\t') && !text.trim().includes('\n')) {
        return;
      }

      event.preventDefault();
      this.openTable(text.replace(/\r/g, '').split('\n').filter((row) => row.trim() !== '').map((row) => row.split('\t')));
    },

    pickFile() {
      this.$refs.file?.click();
    },

    // A file of any kind holding a table — CSV, spreadsheet, document —,
    // read by the server; its first table is offered.
    async onFile(event) {
      const file = event.target.files?.[0];
      event.target.value = '';

      if (!file) {
        return;
      }

      const body = new FormData();
      body.append('file', file);
      const read = await this.request(this.readTableUrl, { method: 'POST', body });
      const table = read?.tables?.find((candidate) => candidate.length);

      if (table) {
        this.openTable(table);
      } else if (read) {
        this.app.getServiceOrFail('toast').show({ type: 'warning', message: this.t('import.no_table') });
      }
    },

    // Each column given the field it most likely holds, by its place: the
    // reader corrects what is wrong.
    openTable(rows) {
      const width = Math.max(...rows.map((row) => row.length));
      const guess = width >= 4 ? ['title', 'quantity', 'unitPrice', 'vatRate'] : ['title', 'quantity', 'unitPrice'];
      const columns = Array.from({ length: width }, (_, index) => guess[index] ?? '');
      const header = rows.length > 1 && numberParse(String(rows[0][columns.indexOf('unitPrice')] ?? ''), this.locale) === null;

      this.table = { rows, columns, header };
    },

    async importTable() {
      if (!this.tableReady) {
        return;
      }

      const columns = {};
      this.table.columns.forEach((field, index) => {
        if (field && !(field in columns)) {
          columns[field] = index;
        }
      });

      const imported = this.apply(await this.request(this.importUrl, {
        method: 'POST',
        body: JSON.stringify({ invoice: this.invoice, rows: this.table.header ? this.table.rows.slice(1) : this.table.rows, columns })
      }));

      if (imported) {
        this.table = null;
      }
    }
  }
};
</script>
