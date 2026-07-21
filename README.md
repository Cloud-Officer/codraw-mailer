# Draw Mailer Component

`codraw/mailer` layers an "email writer" composition system, localization-aware body rendering,
CSS inlining and a call-to-action email layout on top of `symfony/mailer`.

Instead of building your email in your controller directly, you create an email class that
extends `Symfony\Component\Mime\Email` (or `Symfony\Bridge\Twig\Mime\TemplatedEmail`) and one
or more **writers** for it.

## Symfony integration

The component ships a `MailerIntegration` for `codraw/framework-extra-bundle`; it is configured
under the `mailer` section of that bundle:

```yaml
draw_framework_extra:
    mailer:
        # Use the <title> of the html body as subject when no subject is set
        subject_from_html_title:
            enabled: true

        # Inline css style in the html body (requires pelago/emogrifier)
        css_inliner:
            enabled: true

        # Assign a default from address to email that do not have one
        default_from:
            email: support@example.com
            name: Support # optional
```

All features are disabled by default.

## Email writers

Any service that implements `Draw\Component\Mailer\EmailWriter\EmailWriterInterface` is
registered as a writer (autoconfiguration is enabled). `getForEmails()` must return a map of
method names with priority as the value (if you return the method name as the value, its
priority is 0). The system detects which emails a method applies to from the type of the
method's first argument and calls it when a matching email is sent. The `Envelope` is passed
as the second argument in case you need it.

```php
<?php namespace App\Email;

use Draw\Component\Mailer\EmailWriter\EmailWriterInterface;

class ForgotPasswordEmailWriter implements EmailWriterInterface
{
    public function __construct(private LostPasswordTokenProvider $lostPasswordTokenProvider)
    {
    }

    public static function getForEmails(): array
    {
        return ['compose']; // Or ['compose' => 0];
    }

    public function compose(ForgotPasswordEmail $forgotPasswordEmail): void
    {
        $emailAddress = $forgotPasswordEmail->getEmailAddress();
        $forgotPasswordEmail
            ->to($emailAddress)
            ->subject('You have forgotten your password!')
            ->htmlTemplate('emails/forgot_password.html.twig')
            ->context([
                'token' => $this->lostPasswordTokenProvider->generateToken($emailAddress),
            ])
        ;
    }
}
```

Sending the email stays a one-liner wherever you need it:

```php
$mailer->send(new ForgotPasswordEmail($emailAddress));
```

Composition is hooked into the mailer via a `Symfony\Component\Mailer\Event\MessageEvent`
listener, so it works with direct sending and with Messenger.

By convention, create an `Email` folder for your email classes and their writers.

See `Draw\Component\Mailer\EmailWriter\DefaultFromEmailWriter` for an example of a writer
that is called for every `Email` sent.

## Localized emails

Emails implementing `Draw\Component\Mailer\Email\LocalizeEmailInterface` (see
`LocalizeEmailTrait`) are composed and rendered with the translator switched to the email's
locale, then the previous locale is restored.

## Call to action email

`Draw\Component\Mailer\Email\CallToActionEmail` is a `TemplatedEmail` with a ready-made
responsive layout (`@draw-mailer/Email/Layout/call_to_action.html.twig`) supporting a subject,
title, content and call-to-action button, all resolved through the translator.

## Test command

```shell
bin/console draw:mailer:send-test-email to@example.com
```

Sends a simple test email through the configured mailer.
