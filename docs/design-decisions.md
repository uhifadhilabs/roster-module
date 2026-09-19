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
- [A per-team rotation is filed at its base post](#a-per-team-rotation-is-filed-at-its-base-post)
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

## A per-team rotation is filed at its base post

**RULED 2026-09-20.** A per-team rotation names a BASE POST at creation
(`Rotation.baseStation`, required whenever the scope is team). Its duties are
filed against that post and `Duty.station` stays non-null. **A squad away on
tour is counted at its base.**

**Why.** The design draws a per-team rotation — *Crater response team · per
team · 10 on, 4 off · generated to 28 oct* — and shows it generating, but a
duty is "one watch, one station, one day" and nothing said where a roving
squad's watch stands. The rejected alternative was a station-less duty: honest
about a tour, but it makes `Duty.station` nullable, so every count keyed by
station carries an exception and the unique constraint needs paired partial
indexes (Postgres treats NULLs as distinct). The cycle still travels with the
team; only the filing stands still, and the surfaces say so.

**Consequence.** `Rotation::carriedBy()` takes the base post as a required
argument rather than leaving it settable afterwards — a team rotation saved
without one would generate nothing, which looks exactly like a pool that is
all away. `RotationCannotGenerate` is therefore **unreachable from the
product**: both ways of setting a scope take a station. It is kept for the row
written around them (a fixture, a direct insert, a half-finished import), and
tested as such.

**Reopens if** a deployment needs a squad's watches to appear at *nobody's*
post — a marine unit, an aerial wing. Then it is the nullable-station model,
with the partial-index work that implies.

## Leave has no approval in v1

**Decision.** An absence is a record — person, from, to, kind, recorded by — and
nothing else. No state machine, no approver, no request, and no config flag for
one.

**Why.** Ruled: approval is out of scope for v1, and if it is wanted it belongs
to Team, which owns the person. Shipping a `leave_approval: false` flag would
spend a config key on a code path nothing ships.

**Reopens if** Team grows an approval workflow. This module then gains a
read-only state chip on the absence card and still owns no state machine.

## The post's state is the only thing this module derives

**Decision.** `PresenceReader` reads every fact about a PERSON from the area's
`PresenceProviderInterface` unchanged. The one thing it computes is the POST's
state — reporting / late / offline / no-watch — and the join between who
reported and who was rostered.

**Why.** Presence is the area's, ruled, and one derivation read by everybody is
the whole point: if the roster, the overview and a department page each
computed "at post, verified" for themselves there would be three answers, and
the one that disagreed would be the one somebody acted on. But the post's state
is measured against thresholds this module owns on the post, and the area
states plainly that it does not hold who was *supposed* to be on — so the join
is a fact only a caller holding both sides can see, and it is exactly the "no
check-in against a rostered watch" a board draws.

**Reopens if** the area grows post-level state of its own. Then this module
reads that too and keeps only the join.

## Banding is for configure cards, not for tabs

**RULED 2026-09-20** (workspace `730a095`, and the configure-card measure
against the area's own Stations card).

A **tab's** card is the house card: `.c`, a `.tab` header, a body, and at most
ONE quiet `.more` door. A **configure** card keeps the three-region banded
form — and its band is **identity only**: no door, the form's Save at the END
of the body in the house's `.save-row`, and a footer strip reserved for one
separate operation, never for commentary.

**Consequence here.** The Week tab wears `.c`; the three configure sections
keep `.rband` and lost their header doors, their footer commentary and their
footer Save. The `.rb-foot` rules are deleted from the sheet rather than left
unspent — a rule nothing draws is a rule that drifts.

**Note for the shell.** The design defines `.c > .more` (the door pinned to the
card's top edge) in `uhifadhi.css`, which is the shell's sheet. The core has
not ported it yet, so the door renders with the shell's plain `.more` — right
typography and gesture, not yet edge-positioned. This module deliberately does
not define it: it is a shared component and belongs in the shell.

## A swap is an offer, and only accepting moves anybody

**Decision.** `Swap` is an entity, not a column on a duty. Offering changes
nothing; accepting moves the duty (or both duties, for a true exchange) **and
marks the affected days edited**.

**Why the marking is the load-bearing half.** Without it the nightly generator
rebuilds the day from the ring and puts everybody back where the pattern says
they belong — quietly undoing an agreement two people made, with nothing
anywhere saying why. It reuses `EditedDay` because it is the same fact as a
duty officer's hand edit: somebody decided this day is not what the pattern
says. There is a test that runs the generator after an accepted swap.

**One open offer per watch**, enforced in the service and not by a unique
index: a plain unique on (duty, state) would also forbid a second *declined*
offer, and asking somebody else after the first said no is the ordinary way a
hole gets filled.

**Reopens if** a swap ever needs to be approved by a third person. It is an
agreement between two today, and the handset is the only thing that accepts.

## The month is the atlas's, not this module's

**Decision.** The Calendar tab renders `atlas_calendar()` with a
`CalendarFeedInterface` this module implements. It ships no month grid.

**Why.** The component exists to keep one rule a module cannot keep for
itself: a cell is one height whatever it holds, so a busy week does not make
the month taller than a quiet one. The design's own 09-20 commit deleted the
roster's bespoke `.r-mon` grid for exactly this. This module says what
happened on which day; the grid, the day head, the cell, the "+N more" and
the stepper are the atlas's.

**The scope is `<area uuid>:<person uuid>`** because the feed needs both and
the contract's scope is one opaque string by design. `RosterCalendar::scopeFor()`
is the single spelling, so the controller and the feed cannot disagree.

**Past and future never wear the same mark.** A stood watch is `closed` — a
hollow mark — and carries the hue of what the day turned out to be; a future
watch is open and plain. Colouring a plan green would let it read as a record.

## A night watch is two blocks on the day board

**Decision.** `DayBoardService` reads **two** days — the one being drawn and
the one before — and a midnight-crossing watch produces a block on each.

**Why.** A watch running 18:00–06:00 occupies the last quarter of the day it
begins and the first quarter of the next. A board reading only today would
leave every morning before six looking unmanned, and drawing the watch as one
block would be a lie about the day it belongs to. Positions are percentages of
the day, so nothing depends on a pixel.

## A module has no hue

**RULED 2026-09-20.** The sheet declared `--r-acc` and `--r-accT` — an
invented blue — plus `--rb-band` and `--rb-rule`. All four are gone, and so
is the one colour literal (`#000` in two `color-mix()` calls, now
`var(--tx)`). **The sheet now declares no custom property and contains no
literal at all.**

**Why it matters more than it looks.** A hex is the obvious way a module
acquires a palette; a `--r-*` token is the polite one, and the polite one is
worse because it looks deliberate. With `--r-acc` in place, "selected" meant
one thing on a roster screen and another everywhere else.

**Selected wears the HOUSE on-state**, which is *outlined* — `.mchip.on` is
`border-color: acc; color: acc`, not a fill. `.r-pill.on` and `.rsw label.on`
were filling with accent and now outline. `--accT` is no longer spent at all,
because nothing is filled.

`StylesheetVocabularyTest::testTheSheetDeclaresNoColourOfItsOwn` fails the
build on either a declared token or a literal.

## A day holds any number of watches

**RULED by the owner, 2026-09-21.** A ranger may check in and out more than
once in a day. `PersonDay` is one watch, not one day.

**This was a live bug, not a new feature.** `dayIn()` returns
`list<PersonDay>` and nothing ever said one per person — `PresenceReader`
keyed the answer by person uuid and kept whichever came last, so a morning at
the gate vanished the moment somebody checked in on an escort.

**What changed.** `RosteredPerson` carries `list<PersonDay> $watches`. The
folds are stated rather than assumed: **present if ANY watch counts**
(somebody who spent the morning elsewhere and the afternoon at the post was
present), **flagged if ANY is unverified** (one bad claim in three is still a
claim somebody must look at), **the summary leads with the FIRST** (leading
with the latest makes the morning disappear), and a post's silence is measured
from **the newest evidence across every watch**. Today, the Day board, Live
and the station band all render one row per watch with the day's total.

Tested against a stubbed provider — the only way to hand the reader a
two-watch day without this module inventing check-in rows the area owns.

## The swap flow is two house cards, not the archived bar

**Graduated 2026-09-20** (`92ddb50`). The bordered, tinted `.fg-swap` bar is
archive-only; the flow is **"Swap a watch"** (the two cells, then each cost
check as its own `.rln` row, then the actions) and **"Swaps"** (the register,
one row per offer with its state chip — offered · accepted · declined ·
withdrawn).

**Every cost check is STATED, none is ENFORCED.** A duty officer may knowingly
send an offer that breaks the rest rule — a gate that would otherwise stand
empty is sometimes the worse outcome — and what the page must never do is send
one *quietly*. `SwapCostService` answers with sentences; nothing refuses.

**The offer under construction lives in the query string**, not a session: it
is shareable, refreshable and gone the moment somebody navigates away, where a
half-built swap in a session would follow a duty officer around the product.

**`roster.plan` is its own permission.** Moving one watch between two people
on one night is a duty officer's daily work; making it need `roster.manage`
would push every shift change up to whoever rewrites rotations.

## A dashboard card states facts, not sentences

**Design notes, 21 sep.** The explanatory `.rfrag` lines came off all five
tabs — nine of them. What was information rather than commentary moved: the
week grid's shift key is now in the card's `.src` caption. Empty states stay,
because "there is nothing here" is a fact.

## What the design asks for that nothing can answer yet

These are named here rather than invented, because the honest empty state is
the right port until the seam lands.

| The design draws | What it needs | Status |
|---|---|---|
| Presence: verified / unverified / late / offline | `Uhifadhi\Contracts\Area\PresenceProviderInterface` | **landed** — read by `PresenceReader`, never computed |
| The area asking the roster for a person's watches (`/me/roster`) | `Uhifadhi\Contracts\Roster\WatchProviderInterface` | **landed** — `RosterWatches` |
| The *Watch and presence* section on the station record, and the *Roster* block on its configure card | `Uhifadhi\Contracts\Area\StationSectionsInterface` | **landed** — `RosterStationSections` |
| The check-in statuses list on this module's Settings section | the area's `CheckInStatus` read model | **landed** — read through `CheckInStatusService`, written nowhere here |
| The presence card, the attention items and the on-duty tile on the AREA's overview | the area's overview contribution seams | not built yet — step 7 |
| The Live tab's plate, its ranger markers and their ping ages | the area's position feed over its stations layer | **not built** — the roster stores no position and draws no plate; the tab ships the roster underneath and names whose the positions are |
| The swap picker, the cost bar and the offer states on the Week tab | a drawn frame — being graduated from `variants-format/a.html` | **not built**; the swap DOMAIN is complete and tested |
