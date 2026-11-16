<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cookies that services decide on during a request (for example a rotated remember-me token, possibly while
 * a guard answers instead of the handler). The Kernel adds them to whatever response the request ends with.
 */
final class ResponseCookies
{
    /** @var array<string, Cookie> */
    private array $cookies = [];

    public function set(Cookie $cookie): void
    {
        $this->cookies[$cookie->getName()] = $cookie;
    }

    public function apply(Response $response): Response
    {
        foreach ($this->cookies as $cookie) {
            $response->headers->setCookie($cookie);
        }

        $this->cookies = [];

        return $response;
    }
}
