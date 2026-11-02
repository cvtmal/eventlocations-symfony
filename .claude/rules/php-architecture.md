---
paths:
  - "src/**/*.php"
  - "tests/**/*.php"
---

# PHP architecture

## Every file

- Starts with `declare(strict_types=1);` (PHP-CS-Fixer adds it).
- One class per file, namespace mirrors the path under `src/` (`App\`) or `tests/` (`App\Tests\`).

## Modules and layers

Code lives in `src/<Module>/<Layer>/`. Modules: `Geo`, `Media`, `Venues`, `Search`, `Favorites`, `Inquiries`, `Seo`, the back office's `Admin`, `Cms`, `Crm`, the location managers' `Portal`, and `Shared` for cross-cutting building blocks.

| Layer | Holds |
|---|---|
| `Controller` | HTTP entry points |
| `Command` | Command DTOs dispatched on the bus |
| `CommandHandler` | One handler per command |
| `Entity` | Doctrine entities |
| `Repository` | Doctrine repositories (the write side's handlers use them) |
| `ReadModel` | DBAL query services and the `final readonly` view objects they return |
| `ValueObject` | `final readonly` values, enums for fixed vocabularies |
| `Exception` | Business errors of the module |
| `Form` | Form types and form data classes |
| `EventListener` | Kernel and Doctrine event listeners |
| `Event` | A module's published events and the small service that publishes them |
| `Console` | Console commands (`#[AsCommand]`, invokable) |
| `Twig` | Twig filters and functions, `#[AsTwigFilter]` / `#[AsTwigFunction]` |
| `Mailer` | Builds and sends a module's emails |
| `Extension` | Back-office extension points and their implementations |
| `Security` | Security user, provider, checkers, authenticators |
| `Messenger` | Bus middleware and message contracts |

Do not invent new layer names without updating `tests/Architecture/` — the rules match these folder names.

## Class modifiers (checked by `ClassModifiersTest`)

| Kind | final | readonly | Other |
|---|---|---|---|
| Controller | yes | — | extends `AbstractController` |
| Command | yes | yes | |
| CommandHandler | yes | — | invokable, implements `CommandHandlerInterface` |
| Exception | yes (abstract bases excepted) | — | extends `App\Shared\Exception\DomainException` |
| Entity | **no** | **no** | Doctrine proxies and hydrates them |
| Repository | — | — | extends `ServiceEntityRepository` |
| ReadModel | yes | — | |
| ValueObject | yes | yes | enums excepted |
| EventListener | yes | — | |
| Event | yes | yes | |
| Console | yes | — | invokable, `#[AsCommand]` |
| Twig | yes | — | |
| Mailer | yes | — | |
| Extension | yes (interfaces and enums excepted) | — | |
| Security | yes | — | |
| Messenger | yes (interfaces excepted) | — | |

Anything else (services, value objects, form types) is `final` by default.

## Layer isolation (checked by `LayerIsolationTest`)

- Controllers never reference or instantiate handlers; they dispatch commands.
- Handlers never reference controllers.
- Commands never reference entities or repositories — they carry scalars and IDs.
- Entities never reference controllers, handlers or commands.
- Console commands never reference handlers; they dispatch commands.

## Infrastructure access (checked by `InfrastructureAccessTest`)

- Controllers never use `EntityManagerInterface`.
- Console commands never use `EntityManagerInterface`.
- Read models never use entities or `EntityManagerInterface`; they read with hand-written SQL into view objects (`read-model.md`).
- Module code never references `App\Tests`, `App\DataFixtures`, `App\Story` or Foundry.

## Module dependencies (checked by `ModuleBoundariesTest`)

| Module | May depend on |
|---|---|
| Shared | — |
| Geo | Shared |
| Media | Shared |
| Venues | Geo, Media, Shared |
| Search | Venues, Geo, Media, Shared |
| Favorites | Search, Venues, Shared |
| Inquiries | Venues, Shared |
| Seo | Search, Venues, Geo, Shared |
| Admin | Shared |
| Cms | Admin, Venues, Geo, Media, Shared |
| Crm | Admin, Venues, Geo, Inquiries, Portal, Shared |
| Portal | Admin, Cms, Venues, Geo, Media, Shared |

If a feature seems to need a forbidden direction, put an interface in the lower module (or `Shared`) and implement it in the higher one. Changing the matrix is a deliberate decision: update the table here, in `CLAUDE.md` and in the test together.

## Exceptions

```php
final class VenueNotFound extends DomainException
{
    public static function forSlug(string $slug): self
    {
        return new self(sprintf('Venue "%s" does not exist.', $slug));
    }
}
```

Throw with a named constructor; keep message text inside the exception class.
