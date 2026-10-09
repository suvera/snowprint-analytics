<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\ingest;

use dev\suvera\snowprint\ingest\GeoLocator;
use PHPUnit\Framework\TestCase;

final class GeoLocatorTest extends TestCase {

    public function testMapsAGeoIp2CityRecord(): void {
        $record = [
            'country' => ['iso_code' => 'gb', 'names' => ['en' => 'United Kingdom']],
            'subdivisions' => [['iso_code' => 'ENG', 'names' => ['en' => 'England']]],
            'city' => ['names' => ['en' => 'London', 'de' => 'London']],
        ];
        self::assertSame(['country' => 'GB', 'region' => 'England', 'city' => 'London'], GeoLocator::fromRecord($record));
    }

    public function testCountryOnlyDatabase(): void {
        self::assertSame(
            ['country' => 'DE', 'region' => null, 'city' => null],
            GeoLocator::fromRecord(['country' => ['iso_code' => 'DE']])
        );
    }

    public function testNoRecordMeansUnknown(): void {
        self::assertSame(['country' => null, 'region' => null, 'city' => null], GeoLocator::fromRecord(null));
    }
}
