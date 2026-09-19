# Design decisions

Each deliberate modelling choice, **why**, and **the trigger that reopens it**
— so nobody "fixes" a decision later without knowing what it cost.

## Contents

- [The scaffold was reconciled, not preserved](#the-scaffold-was-reconciled-not-preserved)
- [The slug is singular](#the-slug-is-singular)
- [The shift vocabulary is an area's list, seeded from configuration](#the-shift-vocabulary-is-an-areas-list-seeded-from-configuration)
- [An edited day is a row, not a flag](#an-edited-day-is-a-row-not-a-flag)
- [A hole is a shortfall, not a duty with nobody on it](#a-hole-is-a-shortfall-not-a-duty-with-nobody-on-it)
- [The pool is ordered, so it is a join entity](#the-pool-is-ordered-so-it-is-a-join-entity)
- [A horizon is stored as days, not as the preset that chose them](#a-horizon-is-stored-as-days-not-as-the-preset-that-chose-them)
- [A per-team rotation cannot generate yet](#a-per-team-rotation-cannot-generate-yet)
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

## The shift vocabulary is an area's list, seeded from configuration

**Decision.** `Shift` is an entity, one list per area, edited on the Configure
page. What `config/packages/roster.yaml` holds is the four windows a NEW area's
list is seeded with — a starting point, never the running value. `off` is
refused as a shift key.

**Why.** The Settings section rules "one list for the whole area", with "+ add
a shift" and "a shift in use cannot be deleted, only closed". That is a
lifecycle, and a lifecycle needs rows. Grammar 3 is ruled *provisionally* —
whether a gate really runs two twelves is an operational fact the deployment
owns — so the seed stays configurable and a fifth named window is a line in a
yaml file. `off` is reserved because a rotation's ring spells a stood-down day
that way; a shift of that name would make every stored cycle ambiguous.

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

## An edited day is a row, not a flag

**Decision.** `EditedDay` — one row per station per day — is what the generator
checks before it writes. Not a boolean on `Duty`.

**Why.** "An edited day is never overwritten by a later run" is ruled, and a
flag cannot carry it. The commonest edit is TAKING SOMEBODY OFF a watch, and a
flag on a deleted duty goes with the duty: the next run would find nothing
there, conclude the day was never generated, and put the person the duty
officer removed straight back on. The mark has to outlive the thing it
protects. It is keyed by station rather than by rotation because two rotations
may reach one post, and the protection is about the day a person read and
changed.

**Reopens if** a day ever needs to be *partly* protected. It should not: the
generator steps over an edited day whole, because merging would silently undo
part of a decision somebody made while looking at the whole day.

## A hole is a shortfall, not a duty with nobody on it

**Decision.** `Duty.person` is not nullable. An unfilled slot is the difference
between `Rotation.slotsPerShift` and the duties that exist, computed when
something asks.

**Why.** A duty with no person is a row that means "nobody", and every count in
the module would then have to remember to exclude it. The design's own domain
card lists `person` as "a Team member of this area", not as optional.

**Reopens if** a hole ever needs to carry its own data — a reason, an
escalation, a note. Then it is a different object, not a crippled duty.

## The pool is ordered, so it is a join entity

**Decision.** `RotationPoolMember` (rotation, person, position), not a
many-to-many.

**Why.** "Each person enters the ring one day later than the last" makes the
ORDER the plan: position 0 and position 3 are working different days of the
same cycle. A collection whose order the database is free to return differently
would quietly re-plan the park between two page loads, and nothing would look
wrong.

**Reopens if** nothing.

## A horizon is stored as days, not as the preset that chose them

**Decision.** `Rotation.horizonDays` is an integer. The editor's three choices
— six weeks, three months, end of the year — are presets that write a number.

**Why.** "End of the year" stored as a preset would silently mean something
different every January. A preset is a way of choosing a number; the number is
the fact.

**Reopens if** a deployment wants a horizon that genuinely is a rule rather
than a length ("always to the end of the quarter"). That is a second field, not
a different type for this one.

## A per-team rotation cannot generate yet

**Decision.** `RotationGenerator` refuses a per-team rotation with a named
exception (`RotationCannotGenerate`) rather than writing its duties against
some station.

**NEEDS A VERDICT.** The design draws a per-team rotation — "a team's cycle
travels with them", *Crater response team · per team · 10 on, 4 off · generated
to 28 oct* — and shows it generating. But a duty is "one watch, one station,
one day" by ruling, and nothing says where a roving squad's watch stands. The
two candidate answers:

1. **A base post**, chosen when the rotation is created. Keeps `Duty.station`
   non-null and every station-keyed count intact; slightly untrue for a squad
   that is somewhere else all fortnight.
2. **A station-less duty.** Honest about a roving tour; makes `Duty.station`
   nullable, which means every count keyed by station has to exclude it, and
   the unique constraint needs paired partial indexes because Postgres treats
   NULLs as distinct.

Refusing loudly is the interim, because a silent "nothing written" looks exactly
like a rotation whose pool is all away.

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
