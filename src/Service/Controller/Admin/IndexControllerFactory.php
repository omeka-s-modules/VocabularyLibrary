<?php
namespace VocabularyLibrary\Service\Controller\Admin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VocabularyLibrary\Controller\Admin\IndexController;

class IndexControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $dataDir = dirname(__DIR__, 4) . '/data';
        $vocabularies = json_decode(file_get_contents("$dataDir/vocabularies.json"), true);
        $config = $services->get('Config');
        return new IndexController(
            $services->get('Omeka\RdfImporter'),
            $vocabularies,
            $config['vocabulary_library']['tags'] ?? [],
            $dataDir
        );
    }
}
