<?php

namespace EventEspresso\EventMigrator\domain\services\migration\normalize;

use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\application\services\migration\records\UnmappedConcept;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;

/**
 * Class UnmappedConceptWriter
 *
 * Preserves source concepts that Event Espresso cannot model (organizers,
 * speakers, tags, coupons, ...) as a labelled block of text appended to a
 * description, and reports each one as not-modelled (REQ-016). Every
 * source-derived value is escaped with esc_html() inside a fixed wrapper and
 * the result passes through wp_kses_post(), so markup in source data renders
 * inert (REQ-109). Pure.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class UnmappedConceptWriter
{
    /**
     * CSS class on the wrapper, so themes and later runs can recognise the block.
     */
    public const WRAPPER_CLASS = 'ee-migration-preserved';


    /**
     * The HTML block for a list of concepts, or '' when there is nothing to preserve.
     *
     * @param UnmappedConcept[] $concepts The concepts to preserve.
     */
    public function block(array $concepts): string
    {
        $sections = '';
        foreach ($concepts as $concept) {
            if (! $concept instanceof UnmappedConcept) {
                continue;
            }
            $items = '';
            foreach ($concept->values() as $key => $value) {
                $text = $this->valueText($key, $value);
                if ($text !== '') {
                    $items .= '<li>' . esc_html($text) . '</li>';
                }
            }
            if ($items === '') {
                continue;
            }
            $sections .= '<h4>' . esc_html($this->label($concept)) . '</h4><ul>' . $items . '</ul>';
        }
        if ($sections === '') {
            return '';
        }
        return wp_kses_post('<div class="' . self::WRAPPER_CLASS . '">' . $sections . '</div>');
    }


    /**
     * The description with the block appended on its own paragraph; unchanged when there is nothing to add.
     *
     * @param string            $description The (already sanitised) description.
     * @param UnmappedConcept[] $concepts    The concepts to preserve.
     */
    public function append(string $description, array $concepts): string
    {
        $block = $this->block($concepts);
        if ($block === '') {
            return $description;
        }
        return trim($description) === '' ? $block : rtrim($description) . "\n\n" . $block;
    }


    /**
     * One not-modelled warning per concept, naming the concept by its label.
     *
     * @param UnmappedConcept[] $concepts    The concepts that were preserved or dropped.
     * @param string            $source_type The source entity type the warnings are filed under.
     * @param string            $source_id   The source entity id the warnings are filed under.
     * @return MigrationWarning[]
     */
    public function warnings(array $concepts, string $source_type, string $source_id): array
    {
        $warnings = [];
        foreach ($concepts as $concept) {
            if ($concept instanceof UnmappedConcept) {
                $warnings[] = new MigrationWarning(
                    WarningRule::NOT_MODELLED,
                    $source_type,
                    $source_id,
                    [$this->label($concept)]
                );
            }
        }
        return $warnings;
    }


    private function label(UnmappedConcept $concept): string
    {
        $label = trim($concept->label());
        return $label !== '' ? $label : $concept->concept();
    }


    /**
     * One line of text for a value (a scalar or an array of scalars) under its integer or string key:
     * scalars as-is, lists joined with commas, string keys as "key: value".
     */
    private function valueText($key, $value): string
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $part_key => $part) {
                $text = $this->valueText($part_key, $part);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
            $joined = implode(', ', $parts);
            return is_string($key) && $joined !== '' ? $key . ': ' . $joined : $joined;
        }
        if (! is_scalar($value)) {
            return '';
        }
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }
        return is_string($key) ? $key . ': ' . $text : $text;
    }
}
// End of file UnmappedConceptWriter.php
// Location: EventEspresso\EventMigrator\domain\services\migration\normalize/UnmappedConceptWriter.php
