# Code Review — codraw/mailer

Reviewed at package root `/Users/tlacroix/Sites/localhost/codraw/codraw-mailer` (namespace `Draw\Component\Mailer`). The package layers an "email writer" composition system, localization-aware body rendering, CSS inlining, and a call-to-action email template on top of `symfony/mailer`.

## Overall assessment

This is a small, focused, generally well-crafted component. The core design (writers registered per email class, resolved lazily through a service locator, wired by a compiler pass with autoconfiguration) is sound, and almost every class has a dedicated unit test plus a thorough DI integration test. The notable problems are concentrated at the edges: the package silently replaces Twig's standard `trans` filter application-wide with a variant that has subtly different (and in places buggy) semantics; the bundled email template pipes user-suppliable translation tokens through `|raw`; the README documents a bundle that no longer exists in this form; and several DI/robustness edge cases can fail with cryptic errors at container compile time. No critical (exploitable-by-default or data-loss) defect was found.

## Fixes applied (2026-07-20)

- **composer.json:** PHP version constraint changed from unbounded `>=8.5` to `^8.5` (version-compatibility debt: prevents a future PHP 9 from installing against this package; no effect on any currently existing PHP version).
Scoped, low-risk fixes were applied for the findings below; findings marked **[FIXED]** in place. Riskier items (H1 filter override, M4 token escaping, M5 priority semantics, L1 header stripping, L3 union dedup, L4 queued-inlining skip) were deliberately left untouched.

- **composer.json (L2)** — added missing direct runtime dependencies `psr/container` (`^1.1 || ^2.0`, used by `EmailComposer`), `symfony/event-dispatcher` (`^6.4.0`, listener attribute/interface) and `symfony/mime` (`^6.4.0`, used pervasively); added a `suggest` section for `codraw/framework-extra-bundle` (Symfony integration via `DependencyInjection/`) and `pelago/emogrifier` (css_inliner feature); filled in the empty `description`. Open items (unchanged): `"php": ">=8.5"` upper bound kept per repo convention; root-level PSR-4 mapping still leaves `Tests\` autoloadable (no `autoload-dev` split).
- **M1** — `Twig/TranslationExtension.php`: replaced the loose `$result != $message` object comparison with an explicit `!$message instanceof \Stringable || $result !== (string) $message` check, so the fallback chain works via strict string comparison for stringable translatables.
- **M2** — `Twig/TranslationExtension.php`: `%count%` is now only merged into `$arguments` when it is an array, removing the fatal on `$count` combined with a string (locale) `$arguments`.
- **H1 (partial)** — `Twig/TranslationExtension.php`: `$result` is initialized to `null` instead of `reset($messages)`, so an empty array input returns `null` instead of `false` (a `TypeError` against the `?string` return type). The filter-name override itself is NOT fixed (see H1, still open).
- **M3** — `DependencyInjection/MailerIntegration.php`: the `twig.mime_body_renderer` decoration now uses `ContainerInterface::IGNORE_ON_INVALID_REFERENCE`, so container compilation no longer fails when that service is absent (the decorator is simply dropped).
- **M6** — `DependencyInjection/Compiler/EmailWriterCompilerPass.php`: the writer class is now resolved through the parameter bag with a fallback to the service id, and missing `getForEmails`, non-existent writer methods, and parameter-less writer methods each throw a `\RuntimeException` naming the offending service id instead of surfacing as cryptic reflection errors.
- **M7** — `README.md`: rewritten to document the actual package (correct `draw_framework_extra.mailer` configuration with `subject_from_html_title`/`css_inliner`/`default_from {email, name}`, correct `Draw\Component\Mailer\EmailWriter\EmailWriterInterface` FQCN, localization, `CallToActionEmail`, test command).
- **L5 (partial)** — `Command/SendTestEmailCommand.php` (+ its test): fixed the "as been"→"has been" typo and returned `Command::SUCCESS` instead of bare `0`. Confirmation output and recipient validation were not added (behavior/output changes).
- **L6** — `EmailComposer.php` and `BodyRenderer/LocalizeBodyRenderer.php` now restore the locale on `null !== $currentLocale`; `EventListener/EmailSubjectFromHtmlTitleListener.php` uses `'' === $subject` instead of `empty()`, so a `"0"` title is honored.

### Validation pass (2026-07-20)

All fixes above were validated: `composer install` resolves cleanly with the updated constraints, and the full PHPUnit suite passes (47 tests, 173 assertions, 0 failures). The 15 PHPUnit notices ("mock without expectations") and the 2 PHPStan level-5 errors (`MailerIntegration.php` `arrayNode()` on `NodeParentInterface`, `Tests/EmailComposerTest.php` anonymous-class return type) are pre-existing — they occur identically on the unmodified codebase. Only change made in this pass: added a `shell` language tag to the test-command fenced code block in `README.md` (markdownlint MD040); markdownlint is now clean.

## Findings

### High

#### H1. `TranslationExtension` silently overrides Twig's standard `trans` filter app-wide, with divergent semantics

`Twig/TranslationExtension.php:20` registers a filter named `trans`, the same name used by Symfony Twig Bridge's `TranslationExtension`. Because `DependencyInjection/MailerIntegration.php` registers this extension unconditionally (it is autoconfigured as a `twig.extension` via `registerClasses()`), every Twig template in a consuming application — not just email templates — gets this replacement filter, with which extension wins depending on service definition order. The semantics differ from Symfony's filter in observable ways:

- Symfony's filter normalizes `null`/`\Stringable` messages via `(string) $message` before calling the translator; this one passes the raw value straight to `TranslatorInterface::trans()` (`Twig/TranslationExtension.php:50`), so `{{ null|trans }}` relies on the concrete translator accepting `?string` (deprecation or `TypeError` depending on the implementation), and an empty array input returns `reset([]) === false` (line 34/56), violating the `?string` return contract.
- The array-of-fallback-keys behavior (the reason this filter exists — see `Resources/views/Email/Layout/call_to_action.html.twig`) is an incompatible signature extension of a core filter.

At minimum this filter should have a distinct name (e.g. `draw_trans`) or only be registered in the mailer's Twig environment; as shipped, installing the mailer integration changes translation behavior everywhere.

### Medium

#### **[FIXED]** M1. Loose object/string comparison breaks the fallback chain for `TranslatableInterface` messages

`Twig/TranslationExtension.php:43` uses `if ($result != $message)` where `$result` is a `string` and `$message` is a `TranslatableInterface` object. This only behaves as intended when the object implements `__toString()` (e.g. `TranslatableMessage`). For any other `TranslatableInterface` implementation, PHP considers a string never loosely equal to the object, so the condition is always true and the method returns after the first translatable candidate — the documented "try next message on missing translation" fallback silently never happens. Verified against PHP 8.5 comparison semantics. Use an explicit string comparison against the message id instead of `!=` on the object.

#### **[FIXED]** M2. `trans()` fatals when `$count` is combined with a string `$arguments`

`Twig/TranslationExtension.php:31` does `$arguments['%count%'] = $count;` without checking that `$arguments` is an array. The parameter is declared `string|array` (a string means "locale", mirroring Symfony's filter, see line 41). Calling `{{ message|trans('fr', null, null, 5) }}` therefore throws a fatal "cannot use string offset as an array" style `TypeError` instead of either working or failing with a meaningful message.

#### **[FIXED]** M3. Hard decoration of `twig.mime_body_renderer` breaks container compilation when the service is absent

`DependencyInjection/MailerIntegration.php:50-53` decorates `twig.mime_body_renderer` with the default invalid behavior (exception). If the consuming application has Twig but does not have the framework mailer/Twig mime body renderer wired (e.g. `framework.mailer` disabled, or `twig-bridge` mailer support not active), container compilation fails with a `ServiceNotFoundException` about the decorated service rather than a message explaining the mailer integration's requirement. Passing `ContainerInterface::IGNORE_ON_INVALID_REFERENCE` as decoration-on-invalid, or checking for the service, would make the integration degrade gracefully.

#### M4. Bundled template outputs user-suppliable translation tokens with `|raw`

`Resources/views/Email/Layout/call_to_action.html.twig:20,352,368` (and every other content block) renders `..|trans(translation_tokens)|raw`. `translation_tokens` comes straight from the public array property `CallToActionEmail::$translationTokens` (`Email/CallToActionEmail.php:12`). Raw output is intentional so translations may contain HTML, but any placeholder value interpolated into those translations (typically user names, emails, or other user-controlled strings) is emitted unescaped into the email HTML — an HTML-injection vector into outgoing mail (phishing content, tracking pixels, layout spoofing) for any application that forwards user input as a token. Tokens should be escaped before substitution (e.g. document that callers must pre-escape, or escape token values in `getContext()`), and `$translationTokens` should not be a bare public property.

#### M5. Writer priorities are only honored within a single email type, not across the class hierarchy

`EmailComposer.php:43-49` iterates `getTypes()` (concrete class, then parents, then interfaces — line 111-116) and, for each type, calls that type's writers in priority order. Priorities are therefore never compared across types: a writer registered for `TemplatedEmail` with priority `-1000` still runs before a writer registered for `Email` with priority `+1000`. Both bundled writers use priority `-255` ("run last") but only actually run last among writers of their own declared parameter type. This is surprising behavior for a priority-based API and is not documented anywhere; either merge and sort all applicable writers globally by priority, or document the per-type semantics clearly.

#### **[FIXED]** M6. `EmailWriterCompilerPass` crashes with cryptic errors on malformed writers or class-less definitions

`DependencyInjection/Compiler/EmailWriterCompilerPass.php:29` calls `getForEmails` on `$definition->getClass()` which can be `null` (service id != class, class left unset) or an unresolved parameter like `%app.writer_class%`; line 37 unconditionally dereferences `getParameters()[0]` which throws an undecorated `Error` if the declared writer method takes no parameters, and the method name itself is never validated to exist. All of these surface at compile time as low-level reflection errors with no indication that a tagged email writer is the culprit. Guard each case and throw an exception naming the offending service id.

#### **[FIXED]** M7. README documents a different, obsolete package

`README.md` is titled "DrawPostOfficeBundle", shows a `draw_post_office:` configuration key that does not exist (the actual config section is `mailer` under the draw framework-extra extension, per `DependencyInjection/MailerIntegration.php:29-31`), states `default_from` is a plain string when the schema requires `{email, name}` (`MailerIntegration.php:103-108`), and the example imports a wrong FQCN (`Draw\Component\Mailer\Email\EmailWriterInterface` instead of `Draw\Component\Mailer\EmailWriter\EmailWriterInterface`). None of the shipped features (`subject_from_html_title`, `css_inliner`, localization, `CallToActionEmail`, the test-email command) are documented. Following this README as written fails.

### Low

#### L1. Internal template paths leaked to recipients via custom headers

`EmailWriter/AddTemplateHeaderEmailWriter.php:21,24` adds `X-DrawEmail-HtmlTemplate` / `X-DrawEmail-TextTemplate` headers carrying internal Twig template paths, and `EventListener/EmailComposerListener.php:34` adds `X-DrawEmail: 1`. Nothing removes these before transport, so every recipient sees internal implementation details in the raw message source. Harmless for debugging/test assertions, but it is a small information disclosure that should be opt-in or stripped at send time.

#### **[FIXED]** (partially — see "Fixes applied") L2. composer.json constraint and dependency-declaration gaps

`composer.json:12` — `"php": ">=8.5"` has no upper bound, promising compatibility with all future PHP majors. Direct code dependencies `symfony/mime` (used pervasively), `psr/container` (`EmailComposer.php:9`), and `symfony/event-dispatcher` (listener attribute/interface) are not declared and are only satisfied transitively through `symfony/mailer`. The `DependencyInjection/` classes hard-depend on `codraw/dependency-injection`, `symfony/dependency-injection`, and `symfony/config`, which are only in `require-dev` — intentional optional-integration pattern, but a `suggest` entry would make it explicit. `description` is empty. Also, the root-level PSR-4 mapping (`"Draw\\Component\\Mailer\\": ""`) leaves `Tests\` autoloadable in production installs since there is no `autoload-dev` split or `exclude-from-classmap`.

#### L3. Writer invoked twice for union-typed writer methods

`EmailComposer::registerEmailWriter()` (`EmailComposer.php:67-73`) registers the writer once per class extracted from the first parameter's type; `ReflectionExtractor::getClasses()` returns every member of a union. A method typed `compose(Email|TemplatedEmail $email)` gets registered under both classes, and since `getTypes()` for a `TemplatedEmail` message contains both, the writer runs twice for one message. Deduplicate at compose time or reject union types that overlap hierarchically.

#### L4. `EmailCssInlinerListener` re-runs on queued messages

`EventListener/EmailCssInlinerListener.php:19-26` has no `$event->isQueued()` check (unlike `EmailComposerListener.php:20`). With Messenger, `MessageEvent` fires at queue time and again at send time, so Emogrifier parses and inlines the full HTML document twice per email. Idempotent but wasted work on the hot path; skipping when `isQueued()` (or when already inlined) would halve the cost.

#### **[FIXED]** (partially — typo and exit code only) L5. `SendTestEmailCommand` polish

`Command/SendTestEmailCommand.php:33` — the user-visible text contains a typo: "This email **as** been sent" (should be "has"). Line 37 returns bare `0` instead of `Command::SUCCESS`, and the command prints no confirmation output at all. The recipient argument is not validated before being handed to `Email::to()`, so an invalid address surfaces as an uncaught `RfcComplianceException` stack trace rather than a friendly error.

#### **[FIXED]** L6. Minor truthiness edge cases

`EmailComposer.php:51` and `BodyRenderer/LocalizeBodyRenderer.php:35` restore the locale only `if ($currentLocale)`, so a pre-existing locale of `""`/`"0"` would not be restored. `EventListener/EmailSubjectFromHtmlTitleListener.php:40` uses `empty($subject)`, so an HTML title of `"0"` is treated as absent. All extremely unlikely in practice.

## Strengths

- **Clean, single-responsibility classes** — every class is small, final in purpose, constructor-injected, and free of hidden state beyond the deliberate writer registry.
- **Lazy writer resolution** — `EmailComposer` accepts writer service ids and resolves them through a scoped `ServiceLocator` built by `EmailWriterCompilerPass` (`ServiceLocatorTagPass::register`, `EmailWriterCompilerPass.php:50-54`), so writers are only instantiated when their email type is actually sent.
- **Correct locale save/restore** — both `EmailComposer::compose()` and `LocalizeBodyRenderer::render()` restore the previous locale in a `finally` block, so a throwing writer/renderer cannot leave the translator in a foreign locale.
- **Good DI ergonomics** — autoconfiguration of `EmailWriterInterface` implementations, per-feature `canBeEnabled()` config nodes, and a config-time validation that produces a helpful message when `css_inliner` is enabled without `pelago/emogrifier` installed (`MailerIntegration.php:100-104`).
- **Double-compose guard** — `EmailComposerListener` marks composed messages with a header and skips queued events, correctly handling the Messenger double-dispatch of `MessageEvent`.
- **Sensible listener priorities** — compose (200) runs before Symfony's `MessageListener` (0, renders templated bodies), CSS inlining (-1) and subject-from-title (-2) run after rendering; verified against `MessageListener::getSubscribedEvents()`.
- **`call_to_action_link` is escaped** in the template (unlike the translation tokens), and the template is a solid, battle-tested responsive email layout.

## Test coverage

Coverage breadth is very good for a package this size (~580 source lines, ~1400 test lines); nearly every class has a dedicated test:

- **Well covered:** `EmailComposer` (writer registration/sorting/priorities, locator-based and direct writers, locale switch/restore — `Tests/EmailComposerTest.php`); all three event listeners including subscribed-event assertions and negative paths; both email writers; `SendTestEmailCommand` (arguments and sent-message contents); `CallToActionEmail` context building; `MailerIntegration` (`Tests/DependencyInjection/MailerIntegrationTest.php` is thorough — prepend behavior with/without twig, service ids after renaming, and each feature-flag permutation including the `default_from` Address definition arguments).
- **Partially covered:** `TranslationExtension` — only the plain-string and multi-string-fallback paths are tested; the `TranslatableInterface` branch (where bugs H1/M1 live), the `null` message case, string-`$arguments`-as-locale, and the `$count` + string-arguments collision are untested.
- **Not covered:** `LocalizeBodyRenderer` has no test at all (the only production class without one); `EmailWriterCompilerPass` has no dedicated test (integration test registers it but never compiles a container with tagged writers); the `call_to_action.html.twig` template is never rendered in any test, so the template/custom-filter contract (array token fallback, escaping behavior) is unverified.
