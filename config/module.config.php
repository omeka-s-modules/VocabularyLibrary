<?php
namespace VocabularyLibrary;

use Laminas\Router\Http;

return [
    'view_manager' => [
        'template_path_stack' => [
            sprintf('%s/../view', __DIR__),
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => sprintf('%s/../language', __DIR__),
                'pattern' => '%s.mo',
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            'VocabularyLibrary\Controller\Admin\Index' => Service\Controller\Admin\IndexControllerFactory::class,
        ],
    ],
    'form_elements' => [
        'factories' => [
            Form\ImportForm::class => Service\Form\ImportFormFactory::class,
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'Vocabulary Library', // @translate
                'route' => 'admin/vocabulary-library',
                'resource' => 'VocabularyLibrary\Controller\Admin\Index',
                'privilege' => 'browse',
            ],
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'vocabulary-library' => [
                        'type' => Http\Segment::class,
                        'options' => [
                            'route' => '/vocabulary-library[/:action[/:slug]]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                'slug' => '[a-z0-9][a-z0-9-]*',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'VocabularyLibrary\Controller\Admin',
                                'controller' => 'index',
                                'action' => 'browse',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'vocabulary_library' => [
        // Tags an admin can filter the library by. Each vocabulary has one to
        // three, in its "tags" field in data/vocabularies.json.
        'tags' => [
            'general' => 'General', // @translate
            'linked-data' => 'Linked data and modeling', // @translate
            'museums' => 'Museums and art', // @translate
            'libraries' => 'Libraries and cataloging', // @translate
            'archives' => 'Archives and records', // @translate
            'aggregation' => 'Aggregation and IIIF', // @translate
            'publishing' => 'Scholarly publishing', // @translate
            'humanities' => 'Digital humanities', // @translate
            'people' => 'People and organizations', // @translate
            'places' => 'Places and geography', // @translate
            'time' => 'Time and events', // @translate
            'rights' => 'Rights and data provenance', // @translate
            'media' => 'Media and files', // @translate
            'preservation' => 'Digital preservation', // @translate
            'science' => 'Science and measurement', // @translate
            'natural-history' => 'Natural history', // @translate
            'performing-arts' => 'Music and performing arts', // @translate
            'language' => 'Language', // @translate
            // Vocabularies whose properties describe a statement rather than
            // a resource (certainty, sources, attribution, qualifiers), for
            // use in value annotations.
            'value-annotations' => 'Value annotations', // @translate
        ],
    ],
];
