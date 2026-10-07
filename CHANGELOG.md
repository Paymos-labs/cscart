# Changelog

All notable changes to the Paymos for CS-Cart add-on are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

## [Unreleased]

## [1.3.18] - 2026-10-07

- chore: rebuild canonical CMS package

### Fixed
- The checkout error the buyer reads was hard-coded English: "Paymos payment
  error: " followed by the exception message (BUG-181, BUG-188). The exception
  text could also be internal — a credential problem or a server validation
  detail. It now comes from the add-on's catalogues — `paymos.checkout_failed`
  ("Paymos payment error: unable to create invoice.") for a failed checkout and
  `paymos.replacement_blocked` for a blocked invoice replacement (BUG-166),
  which reads the SDK's buyer message without the error prefix. Both keys are
  translated in all six `var/langs/*/addons/paymos.po`; a store that has not
  imported them reads the English text, never the variable name. The exception
  message goes to the PHP error log as "Paymos CS-Cart checkout failed." with
  the order id; a blocked replacement is logged with its summary.
- The reason stored on an order whose invoice replacement was blocked was
  hard-coded English (BUG-188). "Paymos payment needs manual review." is now
  `paymos.manual_review`, followed by the replacement summary; ru is
  translated, de, es, tr and zh carry the English text until they are.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the reason is stored in the order's payment information.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A returning buyer could be sent to an expired invoice. The checkout always
  asked the server, but the server answers a repeated `external_order_id` with
  the same invoice whatever became of it. When that invoice expired, was
  cancelled or ended underpaid, the checkout now cuts a new one. A paid invoice
  is never replaced.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

## [1.3.17] - 2026-10-03

- fix: устранить дефекты реестра после повторного аудита

### Fixed
- The checkout error the buyer reads was hard-coded English: "Paymos payment
  error: " followed by the exception message (BUG-181, BUG-188). The exception
  text could also be internal — a credential problem or a server validation
  detail. It now comes from the add-on's catalogues — `paymos.checkout_failed`
  ("Paymos payment error: unable to create invoice.") for a failed checkout and
  `paymos.replacement_blocked` for a blocked invoice replacement (BUG-166),
  which reads the SDK's buyer message without the error prefix. Both keys are
  translated in all six `var/langs/*/addons/paymos.po`; a store that has not
  imported them reads the English text, never the variable name. The exception
  message goes to the PHP error log as "Paymos CS-Cart checkout failed." with
  the order id; a blocked replacement is logged with its summary.
- The reason stored on an order whose invoice replacement was blocked was
  hard-coded English (BUG-188). "Paymos payment needs manual review." is now
  `paymos.manual_review`, followed by the replacement summary; ru is
  translated, de, es, tr and zh carry the English text until they are.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the reason is stored in the order's payment information.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A returning buyer could be sent to an expired invoice. The checkout always
  asked the server, but the server answers a repeated `external_order_id` with
  the same invoice whatever became of it. When that invoice expired, was
  cancelled or ended underpaid, the checkout now cuts a new one. A paid invoice
  is never replaced.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

## [1.3.16] - 2026-09-29

- chore: bundle Paymos PHP SDK v1.5.0

### Fixed
- The checkout error the buyer reads was hard-coded English: "Paymos payment
  error: " followed by the exception message (BUG-181, BUG-188). The exception
  text could also be internal — a credential problem or a server validation
  detail. It now comes from the add-on's catalogues — `paymos.checkout_failed`
  ("Paymos payment error: unable to create invoice.") for a failed checkout and
  `paymos.replacement_blocked` for a blocked invoice replacement (BUG-166),
  which reads the SDK's buyer message without the error prefix. Both keys are
  translated in all six `var/langs/*/addons/paymos.po`; a store that has not
  imported them reads the English text, never the variable name. The exception
  message goes to the PHP error log as "Paymos CS-Cart checkout failed." with
  the order id; a blocked replacement is logged with its summary.
- The reason stored on an order whose invoice replacement was blocked was
  hard-coded English (BUG-188). "Paymos payment needs manual review." is now
  `paymos.manual_review`, followed by the replacement summary; ru is
  translated, de, es, tr and zh carry the English text until they are.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the reason is stored in the order's payment information.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A returning buyer could be sent to an expired invoice. The checkout always
  asked the server, but the server answers a repeated `external_order_id` with
  the same invoice whatever became of it. When that invoice expired, was
  cancelled or ended underpaid, the checkout now cuts a new one. A paid invoice
  is never replaced.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

## [1.3.15] - 2026-09-25

- fix(cscart): BUG-188 покупатель видит переведённое общее сообщение об ошибке, причина ручной проверки переводится
- docs(plugins): переводы сообщения о заблокированной замене счёта и сверка минимальных версий
- fix(plugins): BUG-181 WooCommerce и CS-Cart называют настоящую причину и переводят текст покупателю; минимум PHP для OpenCart — 8.0
- fix(plugins): BUG-166 старый счёт закрывается на сервере до выпуска нового; открытый, оплаченный или 404 — в ручную проверку
- chore: bundle Paymos PHP SDK v1.4.3
- chore: rebuild canonical CMS package

### Fixed
- The checkout error the buyer reads was hard-coded English: "Paymos payment
  error: " followed by the exception message (BUG-181, BUG-188). The exception
  text could also be internal — a credential problem or a server validation
  detail. It now comes from the add-on's catalogues — `paymos.checkout_failed`
  ("Paymos payment error: unable to create invoice.") for a failed checkout and
  `paymos.replacement_blocked` for a blocked invoice replacement (BUG-166),
  which reads the SDK's buyer message without the error prefix. Both keys are
  translated in all six `var/langs/*/addons/paymos.po`; a store that has not
  imported them reads the English text, never the variable name. The exception
  message goes to the PHP error log as "Paymos CS-Cart checkout failed." with
  the order id; a blocked replacement is logged with its summary.
- The reason stored on an order whose invoice replacement was blocked was
  hard-coded English (BUG-188). "Paymos payment needs manual review." is now
  `paymos.manual_review`, followed by the replacement summary; ru is
  translated, de, es, tr and zh carry the English text until they are.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the reason is stored in the order's payment information.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A returning buyer could be sent to an expired invoice. The checkout always
  asked the server, but the server answers a repeated `external_order_id` with
  the same invoice whatever became of it. When that invoice expired, was
  cancelled or ended underpaid, the checkout now cuts a new one. A paid invoice
  is never replaced.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

## [1.3.14] - 2026-09-25

- fix(plugins): BUG-163/BUG-164 остальные плагины — замена счёта только по ответу сервера закреплена тестами, комментарии о сроке счёта исправлены
- fix(plugins): BUG-103 вебхук, который ещё обрабатывается, больше не отвечается 200 «duplicate»
- fix(plugins): BUG-090 оплата больше не ведёт на истёкший или проваленный счёт Paymos
- fix(plugins): BUG-135 поздний нефинальный вебхук больше не оживляет проваленный или отменённый заказ
- chore: bundle Paymos PHP SDK v1.4.2

### Fixed
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A returning buyer could be sent to an expired invoice. The checkout always
  asked the server, but the server answers a repeated `external_order_id` with
  the same invoice whatever became of it. When that invoice expired, was
  cancelled or ended underpaid, the checkout now cuts a new one. A paid invoice
  is never replaced.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

## [1.3.13] - 2026-09-23

- chore: rebuild canonical CMS package

## [1.3.12] - 2026-09-21

- chore: rebuild canonical CMS package

## [1.3.11] - 2026-09-15

- chore: bundle Paymos PHP SDK v1.4.1

## [1.3.10] - 2026-08-30

- fix(plugins): гейт di:compile теперь запускается, а CS-Cart больше не конвертирует таблицу до её создания
- chore: rebuild canonical CMS package

## [1.3.9] - 2026-08-30

- fix(plugins): phase 4 debts — no installs in the wild, code lands now
- fix(plugins): CMS marketplace readiness spec, phases 1-3
- chore: rebuild canonical CMS package

## [1.3.8] - 2026-08-30

- fix(plugins): implicitly nullable factory params break Magento DI compile on PHP 8.5
- chore: rebuild canonical CMS package

## [1.3.7] - 2026-08-28

- release: the changelog rot had a cause, and it was not the one I named
- audit: the shipped plugin and SDK docs described a product we stopped shipping
- docs(plugins): eight README stubs become the front pages they already were
- docs(plugins): the changelogs stopped in June and the audit never reached them
- chore: bundle Paymos PHP SDK v1.4.0
- chore: rebuild canonical CMS package

## [1.3.6] - 2026-08-08

- fix(cscart): the settings page 500s and the whole admin UI is untranslated
- chore: rebuild canonical CMS package

## [1.3.5] - 2026-08-08

- fix(cscart): make payment possible at all — currency and callback mode

## [1.3.4] - 2026-08-08

- fix(cscart): the connect fix never reached this plugin
- chore: bundle Paymos PHP SDK v1.3.2

## [1.3.3] - 2026-08-07

- chore: rebuild canonical CMS package

## [1.3.2] - 2026-08-07

- chore: rebuild canonical CMS package

## [1.3.1] - 2026-08-07

- chore: bundle Paymos PHP SDK v1.3.1

## [1.3.0] - 2026-08-06

- feat(locales): Spanish blog and plugin catalogs
- feat(locales): German blog corpus, plugin catalogs and bot text
- feat(locales): tr + zh-Hans platform rollout — resx, bots, plugins
- chore: bundle Paymos PHP SDK v1.3.0

## [1.2.0] - 2026-08-03

- Merge remote-tracking branch 'origin/main'
- feat: consolidate BotexV2, Rentron, and ecosystem updates
- chore: bundle Paymos PHP SDK v1.3.0
- chore: rebuild canonical CMS package

## [1.1.2] - 2026-08-02

- chore: rebuild canonical CMS package

## [1.1.1] - 2026-08-02

- fix(ecosystem): recover SDK releases
- chore: bundle Paymos PHP SDK v1.2.1
- chore: rebuild canonical CMS package

## [1.1.0] - 2026-07-21

- feat(docs): make the developer surface consumable by LLM agents
- chore: bundle Paymos PHP SDK v1.2.0
- chore: rebuild canonical CMS package

## [1.0.6] - 2026-07-19

- chore: bundle Paymos PHP SDK v1.1.1

## [1.0.5] - 2026-07-13

- chore: rebuild canonical CMS package

## [1.0.4] - 2026-07-12

- fix(plugins): align CMS guidance with secure Connect

## [1.0.3] - 2026-07-12

- chore: rebuild canonical CMS package

## [1.0.2] - 2026-07-12

- chore: rebuild canonical CMS package

## [1.0.1] - 2026-07-12

- fix(release): align package stamping and webhook fixtures
- chore: rebuild canonical CMS package

## [1.0.0] - 2026-06-22

### Added
- Initial release.
- USDT and USDC payments across 13 mainnet networks via the hosted Paymos checkout.
- Payment processor add-on for CS-Cart 4.20+.
- Pre-registered webhook endpoint with HMAC-SHA256 (`X-Webhook-Signature`) verification and reverse-verification of terminal events.
- Idempotent webhook processing: a short event reservation that commits to the full TTL only after the order update succeeds, so a crash mid-flight self-heals in ~5 minutes instead of blacklisting the event.
- Checkout always re-confirms against the server (idempotent on the order id), so a buyer never lands on a dead page after an invoice expires or is cancelled.
- API credentials and signing secret pre-injected by the dashboard ZIP generator (the merchant types nothing).
- Sandbox / Live mode switch in the CS-Cart admin.
