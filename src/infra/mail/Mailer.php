<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\infra\mail;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;

/**
 * Outgoing email over the operator's SMTP server (snowprint.mail.*). Optional:
 * with no host configured enabled() is false and callers fall back to
 * showing links for the admin to share.
 */
#[Component]
class Mailer {

    #[Autowired]
    private ApplicationContext $ctx;

    private ?MailTransport $transport = null;
    private ?string $from = null;

    public function enabled(): bool {
        return trim($this->ctx->getPropertyStr('snowprint.mail.host', '')) !== '';
    }

    /** Replaces the SMTP transport (tests). */
    public function withTransport(MailTransport $transport, string $from): static {
        $this->transport = $transport;
        $this->from = $from;
        return $this;
    }

    /** @throws MailException when not configured, on a bad address or an SMTP error */
    public function send(string $to, string $subject, string $textBody, ?string $htmlBody = null): void {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException('invalid recipient address');
        }
        if (preg_match('/[\r\n]/', $subject) === 1) {
            throw new MailException('the subject must be one line');
        }
        if ($this->transport === null) {
            if (!$this->enabled()) {
                throw new MailException('email is not configured (SNOWPRINT_SMTP_HOST)');
            }
            // No sender configured: the SMTP login, which is usually an address.
            $from = trim($this->ctx->getPropertyStr('snowprint.mail.from', ''));
            $this->from = self::from($from !== '' ? $from : $this->ctx->getPropertyStr('snowprint.mail.username', ''));
            $this->transport = new SmtpTransport(
                trim($this->ctx->getPropertyStr('snowprint.mail.host', '')),
                (int) $this->ctx->getPropertyStr('snowprint.mail.port', '587'),
                strtolower(trim($this->ctx->getPropertyStr('snowprint.mail.encryption', 'starttls'))),
                $this->ctx->getPropertyStr('snowprint.mail.username', ''),
                $this->ctx->getPropertyStr('snowprint.mail.password', ''),
            );
        }
        $this->transport->send($this->from, $to, $subject, $textBody, $htmlBody);
    }

    /** "Snowprint <a@b>" from a bare address, or the given "Name <a@b>". */
    public static function from(string $value): string {
        $value = trim($value);
        if (filter_var(SmtpTransport::address($value), FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[\r\n]/', $value) === 1) {
            throw new MailException('snowprint.mail.from (SNOWPRINT_SMTP_FROM) must be an email address, got: "' . $value . '"');
        }
        return str_contains($value, '<') ? $value : 'Snowprint <' . $value . '>';
    }
}
