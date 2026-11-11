<?php

namespace modules\docs\utilities;

use Craft;
use craft\base\Utility;
use yii\helpers\Markdown;

class DocsUtility extends Utility
{
    // Page key = Markdown file in content/, e.g. 'webshop/producten' → content/webshop/producten.md
    private const NAV = [
        'Start' => [
            'start/welkom' => 'Welkom',
            'start/menu' => 'Het menu',
            'start/talen' => 'Talen',
            'start/testfase' => 'Testfase',
        ],
        'Webshop' => [
            'webshop/producten' => 'Producten',
            'webshop/categorieen-merken' => 'Categorieën en merken',
            'webshop/klanten' => 'Klanten',
            'webshop/bestellingen' => 'Bestellingen',
            'webshop/winkeladres' => 'Winkeladres',
            'webshop/instellingen' => 'Instellingen',
        ],
        'Pagina\'s' => [
            'paginas/paginas' => 'Home en extra pagina\'s',
            'paginas/navigatie' => 'Header en footer',
        ],
        'CMS' => [
            'cms/bestanden' => 'Foto\'s en bestanden',
            'cms/seo' => 'SEO',
        ],
    ];

    public static function displayName(): string
    {
        return 'Handleiding';
    }

    public static function id(): string
    {
        return 'docs';
    }

    public static function icon(): ?string
    {
        return 'book';
    }

    public static function contentHtml(): string
    {
        $pages = array_merge(...array_values(self::NAV));
        $page = Craft::$app->getRequest()->getQueryParam('page');
        $page = isset($pages[$page]) ? $page : array_key_first($pages);

        $markdown = file_get_contents(dirname(__DIR__) . "/content/$page.md");

        return Craft::$app->getView()->renderTemplate('docs/_utility.twig', [
            'nav' => self::NAV,
            'page' => $page,
            'html' => Markdown::process($markdown, 'gfm'),
        ]);
    }
}
