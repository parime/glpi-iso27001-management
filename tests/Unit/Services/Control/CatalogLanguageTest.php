<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\AiActCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\CatalogLanguage;
use GlpiPlugin\Grcmanager\Services\Control\DoraCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Iso27701CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Nis2CatalogDefaults;
use PHPUnit\Framework\TestCase;

/**
 * Issue #582 : langue d'affichage des catalogues, la langue de l'utilisateur si le catalogue l'a,
 * sinon l'anglais, sinon le français.
 */
final class CatalogLanguageTest extends TestCase
{
    /** @var mixed */
    private $sessionLanguage;

    protected function setUp(): void
    {
        $this->sessionLanguage = $_SESSION['glpilanguage'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->sessionLanguage === null) {
            unset($_SESSION['glpilanguage']);
        } else {
            $_SESSION['glpilanguage'] = $this->sessionLanguage;
        }
    }

    public function testCurrentLanguageIsTheTwoLetterCodeOfTheSession(): void
    {
        $_SESSION['glpilanguage'] = 'it_IT';
        self::assertSame('it', CatalogLanguage::current());
        $_SESSION['glpilanguage'] = 'pt_BR';
        self::assertSame('pt', CatalogLanguage::current());
        unset($_SESSION['glpilanguage']);
        self::assertSame('en', CatalogLanguage::current());
    }

    public function testUserLanguageThenEnglishThenFrench(): void
    {
        $entry = ['fr' => 'Bonjour', 'en' => 'Hello', 'it' => 'Buongiorno'];
        self::assertSame('Buongiorno', CatalogLanguage::pick($entry, '', 'it'));
        self::assertSame('Bonjour', CatalogLanguage::pick($entry, '', 'fr'));
        self::assertSame('Hello', CatalogLanguage::pick($entry, '', 'de'));
        self::assertSame('Hello', CatalogLanguage::pick(['fr' => 'Bonjour', 'en' => 'Hello'], '', 'it'));
        self::assertSame('Bonjour', CatalogLanguage::pick(['fr' => 'Bonjour', 'en' => ''], '', 'it'));
        self::assertSame('', CatalogLanguage::pick([], '', 'it'));
        self::assertSame('en', CatalogLanguage::resolve(['fr' => 'Bonjour', 'en' => 'Hello'], '', 'it'));
    }

    public function testPrefixedFields(): void
    {
        $article = ['title_fr' => 'Maîtrise de l\'IA', 'title_en' => 'AI literacy', 'title_it' => 'Alfabetizzazione'];
        self::assertSame('Alfabetizzazione', CatalogLanguage::pick($article, 'title_', 'it'));
        self::assertSame('AI literacy', CatalogLanguage::pick($article, 'title_', 'es'));
    }

    public function testItalianUserSeesNis2InItalianAndOtherCatalogsInEnglish(): void
    {
        $_SESSION['glpilanguage'] = 'it_IT';
        self::assertSame(
            'Misure di gestione dei rischi di cibersicurezza',
            CatalogLanguage::pick(Nis2CatalogDefaults::ARTICLES[21])
        );
        self::assertSame('gestione degli incidenti', CatalogLanguage::pick(Nis2CatalogDefaults::REQUIREMENTS['21.2.b']));
        // DORA : intitulés officiels en italien, exigences résumées en anglais seulement.
        self::assertSame('Comunicazione', CatalogLanguage::pick(DoraCatalogDefaults::ARTICLES[14]));
        $doraRequirement = DoraCatalogDefaults::REQUIREMENTS[array_key_first(DoraCatalogDefaults::REQUIREMENTS)];
        self::assertSame($doraRequirement['en'], CatalogLanguage::pick($doraRequirement));
        // Catalogue sans italien : anglais, sans erreur.
        foreach (Iso27701CatalogDefaults::TABLES as $table) {
            self::assertSame($table['en'], CatalogLanguage::pick($table));
        }
        self::assertSame('Sorveglianza umana', CatalogLanguage::pick(AiActCatalogDefaults::ARTICLES[14], 'title_'));
    }
}
