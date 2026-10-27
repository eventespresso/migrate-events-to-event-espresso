<?php

namespace EventEspresso\EventMigrator\domain\services\migration\persist;

use EE_Payment_Method;
use EEM_Payment_Method;
use RuntimeException;

/**
 * Class PaymentMethodResolver
 *
 * The EE payment method (PMD_ID, which payments require) to record a migrated
 * payment against: the method whose slug equals the source gateway, else the
 * first of the fallback types that exists. Resolved once per gateway slug for
 * the request.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class PaymentMethodResolver
{
    /**
     * Payment method types tried, in order, when a source gateway has no EE payment method of that slug.
     */
    public const FALLBACK_TYPES = ['Admin_Only', 'Invoice'];

    /**
     * @var array<string, int> gateway slug => payment method id
     */
    private array $resolved = [];


    /**
     * @throws RuntimeException When the site has no usable payment method at all.
     */
    public function resolve(string $gateway): int
    {
        if (isset($this->resolved[ $gateway ])) {
            return $this->resolved[ $gateway ];
        }
        $method_id = $this->idBySlug(sanitize_key($gateway));
        foreach (self::FALLBACK_TYPES as $type) {
            $method_id = $method_id ?? $this->idByType($type);
        }
        if ($method_id === null) {
            throw new RuntimeException(
                esc_html__('No payment method exists to record migrated payments against.', 'migrate-events-to-event-espresso')
            );
        }
        $this->resolved[ $gateway ] = $method_id;
        return $method_id;
    }


    protected function idBySlug(string $slug): ?int
    {
        $method = $slug !== '' ? EEM_Payment_Method::instance()->get_one_by_slug($slug) : null;
        return $method instanceof EE_Payment_Method ? (int) $method->ID() : null;
    }


    protected function idByType(string $type): ?int
    {
        $method = EEM_Payment_Method::instance()->get_one_of_type($type);
        return $method instanceof EE_Payment_Method ? (int) $method->ID() : null;
    }
}
// End of file PaymentMethodResolver.php
// Location: EventEspresso\EventMigrator\domain\services\migration\persist/PaymentMethodResolver.php
