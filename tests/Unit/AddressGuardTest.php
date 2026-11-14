<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Tests\Unit;

use IndianOpinion\FeaturedFromSource\AddressGuard;
use PHPUnit\Framework\TestCase;

/**
 * Case 23 and section 8: the safe-address check on our side of the core one.
 */
final class AddressGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        AddressGuard::setResolver(null);
        parent::tearDown();
    }

    public function testRefusesEveryNonPublicRange(): void
    {
        $refused = [
            '127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '0.0.0.0',
            '169.254.169.254', '100.64.0.1', '100.127.255.254', '198.18.0.1', '192.0.0.170',
            '224.0.0.1', '240.0.0.1', '255.255.255.255',
            '::1', '::', 'fe80::1', 'fd00::1', 'fc00::1', 'ff02::1',
            '::ffff:127.0.0.1', '::ffff:169.254.169.254', '::ffff:a9fe:a9fe', '64:ff9b::a9fe:a9fe', '::10.0.0.1',
        ];
        foreach ($refused as $ip) {
            self::assertFalse(AddressGuard::isPublic($ip), $ip);
        }
        foreach (['93.184.216.34', '8.8.8.8', '100.128.0.1', '2606:2800:220:1:248:1893:25c8:1946', '::ffff:8.8.8.8'] as $ip) {
            self::assertTrue(AddressGuard::isPublic($ip), $ip);
        }
        self::assertFalse(AddressGuard::isPublic('not-an-ip'));
    }

    public function testEveryResolvedAddressMustBePublic(): void
    {
        $dns = [
            'good.example' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
            'mixed.example' => ['93.184.216.34', '169.254.169.254'],
            'v6only.example' => ['fd12::5'],
            'nothing.example' => [],
        ];
        AddressGuard::setResolver(static fn (string $host): array => $dns[$host] ?? []);
        self::assertSame($dns['good.example'], AddressGuard::publicAddresses('good.example'));
        self::assertNull(AddressGuard::publicAddresses('mixed.example'));
        self::assertNull(AddressGuard::publicAddresses('v6only.example'));
        self::assertNull(AddressGuard::publicAddresses('nothing.example'), 'a name that does not resolve is refused');
        self::assertNull(AddressGuard::publicAddresses('[::1]'));
        self::assertNull(AddressGuard::publicAddresses('169.254.169.254'));
        self::assertSame(['8.8.8.8'], AddressGuard::publicAddresses('8.8.8.8'));
    }
}
