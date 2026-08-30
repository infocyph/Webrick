<?php

declare(strict_types=1);

use Infocyph\Webrick\Request\Support\IpCidr;

describe('IpCidr', function () {
    it('matches valid IPv4 CIDR boundaries', function () {
        expect(IpCidr::match('203.0.113.9', '0.0.0.0/0'))->toBeTrue()
            ->and(IpCidr::match('203.0.113.9', '203.0.113.9/32'))->toBeTrue()
            ->and(IpCidr::match('203.0.113.10', '203.0.113.9/32'))->toBeFalse();
    });

    it('matches valid IPv6 CIDR boundaries', function () {
        expect(IpCidr::match('2001:db8::1', '::/0'))->toBeTrue()
            ->and(IpCidr::match('2001:db8::1', '2001:db8::1/128'))->toBeTrue()
            ->and(IpCidr::match('2001:db8::2', '2001:db8::1/128'))->toBeFalse();
    });

    it('fails closed for invalid IPv4 masks', function (string $cidr) {
        expect(IpCidr::match('203.0.113.9', $cidr))->toBeFalse();
    })->with([
        'too large' => '203.0.113.0/33',
        'negative' => '203.0.113.0/-1',
        'non numeric' => '203.0.113.0/abc',
        'empty' => '203.0.113.0/',
    ]);

    it('fails closed for invalid IPv6 masks', function (string $cidr) {
        expect(IpCidr::match('2001:db8::1', $cidr))->toBeFalse();
    })->with([
        'too large' => '2001:db8::/129',
        'negative' => '2001:db8::/-1',
        'non numeric' => '2001:db8::/abc',
        'empty' => '2001:db8::/',
    ]);

    it('rejects mixed address families', function () {
        expect(IpCidr::match('2001:db8::1', '203.0.113.0/24'))->toBeFalse()
            ->and(IpCidr::match('203.0.113.9', '2001:db8::/32'))->toBeFalse();
    });
});
