<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-grc-manager
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Crisis;

/**
 * Issue #616 : modèles de messages de crise fournis à l'installation (bibliothèque vide), dans la
 * langue de l'installation, modifiables ensuite. Les variables entre accolades ne se traduisent pas.
 */
final class CrisisMessageDefaults
{
    /**
     * @return list<array{situation: string, stage: string, audience: string, name: string, subject: string,
     *     body: string}>
     */
    public static function definitions(): array
    {
        return [
            [
                'situation' => CrisisCommunicationRules::SITUATION_MAJOR_INCIDENT,
                'stage'     => 'first',
                'audience'  => 'internal',
                'name'      => __('Major incident: first internal message', 'grcmanager'),
                'subject'   => __('[{reference}] Security incident in progress: {title}', 'grcmanager'),
                'body'      => __(
                    "Hello,\n\nA security incident is in progress: {title} (reference {reference}), detected on "
                        . "{detection_date}.\nAffected systems: {affected_systems}.\n\nThe crisis team is handling "
                        . "it. Until further notice:\n- do not switch off or restart the affected equipment;\n"
                        . "- report anything unusual to the IT department;\n- do not talk about the incident "
                        . "outside the organization.\n\nNext update: {next_update}.\n\n{author}",
                    'grcmanager'
                ),
            ],
            [
                'situation' => CrisisCommunicationRules::SITUATION_DATA_BREACH,
                'stage'     => 'first',
                'audience'  => 'external',
                'name'      => __('Data breach: information of the people concerned', 'grcmanager'),
                'subject'   => __('Information about an incident affecting your personal data', 'grcmanager'),
                'body'      => __(
                    "Hello,\n\nOn {detection_date}, we became aware of an incident affecting personal data "
                        . "that concern you (reference {reference}).\n\nWhat happened: {title}.\nMeasures taken: "
                        . "[to be completed].\nWhat we recommend: [to be completed, for example change your "
                        . "password and be wary of unexpected messages].\n\nFor any question, you can contact our "
                        . "data protection officer: [contact details].\n\nWe apologize for this inconvenience.\n\n"
                        . "{author}",
                    'grcmanager'
                ),
            ],
            [
                'situation' => CrisisCommunicationRules::SITUATION_UNAVAILABILITY,
                'stage'     => 'first',
                'audience'  => 'external',
                'name'      => __('Unavailability: message to users and customers', 'grcmanager'),
                'subject'   => __('[{reference}] Service disruption: {affected_systems}', 'grcmanager'),
                'body'      => __(
                    "Hello,\n\nSince {detection_date}, the following services are unavailable or disrupted: "
                        . "{affected_systems}.\n\nOur teams are working to restore them and we will keep you "
                        . "informed of the progress.\n\nNext update: {next_update}.\n\n{author}",
                    'grcmanager'
                ),
            ],
            [
                'situation' => CrisisCommunicationRules::SITUATION_RANSOMWARE,
                'stage'     => 'first',
                'audience'  => 'internal',
                'name'      => __('Ransomware: instructions to staff', 'grcmanager'),
                'subject'   => __(
                    '[{reference}] URGENT: cyber attack in progress, instructions to follow',
                    'grcmanager'
                ),
                'body'      => __(
                    "Hello,\n\nA ransomware attack was detected on {detection_date}. Affected systems: "
                        . "{affected_systems}.\n\nInstructions, effective immediately:\n- do not switch off your "
                        . "computer, but unplug its network cable or turn off its Wi-Fi;\n- do not open "
                        . "attachments or links, even from a known sender;\n- do not use removable media (USB "
                        . "keys, external drives);\n- call the IT department at once if a message asks for a "
                        . "ransom or if your files no longer open.\n\nDo not talk about the incident outside the "
                        . "organization: the crisis team is the only point of contact.\n\nNext update: "
                        . "{next_update}.\n\n{author}",
                    'grcmanager'
                ),
            ],
            [
                'situation' => CrisisCommunicationRules::SITUATION_MAJOR_INCIDENT,
                'stage'     => 'end',
                'audience'  => 'internal',
                'name'      => __('Major incident: end of crisis', 'grcmanager'),
                'subject'   => __('[{reference}] End of the incident: {title}', 'grcmanager'),
                'body'      => __(
                    "Hello,\n\nThe security incident {reference} ({title}) is now under control and the services "
                        . "have been restored.\n\nThank you for your patience and cooperation. A review will be "
                        . "carried out to learn lessons from it.\n\nRemain vigilant and report anything unusual to "
                        . "the IT department.\n\n{author}",
                    'grcmanager'
                ),
            ],
        ];
    }
}
