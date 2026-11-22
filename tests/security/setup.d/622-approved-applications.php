<?php

/**
 * Issue #622 : fiche « usage autorisé » (approuvée) pour l'application de A et celle de B. Fragment
 * inclus par tests/security/setup.php (jeu de données).
 */

foreach (['A', 'B'] as $suffix) {
    $appliance = (int) $ids['appliance_' . $suffix];
    if (\GlpiPlugin\Grcmanager\ApprovedApplication::getForAppliance($appliance) === null) {
        \GlpiPlugin\Grcmanager\ApprovedApplication::saveFor($appliance, [
            'status'         => 'restricted',
            'hosting'        => 'online',
            'allowed_uses'   => 'Usage approuve securite ' . $suffix,
            'restrictions'   => 'Aucune donnee client ' . $suffix,
            'max_data_level' => 'low',
            'review_months'  => '12',
        ]);
    }
}
