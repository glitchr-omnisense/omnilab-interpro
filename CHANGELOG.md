# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Added

- Omnilab InterPro: an InterPro entry (IPR000971) or a Pfam family (PF00042) - its name, type,
  description, member signatures, GO terms, protein and structure counts, Pfam clan, representative
  structure, overlapping entries and hierarchy - a UniProt protein with the InterPro entries and
  Pfam families it matches, by type and position, as related things, and a search of InterPro
  entries, through the InterPro API, no key needed.

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/interpro` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.
