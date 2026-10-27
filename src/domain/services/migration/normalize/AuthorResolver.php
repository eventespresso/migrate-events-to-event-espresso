<?php

namespace EventEspresso\EventMigrator\domain\services\migration\normalize;

use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use WP_User;

/**
 * Class AuthorResolver
 *
 * The WordPress user a migrated record is written under (PRD OQ-6): the
 * source author when that user still exists, otherwise the user running the
 * migration. Falling back from a named author that no longer exists raises
 * author-fallback; a source that names no author falls back silently.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class AuthorResolver
{
    /**
     * @param int|null         $source_author The source's WordPress user id, or null when it names none.
     * @param string           $source_type   The source entity type the warning is filed under.
     * @param string           $source_id     The source entity id the warning is filed under.
     * @param MigrationContext $context       The run, for the fallback user and the warning sink.
     */
    public function resolve(
        ?int $source_author,
        string $source_type,
        string $source_id,
        MigrationContext $context
    ): int {
        if ($source_author !== null && $source_author > 0) {
            if ($this->userExists($source_author)) {
                return $source_author;
            }
            $context->addWarning(
                new MigrationWarning(
                    WarningRule::AUTHOR_FALLBACK,
                    $source_type,
                    $source_id,
                    [(string) $source_author]
                )
            );
        }
        return $context->userId();
    }


    protected function userExists(int $user_id): bool
    {
        return get_userdata($user_id) instanceof WP_User;
    }
}
// End of file AuthorResolver.php
// Location: EventEspresso\EventMigrator\domain\services\migration\normalize/AuthorResolver.php
