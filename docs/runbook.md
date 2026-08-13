# Runbook

The questions this panel exists to answer, and the ones it deliberately does not.

## "Who paused the Black Friday sale, and when?"

**Offers → open the offer → Status decisions.**

Every row carries the previous status, the new one, a closed-enum reason, the actor
and an `occurred_at` distinct from `created_at`. Nothing writes
`promotions_offers.status` except `DecideOfferStatus`, and the log is read-only in
the panel.

If the log and the status column disagree, the **Ledger integrity** widget on the
offers list says so under *Status drift*. A non-zero number there means something
wrote the column directly and is a bug to chase, not a number to correct by hand.

## "Why has my VIP offer applied to nobody all week?"

**Offers → Evaluate a basket.** Describe a basket like the one you expect to
qualify, add the code if there is one, and read the outcome column.

- **Skipped** — an ordinary non-qualification. The reason names it: below the
  minimum, nothing in the basket is what it targets, blocked by an exclusive offer,
  the total limit is spent, this shopper has already had it.
- **Could not be evaluated** — the offer names a customer group or targets a
  collection and the seam that answers for it **is not bound**. This is a
  deployment problem, not a basket problem. Every other offer is evaluating
  normally; only the ones naming a group or collection are affected. Bind
  `ResolvesCustomerEligibility` or `ResolvesProductGrouping` from your Customers or
  Catalog module.

An offer that does not appear at all is not active. Check its status.

Nothing on this page is written. Quoting a hundred baskets changes no counter and
records no redemption.

## "A shopper says their code was refused."

**Offers → Evaluate a basket**, with the code in *Codes presented*. The table
description names the refusal: *Refused SUMMER10: no such code*, and so on.

Those reasons are **merchant-facing only**. The shopper-facing surface tells them
one message whatever the reason, on purpose: a per-reason answer is an oracle for
which codes exist. Do not quote the reason back to them verbatim.

## "A cancelled order spent a coupon. Give the use back."

**Redemptions → find the order reference → Release.** Pick a reason
(`order_cancelled`, `order_refunded`, `merchant_reversed`, `payment_failed`) and
add a note.

This appends a release row, hands the shopper's per-customer slot back and brings
the counter down. It does not delete anything: the redemption, its lines and its
release all survive, so "spent then returned" stays distinguishable from "never
spent".

A redemption can be released **once**. The action disappears once it has been, and
a concurrent second attempt is refused by the unique index rather than by a guard.

## "The usage counter looks wrong."

**Offers → Ledger integrity → Counter drift.** That number is
`RecomputeRedemptionsUsed::agrees()` run against every offer in the merchant: it
re-derives `redemptions_used` from the redemptions and the releases and compares.

Zero means the cache matches the ledger. Anything else means something wrote the
column outside `ClaimRedemption` and `ReleaseRedemption` — model events do not fire
for `query()->update()`, which is exactly how a counter drifts. Find the writer;
do not hand-correct the column, because the ledger is the truth and the column is
the cache.

## "How do I erase a shopper?"

Not from this panel, in `0.1.0`. The domain publishes
`RedactCustomerFromRedemptions($tenantId, $customerRef): int`, which clears
`customer_ref` and keeps the redemption, its lines and its release, so a merchant's
usage limits and reconciliation do not change because a shopper exercised a right.

It is not surfaced here for two reasons, and both are the reason to be careful when
it is:

1. It takes a **customer reference as an input**, which is exactly the value this
   panel refuses to make searchable or filterable — a form field for it puts it
   into a request, and a filter would put it into the query string.
2. Erasure is a privacy operation with its own audit trail and its own authority.
   It belongs on a privacy surface that records who erased whom, not on a
   promotions panel where it would be one button among the discount tools.

Call it from your privacy module or from a console command until that surface
exists.

## "Can I revert an offer to an old revision?"

Not as a button, deliberately. Reverting is **authoring the old terms again** — a
new revision, with a new actor and a new time. Open the revision, read the archived
terms, and type them into the offer form. The history then says what actually
happened, which is that somebody changed the terms back, rather than implying the
old revision came alive again.

## "What happens to the host's `discounts` and `coupons` tables?"

Nothing. This package neither adopts nor reads them.

- The host's `Discount` reaches no order total and never did: no service, no
  controller, no checkout path, no job reads it. Nothing is lost by leaving it.
- The host's `Coupon` **does** reach an order total, through `CouponService`.
  Migrating live coupons into offers and codes is a host data migration, is not
  attempted by this package, and should be done with both running before either is
  switched off.

## Symptoms and what they actually mean

| Symptom | Cause |
|---|---|
| `RuntimeException: could not resolve a tenant for this panel` | The plugin is attached to a panel with no Filament tenant and no `tenantUsing()`. There is no default on purpose |
| `RuntimeException: the promotions plugin is not attached to this panel` | A resource or widget from this package is reachable on a panel that never attached the plugin |
| A bare `419 Page Expired` on save | Almost always a `TypeError` inside a schema closure, hidden because `app.debug` is false. Turn it on before investigating anything else |
| `ViewErrorBag::put(): $bag must be MessageBag, null given` | Livewire's provider registered before Filament's. `filament/support` re-`bind()`s Livewire's `DataStore`, and a `bind()` drops the instance already there |
| No promotions navigation at all | The module is not in `MODULES_ENABLED`, or the plugin is not attached to that panel |
