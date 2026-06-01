# i18n CSVs — translation provenance

All non-en_US CSV files are AI-drafted from the en_US source as of 2026-04-29.
They have NOT been reviewed by counsel.

**Merchant responsibility:** review legal-language strings (Art. 16 / Art. 14
/ Art. 11a CRD references) for your store's target jurisdictions with local
counsel BEFORE production deployment.

Counsel sign-off deadline (Free release gate): 2026-05-15.

The strings inside the receipt / Annex I durable-medium content are
exception cases — those are sourced verbatim from EUR-Lex CELEX 32011L0083
and should be left as-is.

## Locales shipped (22 total)

- en_US (master, English source)
- bg_BG, cs_CZ, da_DK, de_DE, el_GR, es_ES, et_EE, fi_FI, fr_FR, hr_HR,
  hu_HU, it_IT, lt_LT, lv_LV, nl_NL, pl_PL, pt_PT, ro_RO, sk_SK, sl_SI, sv_SE

## Country variants (de_AT, de_BE, fr_BE, fr_LU, nl_BE, sv_FI) NOT shipped

These are handled at runtime by `MageMe\EUWithdrawal\Plugin\Translate\
MergeParentLanguageStrings`, which transparently fills variant locale
dictionaries from their parent-language CSV via the LocaleFallbackResolver
chain (e.g. de_AT → de_DE → en_US).

## Coverage notes

Each CSV contains all 655 master rows. Where a high-quality translation
was available it was applied; for long admin help-text strings (system.xml
field descriptions, internal error messages, advanced developer-facing
text) the row falls through to the English source. Magento's `__()`
loader silently passes through identical en_source / translation pairs,
so the UI behaves correctly — admin staff using non-English locales will
simply see admin help text in English while customer-facing UI remains
fully localised.

The translation coverage per locale (number of rows where translation
differs from English source) is approximately:

- de_DE, fr_FR, es_ES, it_IT, nl_NL, pt_PT, pl_PL, sv_SE, da_DK, fi_FI:
  ~200–390 translated rows (existing curated translations preserved +
  ~150–250 fresh translations)
- 11 NEW locales (bg_BG, cs_CZ, el_GR, et_EE, hr_HR, hu_HU, lt_LT, lv_LV,
  ro_RO, sk_SK, sl_SI): ~170 translated rows each (fresh AI translations
  for the highest-impact UI strings)

## Placeholder safety

Every translation has been validated to preserve the same set of
placeholder tokens (`%1`, `%2`, `%name`, `%order_increment_id`,
`{period_days}`, etc.) as the English source — `__()` substitution is
guaranteed to work in every locale.
