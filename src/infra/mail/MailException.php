<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\infra\mail;

/** Any mail failure: not configured, bad address, SMTP error. */
class MailException extends \RuntimeException {
}
