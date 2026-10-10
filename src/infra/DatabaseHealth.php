<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\infra;

use dev\winterframework\actuator\Health;
use dev\winterframework\actuator\HealthIndicator;
use dev\winterframework\actuator\stereotype\HealthInformer;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;

/**
 * /api/system/health is DOWN when PostgreSQL does not answer (SP-031). Error details are
 * not exposed: the health endpoint is public.
 */
#[HealthInformer]
class DatabaseHealth implements HealthIndicator {

    #[Autowired]
    private PdbcTemplate $db;

    public function health(): Health {
        try {
            $this->db->queryForScalar('SELECT 1');
            return Health::up()->withDetail('database', 'UP');
        } catch (\Throwable) {
            return Health::down()->withDetail('database', 'DOWN');
        }
    }
}
