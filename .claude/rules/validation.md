---
paths:
  - "src/**/Form/**/*.php"
  - "src/**/Controller/**/*.php"
  - "src/**/CommandHandler/**/*.php"
  - "src/**/Console/**/*.php"
  - "templates/**/*.twig"
---

# Validation

Validation is split across three layers. Put each check in the first layer that can decide it.

## 1. Form (input shape)

- Each form binds to a form data class in `<Module>/Form` that carries the Symfony Validator constraints (`NotBlank`, `Length`, `Email`, …).
- The controller handles the request; when the form is submitted but invalid, re-render the template with HTTP **422**:

  ```php
  if ($form->isSubmitted() && !$form->isValid()) {
      return $this->render('…', ['form' => $form], new Response(status: 422));
  }
  ```

- Only after `isValid()` does the controller build a command from the data class.
- Console commands have no form: validate each argument with `ValidatorInterface::validate($value, [constraints])` and return `Command::FAILURE` on violations.

## 2. Handler (business rules)

- Rules that need state (an inquiry for an unpublished venue, the Merkliste is full) are checked in the command handler.
- Violations throw a `DomainException` subclass with a named constructor. The controller catches the specific exception and turns it into a flash message or a form error.
- Commands carry no validator attributes.

## 3. Database (integrity)

- Uniqueness, foreign keys and value ranges are also enforced by constraints (`uk_`, `fk_`, `chk_`) so concurrent requests cannot break them.
- When a unique constraint fires, catch `UniqueConstraintViolationException` in the handler and rethrow a `DomainException`.
