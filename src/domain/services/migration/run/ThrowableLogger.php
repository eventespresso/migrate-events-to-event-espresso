<?php

namespace EventEspresso\EventMigrator\domain\services\migration\run;

use EventEspresso\core\exceptions\ExceptionLogger;
use Exception;
use Throwable;

/**
 * Class ThrowableLogger
 *
 * The one place the add-on writes to the PHP error log: the Throwable that
 * stopped a migration step, a rollback or a compensation, with where it
 * happened, so support can find it in the server log. Exceptions go through
 * core's ExceptionLogger (same destination, core's format); PHP Errors, which
 * ExceptionLogger does not accept, are formatted here.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class ThrowableLogger
{
    /**
     * @param Throwable $throwable The error that stopped the work.
     * @param string    $context   An optional line written before it, naming what was being done.
     */
    public static function log(Throwable $throwable, string $context = ''): void
    {
        if ($throwable instanceof Exception) {
            if ($context !== '') {
                self::write($context);
            }
            new ExceptionLogger($throwable);
            return;
        }
        self::write(
            ($context !== '' ? $context . ' ' : '')
            . sprintf('%s in %s:%d', $throwable->getMessage(), $throwable->getFile(), $throwable->getLine())
            . "\n" . $throwable->getTraceAsString()
        );
    }


    private static function write(string $message): void
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- not debug output: this records the error that stopped a migration so it can be found in the server log; nothing else is ever logged.
        error_log($message);
    }
}
// End of file ThrowableLogger.php
// Location: EventEspresso\EventMigrator\domain\services\migration\run/ThrowableLogger.php
