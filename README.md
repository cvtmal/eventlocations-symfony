# deine-eventlocation.ch

A Swiss listing of event venues: company events, seminars, birthdays, apéros, Christmas parties, general meetings and weddings. Visitors search venues with filters, save favourites without a login and send an inquiry; venues get a free entry and can buy a paid tier; two staff members run the back office, and venue managers keep their own entry in `/konto`. German only.

Symfony 7.4 on PHP 8.5, PostgreSQL 18, Docker Compose. It was forked once from the sister site hochzeitslocation.ch and shares nothing with it at runtime.

## Quick start

Docker is the only requirement; never run `composer` or `bin/console` on the host.

```sh
make init      # build, install, migrate, start the stack
make seed      # load the demo data
```

The app answers on http://localhost:8080 (`/health` for a check) and the mail UI on http://localhost:8025. This project's own lane ports are in `CLAUDE.md`.

```sh
make test      # PHPUnit and Vitest
make lint      # coding standards, PHPStan level 8 with the architecture rules, translations, admin lint
make cs-fix    # fix the coding standards
```

## Where to read

- `docs/product.md`: what the product does and where it is going.
- `docs/rpi-cycles.md`: settled decisions, requirements, the cycle order and Wave G (go to market).
- `CLAUDE.md` and `.claude/rules/`: how the code is built (commands, architecture, conventions).
- `docs/operations.md`: the production runbook (the sister site's, until the new host is chosen).
- `KICKOFF.md`: the fork brief, superseded and kept for history.
