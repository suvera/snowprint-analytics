<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\infra\mail;

use dev\suvera\snowprint\infra\mail\Mailer;
use dev\suvera\snowprint\infra\mail\MailException;
use dev\suvera\snowprint\infra\mail\MailTransport;
use PHPUnit\Framework\TestCase;

final class MailerTest extends TestCase {

    public function testFormatsTheSender(): void {
        self::assertSame('Snowprint <noreply@example.com>', Mailer::from(' noreply@example.com '));
        self::assertSame('Stats <noreply@example.com>', Mailer::from('Stats <noreply@example.com>'));
        foreach (['', 'not an address', "a@example.com\r\nBcc: x@example.com"] as $bad) {
            try {
                Mailer::from($bad);
                self::fail('accepted ' . json_encode($bad));
            } catch (MailException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSendsThroughTheTransport(): void {
        $transport = new class implements MailTransport {
            public array $sent = [];

            public function send(string $from, string $to, string $subject, string $textBody, ?string $htmlBody = null): void {
                $this->sent[] = [$from, $to, $subject];
            }
        };
        $mailer = (new Mailer())->withTransport($transport, 'Snowprint <noreply@example.com>');

        $mailer->send('a@example.com', 'Hi', 'body');

        self::assertSame([['Snowprint <noreply@example.com>', 'a@example.com', 'Hi']], $transport->sent);
    }

    public function testRejectsHeaderInjection(): void {
        $mailer = (new Mailer())->withTransport($this->createStub(MailTransport::class), 'noreply@example.com');
        foreach ([["a@example.com>\r\nBcc: b@example.com", 'Hi'], ['a@example.com', "Hi\r\nBcc: b@example.com"]] as [$to, $subject]) {
            try {
                $mailer->send($to, $subject, 'body');
                self::fail('accepted ' . json_encode([$to, $subject]));
            } catch (MailException) {
                self::assertTrue(true);
            }
        }
    }
}
