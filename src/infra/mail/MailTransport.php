<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\infra\mail;

/** Delivers one message. SmtpTransport in production, a recording fake in tests. */
interface MailTransport {

    /** @throws MailException */
    public function send(string $from, string $to, string $subject, string $textBody, ?string $htmlBody = null): void;
}
