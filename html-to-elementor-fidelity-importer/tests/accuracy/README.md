# Continuous Accuracy Optimization

The accuracy suite is the source of truth for compiler changes.

## Corpus

42 diverse pages under `corpus/` (Bootstrap/Tailwind/Shopify/Webflow/Squarespace-like,
corporate, blog, dashboards, forms, grids, absolute layouts, dark/light themes, plus
existing Petra/kontakt fixtures and localized real Bootstrap examples).

Rebuild:

```bash
node tests/accuracy/build-corpus.js
```

## Run

From the plugin root:

```bash
node tests/accuracy/run-suite.js --label my-run
node tests/accuracy/run-suite.js --only bs-pricing
node tests/accuracy/run-suite.js --limit 5
```

Each page: Chromium layout + screenshot → Elementor compile + preview HTML →
preview screenshot → pixel/geometry/typography/spacing/responsive metrics →
root-cause classification.

Reports land in `tests/accuracy/out/<label>-<timestamp>/report.{json,txt}` and
`tests/accuracy/out/latest.{json,txt}`.

## Optimization loop

1. Run the full suite
2. Rank `top_categories` by impact
3. Fix only the highest-impact category
4. Re-run and compare `summary.avg_composite`
5. Stop when a change improves average composite by ≤ 0.5%
