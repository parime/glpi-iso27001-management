<?php

/**
 * Issue #1083 : plans de continuité et de reprise. Fragment inclus par tests/security/setup.php (jeu
 * de données) : un plan de reprise informatique par entité (A et B), qui couvre le processus de son
 * entité, avec un test enregistré. Le fragment se relance sans doublon ($add, $insertOnce).
 */

use GlpiPlugin\Grcmanager\ContinuityPlan;
use GlpiPlugin\Grcmanager\Install\ContinuityPlanTables;

foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $ids['continuityplan_' . $suffix] = $add(new ContinuityPlan(), [
        'name'         => 'Plan de reprise securite ' . $suffix,
        'plan_type'    => 'pri',
        'entities_id'  => $entity,
        'is_recursive' => 0,
        'scope'        => 'Salle serveur ' . $suffix,
        'procedures'   => "1. Declencher
2. Restaurer",
    ]);
    $insertOnce(ContinuityPlanTables::ITEMS, [
        'plugin_grcmanager_continuityplans_id' => $ids['continuityplan_' . $suffix],
        'itemtype'                             => \GlpiPlugin\Grcmanager\BusinessProcess::class,
        'items_id'                             => (int) $ids['process_' . $suffix],
    ]);
    $ids['continuityplantest_' . $suffix] = $insertOnce(ContinuityPlanTables::TESTS, [
        'plugin_grcmanager_continuityplans_id' => $ids['continuityplan_' . $suffix],
        'test_date'                            => '2026-01-15',
        'result'                               => 'partial',
        'summary'                              => 'Restauration testee',
        'gaps'                                 => 'Delai depasse',
        'users_id'                             => 2,
    ]);
}
