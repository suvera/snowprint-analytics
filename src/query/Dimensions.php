<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\query;

/**
 * Report dimensions: public name => events column. The single whitelist for
 * filters and breakdowns, so user input never reaches SQL as an identifier.
 */
final class Dimensions {

    public const DIRECT = 'Direct / None';
    public const NONE = '(none)';

    public const COLUMNS = [
        'page' => 'path',
        'hostname' => 'hostname',
        'source' => 'referrer_source',
        'referrer' => 'referrer_host',
        'utm_source' => 'utm_source',
        'utm_medium' => 'utm_medium',
        'utm_campaign' => 'utm_campaign',
        'utm_term' => 'utm_term',
        'utm_content' => 'utm_content',
        'country' => 'country',
        'region' => 'region',
        'city' => 'city',
        'browser' => 'browser',
        'os' => 'os',
        'device' => 'device',
        'event' => 'name',
    ];

    /** Breakdown-only dimensions derived per session. */
    public const SESSION_DIMENSIONS = ['entry_page', 'exit_page'];

    /** @return list<string> */
    public static function breakdownNames(): array {
        return [...array_keys(self::COLUMNS), ...self::SESSION_DIMENSIONS];
    }
}
