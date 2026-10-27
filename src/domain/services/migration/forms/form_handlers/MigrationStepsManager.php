<?php

namespace EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers;

use EventEspresso\core\exceptions\InvalidDataTypeException;
use EventEspresso\core\exceptions\InvalidIdentifierException;
use EventEspresso\core\libraries\form_sections\form_handlers\FormHandler;
use EventEspresso\core\libraries\form_sections\form_handlers\SequentialStepFormManager;
use EventEspresso\core\services\collections\CollectionDetails;
use EventEspresso\core\services\collections\CollectionDetailsException;
use EventEspresso\core\services\collections\CollectionInterface;
use EventEspresso\core\services\collections\CollectionLoader;
use EventEspresso\core\services\collections\CollectionLoaderException;
use EventEspresso\core\services\request\RequestInterface;
use InvalidArgumentException;

/**
 * Class MigrationStepsManager
 *
 * Manages the sequential form steps of the migration wizard: choose a source,
 * set the options, confirm, then the run report. The step list is fixed.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class MigrationStepsManager extends SequentialStepFormManager
{
    /**
     * @param string                $base_url            The URL the wizard's steps post to.
     * @param string                $default_form_step   The slug of the first step.
     * @param string                $form_action         The form action, when it differs from the base URL.
     * @param string                $form_config         A FormHandler form-tag configuration constant.
     * @param string                $progress_step_style How the progress steps are drawn.
     * @param RequestInterface|null $request             The current request.
     * @throws InvalidDataTypeException
     * @throws InvalidArgumentException
     */
    public function __construct(
        string $base_url,
        string $default_form_step = 'source',
        string $form_action = '',
        string $form_config = FormHandler::ADD_FORM_TAGS_AND_SUBMIT,
        string $progress_step_style = 'number_bubbles',
        ?RequestInterface $request = null
    ) {
        parent::__construct(
            $base_url,
            $default_form_step,
            $form_action,
            $form_config,
            $progress_step_style,
            $request
        );
    }


    /**
     * @throws CollectionDetailsException
     * @throws CollectionLoaderException
     */
    protected function getFormStepsCollection(): ?CollectionInterface
    {
        if (! $this->form_steps instanceof CollectionInterface) {
            $loader = new CollectionLoader(
                new CollectionDetails(
                    'event_migrator_form_steps',
                    'EventEspresso\core\libraries\form_sections\form_handlers\SequentialStepForm',
                    [
                        'EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers\ChooseSource',
                        'EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers\MigrationOptionsStep',
                        'EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers\ConfirmMigration',
                        'EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers\MigrationReport',
                    ],
                    [],
                    '',
                    CollectionDetails::ID_CALLBACK_METHOD,
                    'slug'
                )
            );
            $this->form_steps = $loader->getCollection();
        }
        return $this->form_steps;
    }


    /**
     * @return CollectionInterface The wizard's steps, each a MigrationStep.
     * @throws CollectionDetailsException
     * @throws CollectionLoaderException
     */
    public function getSteps(): CollectionInterface
    {
        $this->getFormStepsCollection();
        return $this->form_steps;
    }


    /**
     * @throws InvalidDataTypeException
     * @throws InvalidIdentifierException
     */
    public function setCurrentStepFromRequest(): void
    {
        parent::setCurrentStepFromRequest();
    }
}
// End of file MigrationStepsManager.php
// Location: EventEspresso\EventMigrator\domain\services\migration\forms\form_handlers/MigrationStepsManager.php
