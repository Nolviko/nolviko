<?php

declare(strict_types=1);

namespace Nolviko\Tests\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\ArrayLoader as TwigArrayLoader;

/**
 * Test-only native composition of the selected components. This is not a forum handler or repository.
 */
final class SelectedComponents
{
    private const LAYOUT_TEMPLATE = '<main>{% block content %}{% endblock %}</main>';

    private const PAGE_TEMPLATE = '{% extends "layout" %}'
        . '{% block content %}'
        . '<h1>{{ heading }}</h1>'
        . '{% for row in rows %}<article>{{ row }}</article>{% endfor %}'
        . '{% endblock %}';

    /** Hostile and multilingual row text: must render escaped. */
    private const ROW_TEXT = 'Türkçe العربية 😀 <script>bad()</script>';

    private UrlMatcher $router;

    private Translator $translator;

    private Environment $views;

    private Connection $db;

    /** @var list<string> */
    private array $rows;

    public function __construct()
    {
        $this->router = self::createRouter();
        $this->translator = self::createTranslator();
        $this->views = self::createViews();
        $this->db = self::createLazyConnection();
        $this->rows = array_fill(0, 20, self::ROW_TEXT);
    }

    public function render(Request $request): Response
    {
        $this->router->match($request->getPathInfo());

        // Compile, but never execute, a parameterised query: the fixture must stay database-free.
        $this->db
            ->createQueryBuilder()
            ->select('id')
            ->from('threads')
            ->where('visibility = :visibility')
            ->setParameter('visibility', 'public')
            ->setMaxResults(20)
            ->getSQL();

        $html = $this->views->render('page', [
            'heading' => $this->translator->trans('fixture.heading'),
            'rows' => $this->rows,
        ]);

        return new Response($html);
    }

    public function connected(): bool
    {
        return $this->db->isConnected();
    }

    public function plural(int $count): string
    {
        return $this->translator->trans('fixture.count', ['count' => $count]);
    }

    private static function createRouter(): UrlMatcher
    {
        $routes = new RouteCollection();
        $routes->add('thread', new Route('/threads/{id}', [], ['id' => '[1-9][0-9]*']));

        return new UrlMatcher($routes, new RequestContext());
    }

    /**
     * Turkish requested, English catalog only: exercises the explicit en fallback.
     */
    private static function createTranslator(): Translator
    {
        $translator = new Translator('tr');
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource(
            'array',
            [
                'fixture.heading' => 'Topics',
                'fixture.count' => '{count, plural, one {# post} other {# posts}}',
            ],
            'en',
            'messages+intl-icu',
        );

        return $translator;
    }

    private static function createViews(): Environment
    {
        $loader = new TwigArrayLoader([
            'layout' => self::LAYOUT_TEMPLATE,
            'page' => self::PAGE_TEMPLATE,
        ]);

        return new Environment($loader, [
            'autoescape' => 'html',
            'strict_variables' => true,
        ]);
    }

    /**
     * Port 1 is unreachable on purpose; DBAL connects lazily, so no connection is ever opened.
     */
    private static function createLazyConnection(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => '127.0.0.1',
            'port' => 1,
            'serverVersion' => '8.4.13',
        ]);
    }
}
