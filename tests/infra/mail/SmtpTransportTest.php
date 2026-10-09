<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\infra\mail;

use dev\suvera\snowprint\infra\mail\MailException;
use dev\suvera\snowprint\infra\mail\SmtpTransport;
use PHPUnit\Framework\TestCase;

final class SmtpTransportTest extends TestCase {

    public function testBuildsAPlainMessage(): void {
        $raw = SmtpTransport::buildMessage('Snowprint <noreply@example.com>', 'a@example.com', 'Hello',
            "line one\n.dot line\n" . str_repeat('x', 100), null);

        self::assertStringContainsString("From: Snowprint <noreply@example.com>\r\n", $raw);
        self::assertStringContainsString("To: a@example.com\r\n", $raw);
        self::assertStringContainsString("Subject: Hello\r\n", $raw);
        self::assertMatchesRegularExpression('/Message-ID: <[0-9a-f]{32}@example\.com>/', $raw);
        self::assertStringContainsString('Content-Transfer-Encoding: quoted-printable', $raw);
        self::assertStringContainsString("\r\n..dot line\r\n", $raw, 'dot-stuffed');
        foreach (explode("\r\n", $raw) as $line) {
            self::assertLessThanOrEqual(78, strlen($line), 'long lines are soft-wrapped');
            self::assertStringNotContainsString("\n", $line, 'no bare LF');
        }
    }

    public function testBuildsMultipartWithUtf8Subject(): void {
        $raw = SmtpTransport::buildMessage('noreply@example.com', 'a@example.com', 'Grüße', 'plain', '<p>html</p>');

        self::assertStringContainsString('multipart/alternative', $raw);
        self::assertStringContainsString('Content-Type: text/plain; charset=utf-8', $raw);
        self::assertStringContainsString('Content-Type: text/html; charset=utf-8', $raw);
        self::assertStringContainsString('Subject: =?UTF-8?Q?', $raw);
    }

    public function testReadsMultilineReplies(): void {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "250-smtp.example.com\r\n250-STARTTLS\r\n250 AUTH LOGIN\r\n");
        rewind($stream);

        self::assertSame([250, "250-smtp.example.com\n250-STARTTLS\n250 AUTH LOGIN"], SmtpTransport::readReply($stream));
    }

    public function testRejectsUnknownEncryption(): void {
        $this->expectException(MailException::class);
        new SmtpTransport('smtp.example.com', 25, 'tls');
    }

    public function testSpeaksSmtpWithLogin(): void {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        // A cooperative server: every reply is queued before the client asks.
        fwrite($server, implode("\r\n", ['220 hi', '250-hello', '250 AUTH LOGIN', '334 VXNlcm5hbWU6', '334 UGFzc3dvcmQ6',
            '235 ok', '250 ok', '250 ok', '354 go', '250 queued', '221 bye']) . "\r\n");
        $transport = new class('smtp.example.com', 25, 'none', 'user', 'secret', $client) extends SmtpTransport {
            public function __construct(string $h, int $p, string $e, string $u, string $pw, private mixed $stream) {
                parent::__construct($h, $p, $e, $u, $pw);
            }

            protected function openConnection(): mixed {
                return $this->stream;
            }
        };

        $transport->send('Snowprint <noreply@example.com>', 'a@example.com', 'Hi', 'body');

        $sent = stream_get_contents($server);
        self::assertStringContainsString("AUTH LOGIN\r\n" . base64_encode('user') . "\r\n" . base64_encode('secret') . "\r\n", $sent);
        self::assertStringContainsString("MAIL FROM:<noreply@example.com>\r\nRCPT TO:<a@example.com>\r\nDATA\r\n", $sent);
        self::assertStringContainsString("\r\nbody\r\n.\r\nQUIT\r\n", $sent);
    }

    public function testReportsTheFailingStage(): void {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($server, "220 hi\r\n250 hello\r\n250 ok\r\n550 no such user\r\n");
        $transport = new class('smtp.example.com', 25, 'none', '', '', $client) extends SmtpTransport {
            public function __construct(string $h, int $p, string $e, string $u, string $pw, private mixed $stream) {
                parent::__construct($h, $p, $e, $u, $pw);
            }

            protected function openConnection(): mixed {
                return $this->stream;
            }
        };

        try {
            $transport->send('noreply@example.com', 'nobody@example.com', 'Hi', 'body');
            self::fail('a rejected recipient must throw');
        } catch (MailException $e) {
            self::assertSame('SMTP error at RCPT TO: 550 no such user', $e->getMessage());
        }
    }
}
