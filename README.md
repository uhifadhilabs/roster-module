# uhifadhi/roster-module

Who is on duty, where and when: duty rosters, daily work plans, on-duty
presence and the station registry those shifts are kept at. A
[uhifadhi](https://github.com/uhifadhilabs) module bundle.

> **Status: infrastructure only.** This repository currently contains the
> bundle, its configuration seam and its module registration — and no domain
> model. See [What is not here yet](#what-is-not-here-yet).

## Contents

- [Charter](#charter)
- [What is not here yet](#what-is-not-here-yet)
- [What is here](#what-is-here)
- [Installation](#installation)
- [Configuration](#configuration)
- [Development](#development)
- [License](#license)

## Charter

**Planned work, not performed work.** A roster says who was *meant* to be
somewhere; a patrol says what was *done*. This module owns the plan and the
presence around it, and never duplicates the patrols module:

- **Duty rosters** — who is assigned to what duty, over a period.
- **Daily work plans** — the day's intent for a team or a station.
- **On-duty presence** — who is on shift right now, and the check-ins that
  say so.
- **Station registry** — the stations, posts and gates shifts are kept at,
  and which of them are staffed.

**Departments are a lens, never a fence.** A department attaching this module
changes what a team *sees first* — it never gates what data exists or who may
read it. Rosters belong to the area; the department view is a reading of them.

**Not patrols.** Anything that records a route walked, an observation made or
a track logged belongs in `uhifadhi/patrol-module`. Rosters may be what a
patrol was scheduled against — the link is a reference, never a copy.

## What is not here yet

**No entities, no repositories, no screens** — deliberately. The roster UI
design has not been ruled on, and in this project **the design drives the data
model**: the fields a design needs are the fields that get built, and nothing
is invented ahead of that ruling. Guessing at a shift schema now would mean
either shrinking the design to fit an invented model, or migrating the model
away the week the design lands.

What arrives with the design ruling:

- the roster/shift/station entities and their repositories
- the planning and presence screens, and the routes they need
- the module's widgets and presets on the host's widget framework — including
  the area overview's presence, on-duty and stations widgets, which are
  honestly absent until this module can answer them
- the module's declared permissions (declared alongside the routes that check
  them, never before)
- any PostGIS geometry the station registry needs, and the
  `fundistadi/postgis-bundle` dependency that carries it
- a real database in the test suite and in CI

Until then `RosterModuleProvider::entryRoute()` returns `null`, so the host
renders the module through its generic module page.

## What is here

| Piece | File |
|---|---|
| The Symfony plug | `src/UhifadhiRosterBundle.php` |
| Config tree (`roster:`) | `src/DependencyInjection/RosterConfiguration.php` |
| Catalogue registration | `src/Module/RosterModuleProvider.php` |
| Static service wiring (empty, ready) | `config/services.php` |
| Test host app | `tests/Integration/TestKernel.php` |

The bundle maps its own entity directory (`src/Entity`, empty for now), so a
host will never need to write a doctrine mappings block for roster tables.

## Installation

Not yet — the host installs this module once the domain lands. For the record,
the steps will be:

```bash
composer require uhifadhi/roster-module
```

The bundle registers via Flex (`"type": "symfony-bundle"`), which adds
`Uhifadhi\Roster\UhifadhiRosterBundle` to `config/bundles.php`.
No further host wiring is required: entity mapping is prepended by the bundle,
and the module reaches the catalogue through the `uhifadhi.module` tag.

## Configuration

```yaml
# config/packages/roster.yaml
roster:
    module_category: operations   # catalogue category for the module tile
    dev_tools: false        # dev-only tooling; enable via when@dev / when@test
```

Both keys have defaults; the tree is closed, so an unknown key fails loudly
rather than being ignored. A deployment's shift and station vocabulary will be
configured here too — after the design ruling, as deployment vocabulary rather
than code.

## Development

```bash
composer install
composer check      # cs:check -> phpstan (max) -> phpunit
```

- PHP 8.4+, PHPStan level **max**, php-cs-fixer `@Symfony` + `@Symfony:risky`.
- **Tests first, always.** The tests in this repo were written before the code
  they cover, and that does not relax when the domain arrives.
- The integration suite boots a real kernel (`tests/Integration/TestKernel.php`)
  and opens no database connection — there is nothing to persist yet. The test
  database URL it declares
  (`postgresql://app:app@127.0.0.1:5434/roster_bundle_test`) is the shape the
  real one will take on the fundi cluster.

## License

**AGPL-3.0-or-later** — see [LICENSE](LICENSE): the same license as the
uhifadhi host this module plugs into. Use, modify and self-host freely; if you
offer a modified version to users over a network, they are entitled to the
source of what they're running.
