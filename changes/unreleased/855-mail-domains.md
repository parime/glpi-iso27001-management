### Added

- **Sécurité de la messagerie et des domaines** (#855, mesures A.5.14 et A.8.21) : nouvel onglet de la
  rubrique Sécurité technique. Les domaines de l'organisation sont saisis dans le plugin (sélecteurs
  DKIM, échéance du domaine et du certificat des serveurs de messagerie) ; sur clic, le plugin lit
  seulement les enregistrements DNS publics : SPF, clés DKIM des sélecteurs déclarés, politique DMARC
  (none, quarantine, reject), MTA-STS, TLS-RPT et DNSSEC (délégation signée). Aucun e-mail envoyé,
  aucune connexion aux serveurs de messagerie, aucun service tiers ; sans domaine saisi, aucune
  requête. Écarts signalés avec un conseil (DMARC absent ou en « none », SPF « +all » ou neutre,
  domaine non signé, clé DKIM absente, échéance à moins de 30 jours ou dépassée...), preuve sur les
  fiches des mesures A.5.14 et A.8.21 et source de KRI « domaines sans politique DMARC appliquée ».
