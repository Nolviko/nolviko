<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Users;

use Nolviko\Modules\Users\Http\ReturnPath;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** Beta.10: after signing in, only a path on this site is followed; anything else falls back to the home page. */
final class ReturnPathTest extends TestCase
{
    public function testOnlyOwnPathsAreAccepted(): void
    {
        self::assertSame('/threads/7/reply', ReturnPath::safe('/threads/7/reply'));
        self::assertSame('/forum/threads/7/reply', ReturnPath::safe('/forum/threads/7/reply', '/forum'));
        foreach (
            ['https://evil.example/', '//evil.example/x', '/\\evil.example', 'threads/7', '/a b', "/a\nb",
            '/login', '/login?return=/x', '/logout', '/register', '/threads/' . str_repeat('x', 600)] as $bad
        ) {
            self::assertNull(ReturnPath::safe($bad), $bad);
        }
        self::assertNull(ReturnPath::safe('/threads/7/reply', '/forum'), 'outside the base path');
    }

    public function testItTravelsInTheFormAddress(): void
    {
        self::assertSame('/login?return=%2Fthreads%2F7%2Freply', ReturnPath::append('/login', '/threads/7/reply'));
        self::assertSame('/login', ReturnPath::append('/login', null));
        $request = Request::create('/login?return=%2Fthreads%2F7%2Freply', 'POST', ['identifier' => 'x']);
        self::assertSame('/threads/7/reply', ReturnPath::of($request));
        self::assertNull(ReturnPath::of(Request::create('/login?return=https%3A%2F%2Fevil.example')));
    }
}
