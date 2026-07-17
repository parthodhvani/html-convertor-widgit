# Accuracy regression benchmarks (Phase 15)

Run from the plugin root:

```bash
php tests/benchmarks/run-regression.php
php tests/benchmarks/run-regression.php --json
php tests/benchmarks/run-regression.php --fixture=bootstrap
```

Fixtures come from `tests/php/RegressionFixtures.php` (Bootstrap, Tailwind, HTML5 UP, nested flex, agency, business, portfolio, docs, complex grid).

Metrics per fixture:

- geometry similarity
- fidelity score
- native vs HTML widget editability
- max container depth
