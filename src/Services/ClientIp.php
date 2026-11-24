<?php
declare(strict_types=1);

namespace App\Services;

use Psr\Http\Message\ServerRequestInterface as Request;

/** Address of the visitor. X-Forwarded-For only counts when it comes from a proxy listed in the settings. */
final class ClientIp
{
    /** @param string[] $trusted */
    public function __construct(private array $trusted)
    {
    }

    public function of(Request $request): string
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $hops = array_reverse(array_map('trim', explode(',', $request->getHeaderLine('X-Forwarded-For'))));
        foreach ($hops as $hop) {
            // The nearest hop that is not ours is the client: anything before it can be forged
            if (!in_array($ip, $this->trusted, true) || !filter_var($hop, FILTER_VALIDATE_IP)) {
                break;
            }
            $ip = $hop;
        }
        return $ip;
    }
}
