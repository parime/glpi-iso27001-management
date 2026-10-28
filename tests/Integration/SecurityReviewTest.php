<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use PluginGrcmanagerSecurityIncident as Incident;

/**
 * Revue de sécurité de la marketplace GLPI (2.11.0, issue #280) : chaque scénario du rapport,
 * rejoué avec un profil restreint (droits et entités actives de la session de test modifiés le
 * temps d'un appel). Un test échoue si le défaut revient.
 */
final class SecurityReviewTest extends GrcmanagerIntegrationTestCase
{
    /**
     * Exécute $fn avec les seuls droits $rights et les seules entités $entities, puis restaure la
     * session de super-admin de la suite.
     *
     * @param array<string, int> $rights
     * @param list<int> $entities
     */
    private function asProfile(array $rights, array $entities, callable $fn): mixed
    {
        $saved = [
            $_SESSION['glpiactiveprofile'], $_SESSION['glpiactiveentities'], $_SESSION['glpiactiveentities_string'],
        ];
        $_SESSION['glpiactiveprofile'] = ['interface' => 'central'] + $rights;
        $_SESSION['glpiactiveentities'] = $entities;
        $_SESSION['glpiactiveentities_string'] = implode("','", $entities);
        try {
            return $fn();
        } finally {
            [
                $_SESSION['glpiactiveprofile'], $_SESSION['glpiactiveentities'], $_SESSION['glpiactiveentities_string'],
            ] = $saved;
        }
    }

    /** Points 1, 2, 3, 6, 11, 15, 17 : un rapport généré se lit avec les droits de sa source. */
    public function testRestrictedDocumentFollowsTheAudienceOfItsSource(): void
    {
        $entity = $this->createTestEntity(0, 'PHPUnit revue A ' . random_int(1000, 9999));
        $incidentId = (int) (new Incident())->add(['name' => 'PHPUnit incident restreint', 'entities_id' => $entity]);
        $incident = new Incident();
        $this->assertTrue($incident->getFromDB($incidentId));
        $documentsId = (int) (new \Document())->add(['name' => 'PHPUnit rapport restreint', 'entities_id' => $entity]);
        \PluginGrcmanagerRestrictedDocument::register($documentsId, $incident);

        $documentReader = ['document' => READ];
        $this->assertFalse(
            $this->asProfile($documentReader, [$entity], static fn () => (new \Document())->can($documentsId, READ)),
            'Le seul droit Documents de l\'entité ne suffit plus.'
        );
        $this->assertTrue($this->asProfile(
            $documentReader + [Incident::$rightname => Incident::READALL],
            [$entity],
            static fn () => (new \Document())->can($documentsId, READ)
        ));

        [, $criteria] = \PluginGrcmanagerRestrictedDocument::addDefaultWhere([\Document::class, []]);
        $this->assertCount(1, $criteria, 'Retiré de la liste Gestion > Documents.');
        [, $untouched] = \PluginGrcmanagerRestrictedDocument::addDefaultWhere([\Computer::class, []]);
        $this->assertSame([], $untouched);
    }

    /** Points 4 et 10 : une référence CVE exige de pouvoir modifier son incident. */
    public function testCveReferenceRequiresUpdateOnItsIncident(): void
    {
        $a = $this->createTestEntity(0, 'PHPUnit revue A ' . random_int(1000, 9999));
        $b = $this->createTestEntity(0, 'PHPUnit revue B ' . random_int(1000, 9999));
        $incidentB = (int) (new Incident())->add(['name' => 'PHPUnit incident de B', 'entities_id' => $b]);
        $analyst = [Incident::$rightname => CREATE | UPDATE | PURGE | Incident::READALL];

        $added = $this->asProfile($analyst, [$a], static fn () => (new \PluginGrcmanagerSecurityIncidentCve())->add([
            'plugin_grcmanager_securityincidents_id' => $incidentB, 'cve_id' => 'CVE-2099-0001',
        ]));
        $this->assertFalse($added, 'Incident d\'une autre entité.');
        $this->assertSame(0, countElementsInTable(\PluginGrcmanagerSecurityIncidentCve::getTable(), [
            'plugin_grcmanager_securityincidents_id' => $incidentB,
        ]));

        $id = (int) (new \PluginGrcmanagerSecurityIncidentCve())->add([
            'plugin_grcmanager_securityincidents_id' => $incidentB, 'cve_id' => 'CVE-2099-0002',
        ]);
        $this->assertGreaterThan(0, $id);
        $this->assertFalse($this->asProfile(
            $analyst,
            [$a],
            static fn () => (new \PluginGrcmanagerSecurityIncidentCve())->can($id, PURGE)
        ));
    }

    /** Point 16 : l'identifiant refusé est échappé dans le message. */
    public function testInvalidCveIdentifierIsEscapedInTheMessage(): void
    {
        $incidentId = (int) (new Incident())->add(['name' => 'PHPUnit incident CVE', 'entities_id' => 0]);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
        (new \PluginGrcmanagerSecurityIncidentCve())->add([
            'plugin_grcmanager_securityincidents_id' => $incidentId, 'cve_id' => '<img src=x onerror=alert(1)>',
        ]);
        $messages = implode(' ', array_merge(...array_values($_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [[]])));
        $this->assertStringNotContainsString('<img', $messages);
        $this->assertStringContainsString('&lt;img', $messages);
    }

    /** Points 7, 18, 19, 22 : le contenu d'un onglet ne s'affiche jamais sans le droit du plugin. */
    public function testTabContentIsRefusedWithoutThePluginRight(): void
    {
        $computer = new \Computer();
        $computer->getFromDB((int) $computer->add(['name' => 'PHPUnit poste onglets', 'entities_id' => 0]));
        $risk = new \GlpiPlugin\Grcmanager\Risk();
        $risk->getFromDB((int) $risk->add(['title' => 'PHPUnit risque onglet']));
        $technician = ['computer' => READ, 'software' => READ, Incident::$rightname => Incident::READALL];

        foreach (
            [
                [\GlpiPlugin\Grcmanager\ImpactAnalysis::class, $computer],
                [\GlpiPlugin\Grcmanager\AssetClassification::class, $computer],
                [\GlpiPlugin\Grcmanager\ParkVulnerability::class, $computer],
                [\GlpiPlugin\Grcmanager\CausalLink::class, $risk],
            ] as [$tab, $host]
        ) {
            ob_start();
            $shown = $this->asProfile($technician, [0], static fn () => $tab::displayTabContentForItem($host));
            $html = (string) ob_get_clean();
            $this->assertFalse($shown, $tab);
            $this->assertSame('', trim($html), $tab);
        }
    }

    /** Point 12 : une dérogation ne se lie qu'à un actif visible, et garde les liens invisibles. */
    public function testWaiverLinksOnlyAssetsTheUserCanSee(): void
    {
        $a = $this->createTestEntity(0, 'PHPUnit revue A ' . random_int(1000, 9999));
        $b = $this->createTestEntity(0, 'PHPUnit revue B ' . random_int(1000, 9999));
        $inA = (int) (new \Computer())->add(['name' => 'PHPUnit poste A', 'entities_id' => $a]);
        $inB = (int) (new \Computer())->add(['name' => 'PHPUnit poste B', 'entities_id' => $b]);
        $field = \GlpiPlugin\Grcmanager\Waiver::linkedFieldName(\Computer::class);
        $waiver = new \GlpiPlugin\Grcmanager\Waiver();
        $waiverId = (int) $waiver->add([
            'name' => 'PHPUnit dérogation', 'date_begin' => date('Y-m-d'),
            'date_end' => date('Y-m-d', strtotime('+30 days')), $field => [$inB],
        ]);
        $this->assertGreaterThan(0, $waiverId);

        $gestionnaire = ['plugin_grcmanager' => ALLSTANDARDRIGHT, 'computer' => READ];
        $this->asProfile($gestionnaire, [$a], static fn () => (new \GlpiPlugin\Grcmanager\Waiver())->update([
            'id' => $waiverId, $field => [$inA, $inB + 100000],
        ]));

        $linked = array_column(\GlpiPlugin\Grcmanager\Waiver::getWaiverItems($waiverId), 'items_id');
        sort($linked);
        $expected = [$inA, $inB];
        sort($expected);
        $this->assertSame($expected, $linked, 'Lien vers B conservé, id inexistant refusé, A ajouté.');
    }

    /** Point 14 / 23 : activer une revue périodique exige l'accès à l'entité racine. */
    public function testPeriodicReviewActivationNeedsRootEntityAccess(): void
    {
        $sub = $this->createTestEntity(0, 'PHPUnit sous-entité ' . random_int(1000, 9999));
        $rights = [
            'plugin_grcmanager' => ALLSTANDARDRIGHT,
            'ticketrecurrent'   => ALLSTANDARDRIGHT,
            'itiltemplate'      => ALLSTANDARDRIGHT,
        ];
        $canManage = static fn (): bool => \PluginGrcmanagerPeriodicReview::canManage();
        $this->assertFalse($this->asProfile($rights, [$sub], $canManage));
        $this->assertTrue($this->asProfile($rights, [0, $sub], $canManage));
    }

    /**
     * Quality (cibles « Responsable ») : aucune cible propre au plugin ne réutilise un
     * identifiant de cible du cœur (1 = administrateur global, 2 = administrateur d'entité…).
     */
    public function testPluginNotificationTargetsStayOutOfTheCoreRange(): void
    {
        // Les deux conventions (#293 : les classes passent de inc/ à src/ famille par famille).
        $root = dirname(__DIR__, 2);
        $classes = [];
        foreach (glob($root . '/inc/notificationtarget*.class.php') ?: [] as $file) {
            $classes[] = 'PluginGrcmanagerNotificationTarget' . ucfirst((string) preg_replace(
                '/^notificationtarget(.*)\.class\.php$/',
                '$1',
                basename($file)
            ));
        }
        foreach (glob($root . '/src/NotificationTarget*.php') ?: [] as $file) {
            $classes[] = 'GlpiPlugin\\Grcmanager\\' . basename($file, '.php');
        }
        // Une classe introuvable ne doit pas sortir du contrôle sans bruit.
        $this->assertGreaterThanOrEqual(13, count($classes));
        foreach ($classes as $class) {
            $this->assertTrue(class_exists($class), $class);
            // Constantes de cible propres au plugin : hors de la plage du cœur.
            $own = [];
            $reflection = new \ReflectionClass($class);
            $class = $reflection->getName(); // casse réelle (le nom de fichier est en minuscules)
            foreach ($reflection->getReflectionConstants() as $constant) {
                if ($constant->getDeclaringClass()->getName() === $class && is_int($constant->getValue())) {
                    $this->assertGreaterThanOrEqual(1000, $constant->getValue(), "$class::{$constant->getName()}");
                    $own[] = $constant->getValue();
                }
            }
            // Cible déclarée : une constante du cœur réutilisée exprès (ITEM_USER…) ou une
            // constante de la classe ; jamais un identifiant calculé (id d'utilisateur…).
            $core = array_values(array_filter(
                (new \ReflectionClass(\Notification::class))->getConstants(),
                'is_int'
            ));
            $itemtype = (string) preg_replace('/NotificationTarget/', '', $class, 1);
            $item = is_a($itemtype, \CommonDBTM::class, true) ? new $itemtype() : null;
            $target = new $class(0, '', $item);
            $target->notification_targets = [];
            $target->addAdditionalTargets();
            foreach (array_keys($target->notification_targets) as $key) {
                [$type, $id] = array_map('intval', explode('_', (string) $key) + [1 => 0]);
                if ($type === \Notification::USER_TYPE) {
                    $this->assertContains($id, array_merge($core, $own), "$class : cible $id non déclarée.");
                }
            }
        }
    }
}
