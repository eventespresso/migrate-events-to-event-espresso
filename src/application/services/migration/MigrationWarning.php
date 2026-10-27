<?php

namespace EventEspresso\EventMigrator\application\services\migration;

/**
 * Class MigrationWarning
 *
 * One per-record warning: a stable rule id, the source entity it concerns and
 * the values that fill the rule label's placeholders. Carries no translated
 * text itself, so it can be created at any time and serialised into the run
 * record; message() translates at render time.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class MigrationWarning
{
    private string $rule;

    private string $source_type;

    private string $source_id;

    private array $args;


    /**
     * @param string $rule        A WarningRule constant.
     * @param string $source_type The source entity type (event, booking, payment, ...).
     * @param string $source_id   The source entity identifier.
     * @param array  $args        Scalar values for the label's numbered placeholders, in order.
     */
    public function __construct(string $rule, string $source_type, string $source_id, array $args = [])
    {
        $this->rule        = $rule;
        $this->source_type = $source_type;
        $this->source_id   = $source_id;
        $this->args        = array_map('strval', array_values($args));
    }


    public function rule(): string
    {
        return $this->rule;
    }


    public function sourceType(): string
    {
        return $this->source_type;
    }


    public function sourceId(): string
    {
        return $this->source_id;
    }


    /**
     * @return string[]
     */
    public function args(): array
    {
        return $this->args;
    }


    /**
     * The translated message: the rule label with its placeholders filled from
     * args. Missing args are padded with empty strings so a label with more
     * placeholders than args can never throw at render time.
     */
    public function message(): string
    {
        $label        = WarningRule::label($this->rule);
        $placeholders = preg_match_all('/%(?:\d+\$)?[sd]/', $label);
        $args         = array_map('esc_html', $this->args);
        while (count($args) < $placeholders) {
            $args[] = '';
        }
        return $placeholders > 0 ? vsprintf($label, $args) : $label;
    }


    /**
     * @return array{rule: string, type: string, id: string, args: string[]}
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'type' => $this->source_type,
            'id'   => $this->source_id,
            'args' => $this->args,
        ];
    }


    public static function fromArray(array $data): MigrationWarning
    {
        return new MigrationWarning(
            (string) ($data['rule'] ?? ''),
            (string) ($data['type'] ?? ''),
            (string) ($data['id'] ?? ''),
            (array) ($data['args'] ?? [])
        );
    }
}
// End of file MigrationWarning.php
// Location: EventEspresso\EventMigrator\application\services\migration/MigrationWarning.php
