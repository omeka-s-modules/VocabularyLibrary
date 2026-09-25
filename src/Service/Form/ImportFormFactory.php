<?php
namespace VocabularyLibrary\Service\Form;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VocabularyLibrary\Form\ImportForm;

class ImportFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $form = new ImportForm;
        $form->setOption('languages', $options['languages'] ?? []);
        return $form;
    }
}
