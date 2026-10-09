<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\site;

use dev\suvera\snowprint\site\InviteEmail;
use PHPUnit\Framework\TestCase;

final class InviteEmailTest extends TestCase {

    public function testCarriesTheLink(): void {
        $mail = new InviteEmail('Ada', 'https://stats.example.com/ui/#/invite/abc', 7);

        self::assertStringContainsString("https://stats.example.com/ui/#/invite/abc\n", $mail->text());
        self::assertStringContainsString('Ada invited you', $mail->text());
        self::assertStringContainsString('for 7 days', $mail->text());
        self::assertStringContainsString('<a href="https://stats.example.com/ui/#/invite/abc">', $mail->html());
    }

    public function testEscapesTheInviterInHtml(): void {
        $html = (new InviteEmail('<script>x</script>', 'https://x.test/ui/#/invite/a'))->html();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
