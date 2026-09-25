<?php
namespace VocabularyLibrary;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Omeka\Module\AbstractModule;
use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Stdlib\Message;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include sprintf('%s/config/module.config.php', __DIR__);
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // Point to the library from core's Import vocabulary page. The page
        // has no event of its own, so a notice is added before the layout
        // renders its messages. Only on a GET: after a POST, core redirects,
        // and the notice would show on the next page.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Vocabulary',
            'view.layout',
            function (Event $event) {
                $view = $event->getTarget();
                if ('import' !== $view->params()->fromRoute('action')
                    || !$this->getServiceLocator()->get('Request')->isGet()
                    || !$view->userIsAllowed('VocabularyLibrary\Controller\Admin\Index', 'browse')
                ) {
                    return;
                }
                $message = new Message(
                    'Looking for a published vocabulary? The %s has tested vocabularies, ready to import.', // @translate
                    $view->hyperlink($view->translate('Vocabulary Library'), $view->url('admin/vocabulary-library'))
                );
                $message->setEscapeHtml(false);
                (new Messenger)->addNotice($message);
            }
        );
    }
}
