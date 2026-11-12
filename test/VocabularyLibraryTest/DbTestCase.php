<?php
namespace VocabularyLibraryTest;

use Laminas\Config\Reader\Ini;
use Laminas\Uri\Http as HttpUri;
use Omeka\Entity\User;
use Omeka\Mvc\Application;
use Omeka\Test\DbTestCase as OmekaDbTestCase;

/**
 * The base of the tests that need a database.
 *
 * The first of them installs a fresh schema in core's test database, with
 * this module active. Each test then runs in a transaction that's rolled
 * back, as in core's DbTestCase.
 *
 * The tests read a small library of their own, test/fixtures/library, which
 * has one vocabulary, in place of the module's.
 */
abstract class DbTestCase extends OmekaDbTestCase
{
    const ADMIN = 'admin@example.com';

    private static $installed = false;

    public static function setUpBeforeClass(): void
    {
        self::installOnce();
    }

    public function setUp(): void
    {
        parent::setUp();
        self::signIn(self::ADMIN);
    }

    /**
     * Install the test database, the first time any test asks.
     */
    public static function installOnce(): void
    {
        if (self::$installed) {
            return;
        }
        self::dropSchema();
        self::installSchema();
        $services = self::startApplication();
        self::signIn(self::ADMIN);
        $modules = $services->get('Omeka\ModuleManager');
        $modules->install($modules->getModule('VocabularyLibrary'));
        // Started again, the application loads the module.
        $services = self::startApplication();
        $services->get('Router')->setRequestUri(new HttpUri('http://example.com'));
        self::$installed = true;
    }

    /**
     * Start the application on the test database, as core's DbTestCase does,
     * but with Omeka's own init(), which also loads the active modules.
     * Core's DbTestCase returns the application this sets.
     */
    private static function startApplication()
    {
        $config = require OMEKA_PATH . '/application/config/application.config.php';
        $config['connection'] = (new Ini)->fromFile(OMEKA_PATH . '/application/test/config/database.ini');
        self::$application = Application::init($config);
        return self::$application->getServiceManager();
    }

    public static function services()
    {
        return self::getApplication()->getServiceManager();
    }

    /**
     * The tests' library directory, laid out as the module's data directory.
     */
    public static function libraryDir()
    {
        return dirname(__DIR__) . '/fixtures/library/data';
    }

    /**
     * The tests' library, keyed by slug, as the build wrote it.
     */
    public static function library()
    {
        return json_decode(file_get_contents(self::libraryDir() . '/vocabularies.json'), true);
    }

    /**
     * Make a user, as Omeka's admin would.
     */
    public static function makeUser($email, $role)
    {
        $em = self::services()->get('Omeka\EntityManager');
        $user = new User;
        $user->setEmail($email);
        $user->setName($email);
        $user->setRole($role);
        $user->setIsActive(true);
        $em->persist($user);
        $em->flush();
        return $user;
    }

    /**
     * Make a user the one signed in.
     */
    public static function signIn($email)
    {
        $services = self::services();
        $user = $services->get('Omeka\EntityManager')->getRepository(User::class)->findOneBy(['email' => $email]);
        $services->get('Omeka\AuthenticationService')->getStorage()->write($user);
    }
}
