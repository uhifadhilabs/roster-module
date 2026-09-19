# Design decisions

Each deliberate modelling choice, **why**, and **the trigger that reopens it**
— so nobody "fixes" a decision later without knowing what it cost.

## Contents

- [The scaffold was reconciled, not preserved](#the-scaffold-was-reconciled-not-preserved)
- [The slug is singular](#the-slug-is-singular)
- [The shift vocabulary is configuration](#the-shift-vocabulary-is-configuration)
- [Thresholds are starting values, not settings](#thresholds-are-starting-values-not-settings)
- [Leave has no approval in v1](#leave-has-no-approval-in-v1)
- [What the design asks for that nothing can answer yet](#what-the-design-asks-for-that-nothing-can-answer-yet)

## The scaffold was reconciled, not preserved

**Decision.** The infrastructure committed before the 18–20 September rulings
was rewritten rather than migrated. It declared a module called *Rosters* that
owned "the station registry those shifts are kept at", and it pointed at
`uhifadhi/module-contracts` and the `Uhifadhi\ModuleContracts\` namespace.

**Why.** Both are now wrong, and wrong in a way that would not have failed: the
station and the posting are the AREA's (ruled 18 sep), and the contracts moved
into the core monorepo `uhifadhi/uhifadhi`. A scaffold that keeps a retired
package name compiles happily against nothing an installation has.

**Reopens if** the core's packaging ruling changes again — the split-ready
monorepo is designed to become separate packages, and the day it does, the
`uhifadhi/uhifadhi` constraint becomes several.

## The slug is singular

**Decision.** `roster`, not `rosters`.

**Why.** The ruling names the module's home as `/areas/{uuid}/modules/roster`.
The slug is also the ledger key an area switches the module on by and the
string every overview contribution repeats, so all three had to move together.

**Reopens if** nothing. A slug change after an installation exists orphans its
per-area rows and every stored widget layout keyed to the surface.

## The shift vocabulary is configuration

**Decision.** The named windows a watch can be stood in — day, night, office,
radio night — live in `config/packages/roster.yaml`, not in code and not in the
database.

**Why.** Grammar 3 is ruled *provisionally*: whether a gate really runs two
twelves is an operational fact the deployment owns, not a design choice. As
config, a fifth named window is a line in a yaml file. A window may cross
midnight, and a duty belongs to the calendar day its watch BEGINS on — so a
night watch is one duty and two blocks on a day board.

**Reopens if** the answer for a remote post turns out to be a **tour** (ten days
on, four off — grammar 4 in the gallery). A tour is not a window and a day board
cannot draw it; it would need grammar 3 underneath it anyway, so the vocabulary
would gain a second kind rather than change shape.

## Thresholds are starting values, not settings

**Decision.** `roster.defaults.*` — ping interval, silence window, offline
threshold, catchment radius, generation horizon — are read when something is
CREATED and never at display time.

**Why.** The ruling is explicit that a station's silence threshold is *the
station's own*: a gate that never closes and a rim post reached once a fortnight
cannot share one. A config value read at display time would silently override
whatever a park set on a screen, and the override would be invisible.

**Reopens if** a deployment needs a hard ceiling rather than a default — e.g. an
installation that refuses a catchment wider than 5 km. That is a different node
(a maximum), not a different reading of this one.

## Leave has no approval in v1

**Decision.** An absence is a record — person, from, to, kind, recorded by — and
nothing else. No state machine, no approver, no request, and no config flag for
one.

**Why.** Ruled: approval is out of scope for v1, and if it is wanted it belongs
to Team, which owns the person. Shipping a `leave_approval: false` flag would
spend a config key on a code path nothing ships.

**Reopens if** Team grows an approval workflow. This module then gains a
read-only state chip on the absence card and still owns no state machine.

## What the design asks for that nothing can answer yet

These are named here rather than invented, because the honest empty state is
the right port until the seam lands.

| The design draws | What it needs | Status |
|---|---|---|
| Presence: verified / unverified / late / offline, the presence card, the Live tab | `Uhifadhi\Contracts\Area\PresenceProviderInterface` | **not in the core yet** — the roster reads it, never computes it |
| The area asking the roster for a person's watches (`/me/roster`) | `Uhifadhi\Contracts\Roster\WatchProviderInterface` | **not in the core yet** — this module implements it the day it lands |
| The *Watch and presence* section on the area's station record, and the *Roster* block on its Stations configure card | a station-record sections seam in the area | **not in the core yet**; `Uhifadhi\Contracts\Shell\AreaSectionsInterface` covers the area's configure page but not the station record |
| The check-in statuses list on this module's Settings section | the area's `CheckInStatus` read model | present in `AreaBundle`; the roster reads it and edits nothing |
