<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\infra\mail;

/**
 * Dependency-free SMTP client. Encryption is "starttls" (port 587), "ssl"
 * (implicit TLS, port 465) or "none" (a local relay). With
 * SWOOLE_HOOK_ALL the socket calls yield to other coroutines, so a send does
 * not block the worker.
 */
class SmtpTransport implements MailTransport {

    public const ENCRYPTIONS = ['starttls', 'ssl', 'none'];

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption = 'starttls',
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly int $timeoutSecs = 10,
    ) {
        if (!in_array($encryption, self::ENCRYPTIONS, true)) {
            throw new MailException('SMTP encryption must be one of ' . implode(', ', self::ENCRYPTIONS) . ', got: ' . $encryption);
        }
    }

    public function send(string $from, string $to, string $subject, string $textBody, ?string $htmlBody = null): void {
        $stream = $this->openConnection();
        try {
            $this->expect($stream, [220], 'greeting');
            $this->command($stream, 'EHLO ' . self::localName(), [250], 'EHLO');
            if ($this->encryption === 'starttls') {
                $this->command($stream, 'STARTTLS', [220], 'STARTTLS');
                if (@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new MailException('SMTP STARTTLS handshake with ' . $this->host . ' failed');
                }
                $this->command($stream, 'EHLO ' . self::localName(), [250], 'EHLO');
            }
            if ($this->username !== '') {
                $this->command($stream, 'AUTH LOGIN', [334], 'AUTH LOGIN');
                $this->command($stream, base64_encode($this->username), [334], 'AUTH username');
                $this->command($stream, base64_encode($this->password), [235], 'AUTH password');
            }
            $this->command($stream, 'MAIL FROM:<' . self::address($from) . '>', [250], 'MAIL FROM');
            $this->command($stream, 'RCPT TO:<' . $to . '>', [250, 251], 'RCPT TO');
            $this->command($stream, 'DATA', [354], 'DATA');
            $this->write($stream, self::buildMessage($from, $to, $subject, $textBody, $htmlBody) . "\r\n.\r\n");
            $this->expect($stream, [250], 'message');
            $this->command($stream, 'QUIT', [221], 'QUIT');
        } finally {
            fclose($stream);
        }
    }

    /** @return resource Separate so tests can hand in a scripted stream. */
    protected function openConnection(): mixed {
        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $stream = @stream_socket_client($scheme . '://' . $this->host . ':' . $this->port, $errno, $errstr,
            $this->timeoutSecs);
        if ($stream === false) {
            throw new MailException('SMTP connect to ' . $this->host . ':' . $this->port . ' failed: '
                . ($errstr !== '' ? $errstr : 'error ' . $errno));
        }
        stream_set_timeout($stream, $this->timeoutSecs);
        return $stream;
    }

    private function command(mixed $stream, string $line, array $want, string $stage): void {
        $this->write($stream, $line . "\r\n");
        $this->expect($stream, $want, $stage);
    }

    private function write(mixed $stream, string $data): void {
        $written = @fwrite($stream, $data);
        if ($written === false || $written < strlen($data)) {
            throw new MailException('SMTP write failed');
        }
    }

    /** @param int[] $want */
    private function expect(mixed $stream, array $want, string $stage): void {
        [$code, $text] = self::readReply($stream);
        if (!in_array($code, $want, true)) {
            throw new MailException('SMTP error at ' . $stage . ': ' . $text);
        }
    }

    /** @return array{0: int, 1: string} reply code and text (all lines of a multi-line reply) */
    public static function readReply(mixed $stream): array {
        $lines = [];
        while (true) {
            $line = fgets($stream);
            if ($line === false) {
                throw new MailException((stream_get_meta_data($stream)['timed_out'] ?? false)
                    ? 'SMTP read timed out' : 'SMTP connection closed by server');
            }
            $lines[] = rtrim($line, "\r\n");
            // "250-..." continues a reply, "250 ..." ends it.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return [(int) substr($lines[0], 0, 3), implode("\n", $lines)];
    }

    /** The bare address of "Name <a@b>" or "a@b". */
    public static function address(string $mailbox): string {
        return preg_match('/<([^<>]+)>\s*$/', $mailbox, $m) === 1 ? trim($m[1]) : trim($mailbox);
    }

    /** RFC 5322 message; bodies are quoted-printable so long lines and UTF-8 survive any relay. */
    public static function buildMessage(string $from, string $to, string $subject, string $textBody,
                                        ?string $htmlBody): string {
        $domain = substr(strrchr(self::address($from), '@') ?: '@localhost', 1);
        $head = [
            'Date: ' . gmdate('D, d M Y H:i:s O'),
            'From: ' . $from,
            'To: ' . $to,
            'Subject: ' . (preg_match('/[^\x20-\x7E]/', $subject) === 1
                ? mb_encode_mimeheader($subject, 'UTF-8', 'Q') : $subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
        ];
        if ($htmlBody === null) {
            return implode("\r\n", [...$head, ...self::part('text/plain', $textBody)]);
        }
        $boundary = 'snowprint-' . bin2hex(random_bytes(12));
        return implode("\r\n", [
            ...$head,
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            '',
            '--' . $boundary,
            ...self::part('text/plain', $textBody),
            '--' . $boundary,
            ...self::part('text/html', $htmlBody),
            '--' . $boundary . '--',
        ]);
    }

    /** @return list<string> */
    private static function part(string $type, string $body): array {
        // quoted_printable_encode() keeps CRLF but would encode a bare LF as =0A.
        $encoded = quoted_printable_encode(preg_replace('/\r\n|\r|\n/', "\r\n", $body));
        return [
            'Content-Type: ' . $type . '; charset=utf-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            self::dotStuff($encoded),
        ];
    }

    /** SMTP dot-stuffing: a line starting with "." gets a second one. */
    public static function dotStuff(string $body): string {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $body));
        foreach ($lines as &$line) {
            if (str_starts_with($line, '.')) {
                $line = '.' . $line;
            }
        }
        return implode("\r\n", $lines);
    }

    private static function localName(): string {
        $name = gethostname();
        return is_string($name) && $name !== '' ? $name : 'localhost';
    }
}
