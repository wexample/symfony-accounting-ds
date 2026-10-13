<script>
import AbstractEntityGrid from '@wexample/symfony-design-system/components/collection/grid/abstract-entity-grid/abstract-entity-grid.vue';
import InvoiceItem from '@wexample/symfony-accounting/Entity/InvoiceItem';

const T = 'WexampleSymfonyAccountingDsBundle.components.invoice-item-grid.invoice-item-grid::';

// Quantities are kept scaled by 100, VAT rates in basis points: what the books
// store, what the API takes.
const SCALE = 100;

// The lines of a draft, written in place: the entity grid of the design
// system on the document's lines. A table pasted into the new line, or a file
// of any kind holding one — read by the server —, adds many at once.
export default {
  extends: AbstractEntityGrid,

  template: '#vue-template-wexample-symfony-accounting-ds-bundle-components-invoice-item-grid-invoice-item-grid',

  props: {
    invoice: { type: String, required: true },
    ledger: { type: String, required: true },
    // In basis points, the first one the default.
    vatRates: { type: Array, default: () => [] },
    // The document's, which may not be the ledger's.
    currencyCode: { type: String, default: 'EUR' },
    localeCode: { type: String, default: null }
  },

  data() {
    return {
      fill: true,
      currency: this.currencyCode,
      locale: this.localeCode
    };
  },

  watch: {
    currencyCode(currency) {
      this.currency = currency;
    }
  },

  methods: {
    getEntityClass() {
      return InvoiceItem;
    },

    getCollectionQuery() {
      return { invoice: this.invoice };
    },

    getNewEntityData() {
      return { invoice: this.invoice };
    },

    canReadTableFiles() {
      return true;
    },

    async readTableFile(file) {
      const body = new FormData();
      body.append('file', file);

      const response = await fetch(
        this.app.getServiceOrFail('routing').path('api_invoice_item_read_table', { ledger: this.ledger }),
        { method: 'POST', headers: { Accept: 'application/json' }, body }
      );
      const answer = await response.json();

      if (!response.ok) {
        throw new Error(answer?.message || response.statusText);
      }

      return answer?.data?.tables ?? [];
    },

    rate(basisPoints) {
      return `${(Number(basisPoints) / 100).toLocaleString(this.locale || undefined)} %`;
    },

    getColumnsConfiguration() {
      const t = (key) => this.trans(`${T}${key}`);

      return [
        { key: 'title', label: t('column.title'), editor: 'text', required: true, placeholder: t('new') },
        { key: 'description', label: t('column.description'), editor: 'text' },
        { key: 'quantity', label: t('column.quantity'), editor: 'number', scale: SCALE, default: SCALE, width: 's' },
        { key: 'priceRaw', label: t('column.unit_price'), editor: 'money', required: true, width: 'm' },
        {
          key: 'priceVat',
          label: t('column.vat'),
          editor: 'select',
          scale: 100,
          required: true,
          default: this.vatRates[0] ?? 0,
          options: this.vatRates.map((rate) => ({ value: rate, label: this.rate(rate) })),
          width: 's'
        },
        { key: 'domesticReverseCharge', label: t('column.reverse_charge'), editor: 'checkbox', align: 'center', width: 'xs' },
        {
          key: 'net',
          label: t('column.net'),
          editor: 'money',
          align: 'right',
          width: 'm',
          compute: (line) => (line.priceRaw === null || line.priceRaw === undefined
            ? null
            : Math.round(Number(line.priceRaw) * Number(line.quantity ?? SCALE) / SCALE))
        }
      ];
    }
  }
};
</script>
