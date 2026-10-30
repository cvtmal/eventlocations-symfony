# Code quality

## Before calling a change done

Run, in this order, and fix until each passes:

1. `make cs-fix` — rewrites style (strict types, imports, snake_case test names).
2. `make lint` — `cs-check` + PHPStan level 8 with the phpat architecture rules. This is exactly what CI runs.
3. `make test` — all four suites against a freshly migrated `app_test`.

## PHPStan

- No baseline file, no `ignoreErrors`, no `@phpstan-ignore` without a comment that explains why the error is wrong, and only after the user agrees.
- Architecture (phpat) errors mean the code is in the wrong place or shape. Move or reshape the class; never loosen a rule to make an error go away.
- Use `/fix-phpstan` for a structured pass over a failing run.

## Style

- PHP-CS-Fixer with `@Symfony` + `@Symfony:risky`, `declare_strict_types`, snake_case PHPUnit methods. Do not hand-format against it.
- Prefer constructor property promotion, `readonly` where the class allows, and native types over PHPDoc types.
- Keep comments for the "why"; the code says the "what".

## Tests

- New behaviour comes with a test in the narrowest suite that can prove it (Unit → Integration → Application).
- PHPUnit fails on deprecations, notices and warnings triggered by `src/`; fix the cause, do not silence it.
