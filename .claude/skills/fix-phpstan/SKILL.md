---
name: fix-phpstan
description: Make `make phpstan` pass by fixing the root cause of each error, including phpat architecture violations. Use when PHPStan or `make lint` fails, or when asked to clean up static-analysis errors.
---

# Fix PHPStan errors

1. **Run it.** `make phpstan` (the stack must be up: `make up`). Save the full output.

2. **Group the errors** by identifier (the 🪪 line, e.g. `argument.type`, `phpat.testControllersAreFinal`) and then by file. Fix one group at a time; many errors often share one cause.

3. **Fix the root cause.**
   - Type errors: tighten or correct native types first; add a PHPDoc type only where PHP cannot express it (array shapes, generics).
   - Nullable access: handle the `null` case explicitly instead of asserting it away.
   - `phpat.*` errors are architecture violations. Move the class to the right layer or module, or change its shape (make it final/readonly, extend the right base, dispatch a command instead of calling a handler). Read `.claude/rules/php-architecture.md`, `.claude/rules/cqrs.md` and `.claude/rules/read-model.md`. **Never weaken, delete or exclude a rule** to get green.

4. **No suppressions.** Do not add a baseline, `ignoreErrors` or `@phpstan-ignore` unless the user explicitly agrees, and then with a comment that says why the error is a false positive.

5. **Repeat** `make phpstan` until it reports `[OK] No errors`.

6. **Finish** with `make lint` (adds the coding-standard check) and `make test` if any production code changed.
