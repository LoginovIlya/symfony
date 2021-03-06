<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\SecurityBundle\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class LogoutTest extends AbstractWebTestCase
{
    /**
     * @dataProvider provideSecuritySystems
     */
    public function testSessionLessRememberMeLogout(array $options)
    {
        $client = $this->createClient($options + ['test_case' => 'RememberMeLogout', 'root_config' => 'config.yml']);

        $client->request('POST', '/login', [
            '_username' => 'johannes',
            '_password' => 'test',
        ]);

        $cookieJar = $client->getCookieJar();
        $cookieJar->expire(session_name());

        $this->assertNotNull($cookieJar->get('REMEMBERME'));
        $this->assertSame('lax', $cookieJar->get('REMEMBERME')->getSameSite());

        $client->request('GET', '/logout');

        $this->assertNull($cookieJar->get('REMEMBERME'));
    }

    /**
     * @dataProvider provideSecuritySystems
     */
    public function testCsrfTokensAreClearedOnLogout(array $options)
    {
        $client = $this->createClient($options + ['test_case' => 'LogoutWithoutSessionInvalidation', 'root_config' => 'config.yml']);
        $client->disableReboot();
        $this->callInRequestContext($client, function () {
            static::$container->get('security.csrf.token_storage')->setToken('foo', 'bar');
        });

        $client->request('POST', '/login', [
            '_username' => 'johannes',
            '_password' => 'test',
        ]);

        $this->callInRequestContext($client, function () {
            $this->assertTrue(static::$container->get('security.csrf.token_storage')->hasToken('foo'));
            $this->assertSame('bar', static::$container->get('security.csrf.token_storage')->getToken('foo'));
        });

        $client->request('GET', '/logout');

        $this->callInRequestContext($client, function () {
            $this->assertFalse(static::$container->get('security.csrf.token_storage')->hasToken('foo'));
        });
    }

    /**
     * @dataProvider provideSecuritySystems
     */
    public function testAccessControlDoesNotApplyOnLogout(array $options)
    {
        $client = $this->createClient($options + ['test_case' => 'Logout', 'root_config' => 'config_access.yml']);

        $client->request('POST', '/login', ['_username' => 'johannes', '_password' => 'test']);
        $client->request('GET', '/logout');

        $this->assertRedirect($client->getResponse(), '/');
    }

    public function testCookieClearingOnLogout()
    {
        $client = $this->createClient(['test_case' => 'Logout', 'root_config' => 'config_cookie_clearing.yml']);

        $cookieJar = $client->getCookieJar();
        $cookieJar->set(new Cookie('flavor', 'chocolate', strtotime('+1 day'), null, 'somedomain'));

        $client->request('POST', '/login', ['_username' => 'johannes', '_password' => 'test']);
        $client->request('GET', '/logout');

        $this->assertRedirect($client->getResponse(), '/');
        $this->assertNull($cookieJar->get('flavor'));
    }

    private function callInRequestContext(KernelBrowser $client, callable $callable): void
    {
        /** @var EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = static::$container->get(EventDispatcherInterface::class);
        $wrappedCallable = function (RequestEvent $event) use (&$callable) {
            $callable();
            $event->setResponse(new Response(''));
            $event->stopPropagation();
        };

        $eventDispatcher->addListener(KernelEvents::REQUEST, $wrappedCallable);
        try {
            $client->request('GET', '/'.uniqid('', true));
        } finally {
            $eventDispatcher->removeListener(KernelEvents::REQUEST, $wrappedCallable);
        }
    }
}
