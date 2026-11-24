<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Database;
use App\Services\ClientIp;
use App\Services\Input;
use App\Services\Password;
use App\Services\Throttle;
use App\Services\TwoFactor;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SecurityTest extends TestCase
{
    private function twoFactor(int $time): TwoFactor
    {
        $clock = new class ($time) implements ClockInterface {
            public function __construct(private int $time)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@' . $this->time);
            }
        };
        return new TwoFactor(new Database([]), $clock, str_repeat('k', 32));
    }

    /** RFC 6238, appendix B (SHA-1, last six digits). */
    public function testCodesFollowTheStandard(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $time => $code) {
            $this->assertSame(intdiv($time, 30), $this->twoFactor($time)->step($secret, $code));
        }
    }

    public function testCodesExpireAndWorkOnce(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $step = intdiv(1111111109, 30);
        $this->assertSame($step, $this->twoFactor(1111111109 + 30)->step($secret, '081804'), 'one step late');
        $this->assertNull($this->twoFactor(1111111109 + 60)->step($secret, '081804'), 'two steps late');
        $this->assertNull($this->twoFactor(1111111109)->step($secret, '081804', $step), 'already used');
        $this->assertNull($this->twoFactor(1111111109)->step($secret, '000000'));
    }

    public function testTwoFactorNeedsAKey(): void
    {
        $this->assertFalse((new TwoFactor(new Database([]), $this->createStub(ClockInterface::class), 'short'))->available());
        $this->assertSame(32, strlen($this->twoFactor(0)->newSecret()));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $this->twoFactor(0)->qr('otpauth://totp/x'));
    }

    public function testPasswords(): void
    {
        $problem = static fn (string $new, string $current = 'the-old-one-1'): ?string => Password::problem($new, $current, 'alex');
        $this->assertNull($problem('correct horse battery'));
        $this->assertSame('short_password', $problem('short'));
        $this->assertSame('same_password', $problem('the-old-one-1'));
        $this->assertSame('weak_password', $problem('1111111111'));
        $this->assertSame('weak_password', $problem('Hello-ALEX-2026'));
        $this->assertSame('long_password', $problem("null\0inside-it"));
        $this->assertSame('long_password', $problem("\xff\xfe-not-utf8-text"));
        $this->assertSame('long_password', $problem(str_repeat('abcdefgh', 10)));
    }

    public function testForwardedAddressOnlyCountsFromTrustedProxies(): void
    {
        $ip = static function (string $remote, string $forwarded, array $trusted): string {
            $request = (new ServerRequestFactory())->createServerRequest('GET', '/', ['REMOTE_ADDR' => $remote]);
            return (new ClientIp($trusted))->of($forwarded === '' ? $request : $request->withHeader('X-Forwarded-For', $forwarded));
        };
        $this->assertSame('203.0.113.5', $ip('203.0.113.5', '', []));
        $this->assertSame('203.0.113.5', $ip('203.0.113.5', '198.51.100.1', []), 'forged');
        $this->assertSame('198.51.100.1', $ip('10.0.0.1', '198.51.100.1', ['10.0.0.1']));
        $this->assertSame('198.51.100.1', $ip('10.0.0.1', '1.2.3.4, 198.51.100.1', ['10.0.0.1']), 'forged through the proxy');
        $this->assertSame('198.51.100.1', $ip('10.0.0.1', '198.51.100.1, 10.0.0.2', ['10.0.0.1', '10.0.0.2']));
        $this->assertSame('10.0.0.1', $ip('10.0.0.1', 'garbage', ['10.0.0.1']));
    }

    public function testIpv6CountsByNetwork(): void
    {
        $this->assertSame(Throttle::network('2001:db8:1:2::1'), Throttle::network('2001:db8:1:2:ffff::9'));
        $this->assertNotSame(Throttle::network('2001:db8:1:2::1'), Throttle::network('2001:db8:1:3::1'));
        $this->assertSame('203.0.113.5', Throttle::network('203.0.113.5'));
    }

    public function testOnlyPlainTextIsAccepted(): void
    {
        $this->assertTrue(Input::isText('año'));
        foreach (["\xff\xfe", "a\0b", ['a'], null] as $value) {
            $this->assertFalse(Input::isText($value));
        }
    }
}
