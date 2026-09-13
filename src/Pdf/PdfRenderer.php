<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Rendu HTML -> PDF réutilisable par les 3 rapports d'audit externe (SoA, registre de risques,
 * programme d'audit/CAPA — ROADMAP.md "Version 1.5"). Même configuration Dompdf que le plugin
 * jumeau assetsign-glpi (`GlpiPlugin\Assetsign\Pdf\PdfRenderingHelpers::renderPdf()`, même auteur)
 * — le *pattern* est repris à l'identique, pas le code lui-même (dépendance Composer indépendante
 * par plugin) : réseau désactivé (`isRemoteEnabled = false`, aucune image externe ne doit pouvoir
 * être chargée dans un rapport de conformité), racine de fichiers restreinte à GLPI_DOC_DIR
 * (`chroot`), police DejaVu Sans (supporte les accents français sans configuration supplémentaire,
 * contrairement aux polices PDF standard).
 *
 * NOTE : dépend de la constante GLPI_DOC_DIR (définie par le cœur GLPI au chargement), pas testé
 * unitairement — voir phpstan.neon.dist pour l'exclusion, même rationale que RiskMatrixConfig.php.
 */
final class PdfRenderer
{
    public static function renderHtmlToPdf(string $html): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', GLPI_DOC_DIR);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
