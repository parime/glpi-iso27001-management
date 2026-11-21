<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Méthodes du cœur retirées de GLPI 12 : un appel donne « Call to undefined method » et une
 * page 500 (front/accessreviewbmcaccount.php, front/auditlog.php avant la version 2.80.0).
 * L'analyse PHPStan GLPI 12 ne couvre pas front/ et la matrice d'accès tourne sous GLPI 11 :
 * ce contrôle textuel balaie donc front/, src/, ajax/ et inc/.
 */
final class RemovedCoreMethodsTest extends TestCase
{
    /** Appel retiré => remplacement à utiliser. */
    private const REMOVED = [
        'Html::displayRightError(' => 'throw new \Glpi\Exception\Http\AccessDeniedHttpException()',
        'Html::displayNotFoundError(' => 'throw new \Glpi\Exception\Http\NotFoundHttpException()',
        'Html::displayErrorAndDie(' => 'throw new \Glpi\Exception\Http\BadRequestHttpException()',
    ];

    public function testNoCallToCoreMethodsRemovedInGlpi12(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        foreach (['front', 'src', 'ajax', 'inc'] as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $code = (string) file_get_contents($file->getPathname());
                foreach (self::REMOVED as $call => $replacement) {
                    if (str_contains($code, $call)) {
                        $found[] = substr($file->getPathname(), strlen($root) + 1) . ' : ' . $call . ') -> ' . $replacement;
                    }
                }
            }
        }

        self::assertSame([], $found);
    }
}
