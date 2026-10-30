---
paths:
  - "src/**/ReadModel/**/*.php"
  - "src/**/Controller/**/*.php"
  - "src/**/Twig/**/*.php"
---

# Read models

Public pages read through a module's `ReadModel` layer, never through entities.

- A query class lives in `<Module>/ReadModel`, is `final`, injects `Doctrine\DBAL\Connection` and runs hand-written SQL.
- It returns `final readonly` view objects (or lists of them) that carry exactly what the template renders.
- It never hydrates entities, never uses `EntityManagerInterface` or repositories, and never writes. `InfrastructureAccessTest` enforces this.
- Repositories remain for the handlers on the write side.
- Keep the SQL in the query class, bind every parameter, and name the class after the question it answers (`VenueListing`, `VenueDetail`).
- Every query has an integration test that runs it against `app_test`.

Source: S2 "Reads" in `docs/rpi-cycles.md`; the read-model idea from DEP `apps/attendances/src/ReadModel/`.
