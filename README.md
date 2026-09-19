# uhifadhi/roster-module

Who is due on watch, where and when: the rotation a post or a team runs, the
duties it generates, the swaps and absences that change them, and the on-duty
presence read back from the area. A [uhifadhi](https://github.com/uhifadhilabs)
module bundle.

> **Status: in build.** The domain was ruled on 20 September 2026 and this
> repository is being built against that ruling. See
> [What is built](#what-is-built) for where it has got to.

## Contents

- [Charter](#charter)
- [What this module owns — and what it does not](#what-this-module-owns--and-what-it-does-not)
- [What is built](#what-is-built)
- [Installation](#installation)
- [Configuration](#configuration)
- [Development](#development)
- [License](#license)

## Charter

**Planned work, not performed work.** A roster says who was *meant* to be
somewhere; a patrol says what was *done*. This module owns the plan, and the
one place the two touch is a person on a watch who is out on a patrol — printed
with the patrols module's own id and never copied.

**Presence is derived, never typed.** There is no on-duty field on any form in
this module, for any role, including an administrator. Who is actually at a
post is read from the area's check-ins and the pings that followed them.

## What this module owns — and what it does not

| | Owner |
|---|---|
| Station, posting, check-in, position | **the area** |
| Person, position, department | **team** |
| Rotation — cycle, slots per shift, pool, horizon | **roster** |
| Duty — one watch, one station, one day | **roster** |
| Swap — two cells, accepted on the handset | **roster** |
| Absence — person, from, to, kind, recorded by | **roster** |
| The watch a station expects — shifts, silence window, catchment, pool | **roster**, contributed onto the area's station record |
| Presence — who is here now, verified, late, offline | **read** from the area's presence seam |

Keeping the three apart is what lets a ranger cover another station for a
fortnight without the org chart quietly rewriting itself.

## What is built

| Piece | File |
|---|---|
| The Symfony plug | `src/UhifadhiRosterBundle.php` |
| Config tree (`roster:`) | `src/DependencyInjection/RosterConfiguration.php` |
| Catalogue registration | `src/Module/RosterModuleProvider.php` |
| Static service wiring | `config/services.php` |
| Test installation | `tests/Integration/TestKernel.php` |

The bundle maps its own entity directory and serves its own assets, so an
installation writes no doctrine block and no asset path for it.

## Installation

```bash
composer require uhifadhi/roster-module
php bin/console doctrine:migrations:migrate
php bin/console registry:sync
php bin/console asset-map:compile
php bin/console cache:clear
```

The bundle registers via Flex (`"type": "symfony-bundle"`), which adds
`Uhifadhi\Roster\UhifadhiRosterBundle` to `config/bundles.php`. Entity mapping
and the migrations path are prepended by the bundle; the module reaches the
catalogue through the `uhifadhi.module` tag.

## Configuration

```yaml
# config/packages/roster.yaml
roster:
    module_category: operations   # catalogue category for the module tile
    dev_tools: false              # dev-only tooling; when@dev / when@test

    # The named windows a watch can be stood in. A station declares which of
    # them it runs; a window may cross midnight, and a duty belongs to the
    # calendar day its watch BEGINS on.
    shifts:
        - { key: day,    label: Day,         start: '06:00', end: '18:00' }
        - { key: night,  label: Night,       start: '18:00', end: '06:00' }
        - { key: office, label: Office,      start: '07:30', end: '16:30' }
        - { key: radio,  label: Radio night, start: '18:00', end: '06:00' }

    # The values a new area setting, station watch or rotation STARTS at.
    # What any of them actually runs at afterwards is its own stored value,
    # edited on a screen — nothing here is read at display time.
    defaults:
        ping_interval_minutes: 30
        silence_window_minutes: 120
        offline_after_minutes: 1440
        catchment_metres: 1500
        horizon_days: 42
```

Every key has a default and the tree is closed, so an unknown key fails loudly
rather than being ignored.

## Development

```bash
composer install
composer check      # cs:check -> phpstan (max) -> phpunit
```

- PHP 8.4+, PHPStan level **max** over `src`, `tests` and `migrations`,
  php-cs-fixer `@Symfony` + `@Symfony:risky`.
- **Tests first, always.**
- The integration suite boots a real installation
  (`tests/Integration/TestKernel.php`) against a real PostGIS database at
  `postgresql://app:app@127.0.0.1:5434/roster_bundle_test`. Never SQLite.

## License

**AGPL-3.0-or-later** — see [LICENSE](LICENSE): the same license as the
uhifadhi core this module plugs into. Use, modify and self-host freely; if you
offer a modified version to users over a network, they are entitled to the
source of what they're running.
