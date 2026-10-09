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
 * Records update() calls and serves canned query results. Methods the code
 * under test should not call throw.
 */
final class RecordingPdbcTemplate implements PdbcTemplate {

    /** @var list<array{sql: string, binds: array}> */
    public array $updates = [];
    /** @var list<array<string, mixed>> */
    public array $listResult = [];
    public mixed $scalarResult = null;
    public ?\Throwable $failNextUpdate = null;

    public function update(string $sql, array|BindVars $bindVars, array|OutBindVars $outBindVars = [], array &$generatedKeys = []): int {
        if ($this->failNextUpdate !== null) {
            $e = $this->failNextUpdate;
            $this->failNextUpdate = null;
            throw $e;
        }
        $this->updates[] = ['sql' => $sql, 'binds' => (array) $bindVars];
        return 1;
    }

    public function queryForList(string $sql, array|BindVars $bindVars = []): array {
        return $this->listResult;
    }

    public function queryForScalar(string $sql, array|BindVars $bindVars = []): int|string|float|bool|null {
        return $this->scalarResult;
    }

    public function batchUpdate(string $sql, array $arrayBindVars): array {
        throw new \LogicException('unexpected batchUpdate');
    }

    public function execute(string $sql, array|BindVars $bindVars = [], ?PreparedStatementCallback $action = null): mixed {
        throw new \LogicException('unexpected execute');
    }

    public function query(string $sql, array|BindVars $bindVars, callable|ResultSetExtractor|RowCallbackHandler|RowMapper $processor): mixed {
        throw new \LogicException('unexpected query');
    }

    public function queryForMap(string $sql, array|BindVars $bindVars = []): array {
        throw new \LogicException('unexpected queryForMap');
    }

    public function queryForObject(string $sql, array|BindVars $bindVars, string|RowMapper|null $classOrMapper = null): object {
        throw new \LogicException('unexpected queryForObject');
    }

    public function queryForObjects(string $sql, array|BindVars $bindVars, string $ppaClass): array {
        throw new \LogicException('unexpected queryForObjects');
    }

    public function updateObjects(object ...$ppaObjects): void {
        throw new \LogicException('unexpected updateObjects');
    }

    public function deleteObjects(object ...$ppaObjects): void {
        throw new \LogicException('unexpected deleteObjects');
    }
}
