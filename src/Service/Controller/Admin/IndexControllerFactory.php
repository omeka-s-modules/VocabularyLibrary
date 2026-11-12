<?php
namespace VocabularyLibrary\Service\Controller\Admin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VocabularyLibrary\Controller\Admin\IndexController;
use VocabularyLibrary\Stdlib\Collation;
use VocabularyLibrary\Stdlib\Importer;
use VocabularyLibrary\Stdlib\LanguageNames;
use VocabularyLibrary\Stdlib\SiteVocabularies;
use VocabularyLibrary\Stdlib\TermSearch;

class IndexControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $config = $services->get('Config');
        // The translator core's lang view helper gets, for the admin's
        // locale.
        $translator = $services->get('MvcTranslator')->getTranslator()->getDelegatedTranslator();
        $collation = new Collation($translator);
        $dataDir = $config['vocabulary_library']['data_dir'];
        $vocabularies = json_decode(file_get_contents("$dataDir/vocabularies.json"), true);
        return new IndexController(
            new Importer($services->get('Omeka\RdfImporter'), $dataDir),
            new TermSearch("$dataDir/term-index.json", $vocabularies),
            new LanguageNames($translator, $collation),
            new SiteVocabularies($services->get('Omeka\ApiManager'), $services->get('ViewHelperManager')->get('Url')),
            $collation,
            $vocabularies,
            $config['vocabulary_library']['tags'] ?? []
        );
    }
}
