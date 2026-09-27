<?php
namespace VocabularyLibrary\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Laminas\Validator\Regex;
use Omeka\Entity\Vocabulary;

/**
 * Imports a vocabulary from the library. The namespace and file come from
 * the library; the admin chooses the prefix, label and comment, and the
 * language when the file has several.
 *
 * Get it with $this->getForm(ImportForm::class, ['languages' => [tag =>
 * option text, …]]), so core adds its CSRF element. Labels use core's import
 * form wording, which is already translated.
 */
class ImportForm extends Form
{
    public function init()
    {
        $this->add([
            'type' => Element\Text::class,
            'name' => 'o:prefix',
            'options' => [
                'label' => 'Namespace prefix', // @translate
                'info' => 'Cannot be changed after import.', // @translate
            ],
            'attributes' => [
                'id' => 'o:prefix',
                'required' => true,
            ],
        ]);
        $this->add([
            'type' => Element\Text::class,
            'name' => 'o:label',
            'options' => [
                'label' => 'Label', // @translate
            ],
            'attributes' => [
                'id' => 'o:label',
                'required' => true,
            ],
        ]);
        $this->add([
            'type' => Element\Textarea::class,
            'name' => 'o:comment',
            'options' => [
                'label' => 'Comment', // @translate
            ],
            'attributes' => [
                'id' => 'o:comment',
            ],
        ]);
        $languages = $this->getOption('languages') ?: [];
        if (count($languages) > 1) {
            $this->add([
                'type' => Element\Select::class,
                'name' => 'lang',
                'options' => [
                    'label' => 'Preferred language', // @translate
                    'info' => 'The language of labels and comments. This vocabulary has them in several languages.', // @translate
                    'value_options' => $languages,
                ],
                'attributes' => [
                    'id' => 'lang',
                    'required' => true,
                ],
            ]);
        }

        // Omeka's column lengths: vocabulary prefix varchar(190), label
        // varchar(255).
        $inputFilter = $this->getInputFilter();
        $inputFilter->add([
            'name' => 'o:prefix',
            'required' => true,
            'filters' => [
                ['name' => 'StringTrim'],
            ],
            'validators' => [
                [
                    'name' => 'Regex',
                    'options' => [
                        'pattern' => Vocabulary::PREFIX_REGEX,
                        'messages' => [
                            Regex::NOT_MATCH => 'The prefix may only contain letters, numbers, hyphens, and underscores.', // @translate
                        ],
                    ],
                ],
                [
                    'name' => 'StringLength',
                    'options' => ['max' => 190],
                ],
            ],
        ]);
        $inputFilter->add([
            'name' => 'o:label',
            'required' => true,
            'filters' => [
                ['name' => 'StringTrim'],
            ],
            'validators' => [
                [
                    'name' => 'StringLength',
                    'options' => ['max' => 255],
                ],
            ],
        ]);
        $inputFilter->add([
            'name' => 'o:comment',
            'required' => false,
            'filters' => [
                ['name' => 'StringTrim'],
            ],
        ]);
    }
}
