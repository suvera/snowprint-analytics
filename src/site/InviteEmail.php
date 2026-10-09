<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\site;

/** The invite email: subject, plain text and HTML for one invite link. */
final class InviteEmail {

    public const SUBJECT = 'You are invited to Snowprint analytics';

    public function __construct(
        private readonly string $inviter,
        private readonly string $link,
        private readonly int $days = InviteService::INVITE_DAYS,
    ) {
    }

    public function text(): string {
        return <<<TEXT
            Hi,

            {$this->inviter} invited you to their Snowprint web analytics dashboard.
            Open this link to choose your name and password:

            {$this->link}

            The link works once, for {$this->days} days. If you did not expect this
            invite, ignore this email.
            TEXT;
    }

    public function html(): string {
        $inviter = htmlspecialchars($this->inviter, ENT_QUOTES);
        $link = htmlspecialchars($this->link, ENT_QUOTES);
        return <<<HTML
            <p>Hi,</p>
            <p>{$inviter} invited you to their Snowprint web analytics dashboard.
            Open this link to choose your name and password:</p>
            <p><a href="{$link}">Accept the invite</a></p>
            <p>Or paste it into your browser:<br>{$link}</p>
            <p>The link works once, for {$this->days} days. If you did not expect this invite, ignore this email.</p>
            HTML;
    }
}
