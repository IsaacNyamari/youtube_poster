<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * The workspace (user) whose data is being read or written.
 *
 * In a web request this is the logged-in user. In queued jobs and scheduled commands there is no login,
 * so work is wrapped in Tenant::run() with the owner of the data.
 */
class Tenant
{
    private static ?int $override = null;

    public static function id(): ?int
    {
        return self::$override ?? Auth::id();
    }

    public static function requireId(): int
    {
        return self::id() ?? throw new RuntimeException('No tenant (user) context is active.');
    }

    /**
     * Run a callback as the given user and restore the previous context afterwards.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function run(int $userId, callable $callback): mixed
    {
        $previous = self::$override;
        self::$override = $userId;

        try {
            return $callback();
        } finally {
            self::$override = $previous;
        }
    }

    /**
     * Run a callback once for every user, each inside that user's context.
     *
     * @param  callable(User): void  $callback
     */
    public static function eachUser(callable $callback): void
    {
        User::query()->orderBy('id')->each(function (User $user) use ($callback): void {
            self::run($user->id, fn () => $callback($user));
        });
    }
}
