<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\privacy;

use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Component;

/**
 * PostgreSQL salt store. Salts travel hex-encoded because PDO returns bytea
 * columns as streams.
 */
#[Component]
class SaltStoreImpl implements SaltStore {

    #[Autowired]
    private PdbcTemplate $db;

    public function getOrCreate(string $day, string $candidate): string {
        $this->db->update(
            "INSERT INTO salts (day, salt) VALUES (?, decode(?, 'hex')) ON CONFLICT (day) DO NOTHING",
            [$day, bin2hex($candidate)]
        );
        $hex = $this->db->queryForScalar("SELECT encode(salt, 'hex') FROM salts WHERE day = ?", [$day]);
        return hex2bin((string) $hex);
    }

    public function deleteBefore(string $keepFromDay): int {
        return $this->db->update('DELETE FROM salts WHERE day < ?', [$keepFromDay]);
    }
}
