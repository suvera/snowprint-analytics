<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\support;

use dev\winterframework\pdbc\core\BindVars;
use dev\winterframework\pdbc\core\OutBindVars;
use dev\winterframework\pdbc\core\PreparedStatementCallback;
use dev\winterframework\pdbc\core\ResultSetExtractor;
use dev\winterframework\pdbc\core\RowCallbackHandler;
use dev\winterframework\pdbc\core\RowMapper;
use dev\winterframework\pdbc\PdbcTemplate;

/**
 * Plain-PDO PdbcTemplate for integration tests: the subset of methods the
 * application uses, against a real PostgreSQL.
 */
final class PdoPdbcTemplate implements PdbcTemplate {

    public function __construct(public readonly \PDO $pdo) {
    }

    public static function fromEnv(): ?self {
        $url = getenv('SNOWPRINT_TEST_DB_URL');
        if (!$url) {
            return null;
        }
        $pdo = new \PDO($url, getenv('SNOWPRINT_TEST_DB_USER') ?: 'snowprint', getenv('SNOWPRINT_TEST_DB_PASSWORD') ?: '');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        return new self($pdo);
    }

    private function run(string $sql, array|BindVars $binds): \PDOStatement {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values((array) $binds));
        return $stmt;
    }

    public function queryForList(string $sql, array|BindVars $bindVars = []): array {
        return $this->run($sql, $bindVars)->fetchAll();
    }

    public function queryForScalar(string $sql, array|BindVars $bindVars = []): int|string|float|bool|null {
        // Like Winter Boot's PdoTemplate: no row is an error, not null.
        // Fetch the row, not the column: a boolean FALSE value is a result too.
        $row = $this->run($sql, $bindVars)->fetch(\PDO::FETCH_NUM);
        if ($row === false) {
            throw new \UnexpectedValueException('Incorrect result size: expected 1, actual empty record');
        }
        return $row[0];
    }

    public function queryForMap(string $sql, array|BindVars $bindVars = []): array {
        return $this->run($sql, $bindVars)->fetch() ?: [];
    }

    public function update(string $sql, array|BindVars $bindVars, array|OutBindVars $outBindVars = [], array &$generatedKeys = []): int {
        return $this->run($sql, $bindVars)->rowCount();
    }

    public function execute(string $sql, array|BindVars $bindVars = [], ?PreparedStatementCallback $action = null): mixed {
        return $this->run($sql, $bindVars)->rowCount();
    }

    public function batchUpdate(string $sql, array $arrayBindVars): array {
        throw new \LogicException('not supported in tests');
    }

    public function query(string $sql, array|BindVars $bindVars, callable|ResultSetExtractor|RowCallbackHandler|RowMapper $processor): mixed {
        throw new \LogicException('not supported in tests');
    }

    public function queryForObject(string $sql, array|BindVars $bindVars, string|RowMapper|null $classOrMapper = null): object {
        throw new \LogicException('not supported in tests');
    }

    public function queryForObjects(string $sql, array|BindVars $bindVars, string $ppaClass): array {
        throw new \LogicException('not supported in tests');
    }

    public function updateObjects(object ...$ppaObjects): void {
        throw new \LogicException('not supported in tests');
    }

    public function deleteObjects(object ...$ppaObjects): void {
        throw new \LogicException('not supported in tests');
    }
}
