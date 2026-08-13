# What this panel is, surface by surface

This package is presentation only. The domain owns every rule; what follows is why
each surface looks the way it does, and which domain call is behind it.

## The three things called "a discount"

The domain keeps four concepts apart and this panel keeps them apart too, because
collapsing them is what the host does:

- **An offer** is the merchant's standing rule. It is authored, revised, and
  decided upon. → `OfferResource`
- **A code** is a way of *reaching* an offer. Many per offer, or none — an
  automatic discount is an offer with no code. → `CodesRelationManager`
- **An entitlement** is the evaluation of the offers against one basket at one
  moment. It is derived and perishable, and it is never stored. →
  `EvaluateBasket`, which recomputes on every render
- **A redemption** is the historical fact that an offer was spent on an order. It
  is append-only. → `RedemptionResource`

## Offer authoring

`OfferResource::form()` is the answer to the host's `DiscountResource::form()`,
which returns `->components([//])` — an empty schema over a table whose `title`
column is `NOT NULL`, so the Create page renders a form with no fields and the
feature is dead at both ends.

Every field of `OfferTerms` a merchant sets is settable, and **the form must not
let a merchant save terms the domain will reject**. `InvalidOfferTerms` is a
backstop, not a UX. Two techniques do the work:

- **Rules that mirror the domain's**, field by field: a rate between 1 and 10000
  basis points, a positive amount, a minimum quantity of at least one, an end
  after a start, at least one product reference when the target is products.
- **Making the impossible pairs unreachable rather than refused.** A free-shipping
  offer targets shipping and nothing else may, so the target select offers exactly
  one option in each direction. A rate is not shown for a fixed-amount offer and
  an amount is not shown for a percentage one. A field a merchant cannot see is a
  field they cannot get wrong.

Money is entered as a **decimal string** and parsed by `Money::fromDecimalString`,
which is string arithmetic. `(int) (19.99 * 100)` is 1998; a test pins that.
`TextInput::integer()` is not used anywhere in this package — it hands back a
`float`, and the resulting `TypeError` surfaces through Livewire as a bare
`419 Page Expired`.

A percentage is basis points, an integer. 20% is 2000. It is rendered back out
with integer arithmetic too.

**Status is not a form field.** It is not in the schema at all, and a test asserts
that.

## Writing goes through the domain, never through Eloquent

| Page | Domain call |
|---|---|
| Create | `CreateOffer` — offer, first revision and creating decision, in one transaction |
| Edit | `ReviseOfferTerms` — archives the new terms, moves the live columns |
| Activate / Pause / End | `DecideOfferStatus` |
| Issue a code | `IssueCode` |
| Evaluate a basket | `QuoteBasket` — writes nothing |
| Release | `ReleaseRedemption` |

Writing the offer row directly would produce an offer with no revision for a
redemption to point at and no decision saying it exists — the archive would start
one edit late. The test fixtures go through the same actions for the same reason.

## The status decision log and the revision archive are first-class

Not audit trivia. "Who paused the Black Friday sale, and when" is a question
somebody asks at 9am on Black Friday, and the host's answer is
`discounts.is_active`, which records neither.

Both are fed by `ListOfferHistory`, the domain's published query, so the ordering
is the domain's and not a second opinion. Both are read-only in every direction:
no create, no edit, no delete, no bulk action, and no clickable row — there is
nothing underneath an archived row to open, and a clickable row would offer an
edit page that must never exist.

Reverting is **not** offered. Reverting is authoring the old terms again, which is
a new revision with a new actor and a new time, and it goes through the offer form.

The activate action derives its reason from where the offer is coming from:
activating a draft is `merchant_activated` and resuming a pause is
`merchant_resumed`. They are different facts and the domain has a reason for each;
a merchant should not have to classify their own click.

## Evaluating a basket, and skipped offers

Addendum §6 is the reason this page exists. A merchant whose VIP offer has silently
applied to nobody for a week must be able to find out why **without reading logs**.

The page lists every active offer the quote considered, each one either applied —
with the allocation the domain published, per line and shipping separately — or
skipped, **by name and with its refusal reason**.

`eligibility_unresolvable` is rendered as a *distinct outcome*, styled differently
and worded to say what to do about it. That is the whole point of the domain
publishing a separate reason: an offer that could not be evaluated at all is a
broken deployment, not a basket that missed a minimum, and collapsing the two is
precisely the failure this reason exists to prevent.

Refusal reasons are **merchant-facing only**. Rendering them to a shopper turns a
quote into an oracle for which codes exist, which is wave 7's gift-card rule and a
security decision rather than a UX one. This surface is the merchant's, so this is
the one place in the fleet they are rendered — `Support\Refusals` is the only
translation table for them.

**Nothing is cached.** The entitlement is recomputed every time the table is asked
for its records. The host recomputes the coupon at checkout, correctly, and keeps
the stale applied figure in the session beside it; there is no session copy here
because there is no copy at all.

### A custom-data table needs three things unwired

This is the only table in the package whose records are arrays rather than models,
and each of the three fails separately:

1. `ListRecords::makeTable()` attaches a `recordAction` closure typed against
   `Model` → replaced with `null`.
2. It attaches a `recordUrl` closure typed against `Model`, unless the table
   already declares a custom one → passing `null` counts as declaring one.
3. `ViewAction` authorizes against a `Model` → this table ships no record actions.

The resource still declares `$model = Offer::class`, because Filament's resources,
policies, relation managers and record routing are all typed against a model —
there is no model-less resource. Eloquent stays where it belongs: tenant-scoped
route binding and the offers list.

## The redemption ledger

Append-only, and nothing here edits or deletes a row. A usage limit counts these
rows and an accountant reconciles them; the host has no such concept at all,
inferring uses from a `SELECT COUNT(*)` over another module's orders table, which
is why a cancelled order can never give a use back there.

A **release** is neither an edit nor a deletion. It appends a row to
`promotions_redemption_releases`, hands the per-customer slot back by clearing
`customer_sequence`, and brings the counter down by the same conditional update
that claimed it. The redemption, its lines and its release all survive, so "spent
then returned" stays distinguishable from "never spent".

`customer_sequence` is shown on the record and labelled as what it is: **a
constraint slot rather than a fact**. It is not "which use this was".

## Ledger integrity

The two cached values in this module — `redemptions_used` and `status` — exist for
good reasons: a conditional update is the only race-free way to enforce a limit,
and evaluation cannot fold a decision log on every basket. The domain publishes a
recompute for each, with an `agrees()`.

Those checks are surfaced **on the page**, in the `LedgerIntegrity` widget, rather
than only in a test. A check that runs once in CI proves the code was right when it
shipped; a check on the page proves the data is right now, and the merchant is the
person who finds out first that a limit is counting wrong.

The columns and the ledger show the **cached** values as they are stored. Showing
the recomputed number in their place would hide exactly the drift this widget
exists to reveal.

## What is searchable, what is filterable, and why

Search terms and filter state both persist into the query string, into browser
history, and into every screenshot pasted into a ticket.

| Value | Searchable | Filterable | Why |
|---|---|---|---|
| Offer name | yes | no | The merchant's own label for their own rule |
| Offer status / kind / target | no | yes | Closed enums, nothing sensitive |
| **Code** | **no** | **no** | A bearer-ish value: whoever holds it can spend the offer. Shown on the offer, reached by opening the offer |
| Order reference | yes | no | The merchant's own order identifier; the ledger is unusable without it |
| **Customer reference** | **no** | **no** | Not a thing to leave in browser history. Shown on the record a merchant already opened |
| Release state and reason | no | yes | Closed enum |

## Authorization

Three separate defaults are permissive, and each has shipped as a hole in this
programme:

1. a model with no policy — Laravel's unanswered gate allows;
2. a policy present but missing the method asked about — Filament's
   `get_authorization_response()` returns **allow**;
3. `associate` and `dissociate`, which are live on a `hasMany` relation manager and
   open by default.

`Policies\DeniesEveryAbility` answers all eighteen abilities Filament's resource
and relation-manager authorization passes to the gate, **by name**, with `false`.
Each policy overrides only what it publishes:

| Policy | Published |
|---|---|
| `OfferPolicy` | `viewAny`, `view`, `create`, `update`, and `decideStatus` |
| `CodePolicy` | `viewAny`, `view`, `create` |
| `OfferRevisionPolicy` | `viewAny`, `view` |
| `OfferStatusDecisionPolicy` | `viewAny`, `view` |
| `RedemptionPolicy` | `viewAny`, `view`, and `release` |

`decideStatus` and `release` are separate abilities rather than `update`, because
neither is an update: one appends a decision and the other appends a release. A
panel may reasonably let a junior merchant pause a runaway sale without letting
them rewrite its terms.

It is a base **class**, not a trait, because a subclass method silently wins over a
trait's — a policy that meant to deny would reopen an ability by naming a method
that happened to collide. Overriding a parent method is the same act, but it is one
a reader can see.

Custom actions carry **no** automatic authorization in Filament: the default is
`null`, which is allowed for everybody. The status actions and the release action
name their ability explicitly.

## Accessibility

Every column, field and action carries an explicit label. The basket evaluation
page has a heading, a subheading that says what the page does not do, and empty
states that say what to do next. All input is through Filament's own form
components, which are keyboard-reachable and labelled; this package ships no custom
Blade and no custom JavaScript.
