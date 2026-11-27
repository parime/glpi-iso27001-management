### Added

- Connecteur SentinelOne : réglage « Criticité minimale des vulnérabilités importées » (Configuration > Plugins > GRC Manager > Connecteurs). Les vulnérabilités applicatives sous le seuil sont écartées avant l'enregistrement, ce qui allège la base sur un grand parc ; par défaut tout est importé, comme avant. La criticité fournie par SentinelOne est utilisée, sinon le score CVSS ; relever le seuil n'efface pas les vulnérabilités déjà importées (#1160).
