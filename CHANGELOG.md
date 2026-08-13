# Changelog

All notable changes to `liberusoftware/ecommerce-promotions-filament` are
documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this package uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-08-13

First release. The merchant-facing panel over `liberusoftware/ecommerce-promotions`
`0.1.0`. It adds no business rule of its own: every write goes through a domain
action and every read comes from a domain query or a tenant-scoped Eloquent query.

### Added

- `PromotionsPlugin`, attached per panel. Nothing registers globally: the service
  provider is empty, and a host that enables the module without attaching the
  plugin gets no navigation, no routes and no policies.
- **Offer authoring** (`OfferResource`) with a form that expresses every term
  `OfferTerms` accepts and refuses every combination it rejects. Creating goes
  through `CreateOffer`; editing goes through `ReviseOfferTerms`, so an edit
  archives a revision rather than overwriting one.
- **The status decision log** (`StatusDecisionsRelationManager`) and the
  **Activate / Pause / End** actions, which go through `DecideOfferStatus`.
  Status is not a form field and is never written directly.
- **The revision archive** (`RevisionsRelationManager`), read-only, fed by
  `ListOfferHistory`.
- **Codes** (`CodesRelationManager`), issued through `IssueCode`. Never
  searchable, never filterable, never edited, never deleted.
- **Evaluate a basket** (`EvaluateBasket`), which quotes a described basket
  through `QuoteBasket` and lists every active offer as applied — with its
  allocation — or skipped, **by name and with its refusal reason**. "Could not be
  evaluated" is rendered as a distinct outcome from "did not qualify".
- **The redemption ledger** (`RedemptionResource`) with its lines, its revision
  and its release, and a **Release** action through `ReleaseRedemption`.
- **`LedgerIntegrity`**, a widget that surfaces `RecomputeRedemptionsUsed::agrees()`
  and `RecomputeOfferStatus::agrees()` on the page rather than only in a test.
- Policies for all five models the panel routes to, each forcing every
  unpublished ability false **by name**, including `associate` and `dissociate`.

### Deliberately not in this release

- **Customer redaction.** `RedactCustomerFromRedemptions` is published by the
  domain and is not surfaced here; see `docs/runbook.md`.
- **A per-order redemption view.** `ListRedemptionsForOrder` is the `-api`'s
  read model; the ledger lists a merchant's redemptions and filters by offer.

[0.1.0]: https://github.com/liberusoftware/module-ecommerce-promotions-filament/releases/tag/0.1.0
