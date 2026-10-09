<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\site;

use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;

/**
 * Conversion goals (PRD §6.2): a pageview goal matches a path ("*" wildcard,
 * e.g. "/thanks*"), an event goal matches a custom event name.
 */
#[Service]
class GoalService {

    public const KINDS = ['pageview', 'event'];

    #[Autowired]
    private PdbcTemplate $db;

    /** @return array{id: int, name: string, kind: string, match: string} */
    public function create(int $siteId, string $kind, string $match, ?string $name = null): array {
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidInput('goal kind must be "pageview" or "event"');
        }
        $match = trim($match);
        if ($match === '' || mb_strlen($match) > 2048) {
            throw new InvalidInput('goal needs a path or event name');
        }
        if ($kind === 'pageview' && !str_starts_with($match, '/')) {
            throw new InvalidInput('pageview goals match a path starting with "/"');
        }
        $name = trim($name ?? '') !== '' ? trim($name) : ($kind === 'pageview' ? "Visit $match" : $match);
        $exists = $this->db->queryForScalar(
            'SELECT count(*) FROM goals WHERE site_id = ? AND kind = ? AND match = ?', [$siteId, $kind, $match]);
        if ((int) $exists > 0) {
            throw new InvalidInput('this goal already exists');
        }
        $id = (int) $this->db->queryForScalar(
            'INSERT INTO goals (site_id, name, kind, match) VALUES (?, ?, ?, ?) RETURNING id',
            [$siteId, mb_substr($name, 0, 255), $kind, $match]
        );
        return ['id' => $id, 'name' => $name, 'kind' => $kind, 'match' => $match];
    }

    /** @return list<array{id: int, name: string, kind: string, match: string}> */
    public function forSite(int $siteId): array {
        return array_map(static fn(array $r) => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'],
            'kind' => (string) $r['kind'], 'match' => (string) $r['match'],
        ], $this->db->queryForList('SELECT id, name, kind, match FROM goals WHERE site_id = ? ORDER BY id', [$siteId]));
    }
}
