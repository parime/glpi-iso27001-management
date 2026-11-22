### Where has it gone? Finding a screen after the new navigation

The navigation of the plugin was reorganised so that each screen is where you would naturally look
for it: the settings in the configuration page of the plugin, the daily work in the matching GLPI
menu (Assistance, Assets, Management) or in a clearly named section of the **GRC & Compliance**
menu. The addresses of the screens and the rights do not change: your bookmarks and links still
work, and the breadcrumb at the top of each page shows the new location.

**Log out and log in again** once: GLPI keeps the menu in memory during the session.

#### Daily work screens

| Screen | Former location | New location |
|---|---|---|
| Assessment by framework | GRC > Dashboard | GRC > Regulations |
| Maturity score, maturity by control, compliance calendar, consistency checks, certification readiness | GRC > Dashboard | GRC > Dashboard (unchanged) |
| Single points of failure (impact analyses), domino effect, EBIOS RM studies, risk indicators (KRI) | GRC > Risks | GRC > Risk analyses |
| Supplier risks | GRC > Risks | Management > Supplier security (and the **Security** tab of the supplier form) |
| Supplier questionnaire templates | link of the supplier risks page | Management > Supplier security |
| Risk heatmap, catalog of typical risks | outside the menu (links of the risk list) | GRC > Risks, tabs |
| Risk import and export | Tools | GRC > Risks, **Import and export** tab |
| Approved applications | GRC > Risks | GRC > Policies and procedures |
| SoA scoping, blocked controls, effectiveness tests | GRC > Compliance | GRC > Security controls (SoA) |
| Frameworks | GRC > Compliance | GRC > Regulations (enabling them: Setup, Frameworks tab) |
| ReCyF measures, NIS2 Italy requirements (ACN) | outside the menu (link of the configuration) | GRC > Regulations, when the framework is enabled |
| ISMS requirements, issues and interested parties, responsibility matrix, official contacts, obligations | GRC > Compliance | GRC > ISMS organisation |
| Delegated decisions | Tools | GRC > ISMS organisation |
| Record of processing activities, data subject requests, breach register | GRC > Compliance | GRC > Privacy (GDPR) |
| Policies, policy templates, operating procedures, control coverage by policies | GRC > Compliance | GRC > Policies and procedures |
| Evidence, evidence pack, evidence coverage | GRC > Compliance | GRC > Audits and evidence |
| Internal audits, audit programme, findings of previous audits, audit plan of the certification body | GRC > Audits and improvement | GRC > Audits and evidence |
| Nonconformities, gap backlog, objectives, management reviews | GRC > Audits and improvement | GRC > Nonconformities and improvement |
| Access reviews, periodic reviews, account hygiene, MFA coverage, file share access rights | GRC > Operations | GRC > Access and accounts |
| Personnel security compliance, trainings, phishing simulations, competences | GRC > Operations | GRC > People and awareness |
| Data breach exposures | GRC > Technical security | GRC > People and awareness |
| Active Directory security audit | GRC > Technical security | GRC > Access and accounts |
| Fleet vulnerabilities, most exposed machines, products, software to watch, licences, patches, technical checks, configuration compliance, encryption, logging, Zabbix, Uptime Kuma, server controllers, backups, mail and domains, network flow matrix | GRC > Technical security | Assets > Asset security |
| Physical security, secure development, code security | GRC > Compliance | Assets > Asset security, **Sites and development** group |
| Manufacturer end of life of models | plugin configuration | **Manufacturer end of life** tab of the model form, or Assets > Asset security > Hardware end of life |
| Security incidents | Assistance, after Recurrent changes | Assistance, right after Changes |
| Remediation playbooks, EDR alerts import, security audit log | links of the incident list | Assistance > Security incidents, tabs |
| Business processes (BIA), crisis scenarios, crisis exercises, recovery test programme | GRC > Operations | Assistance > Crisis and continuity |
| Crisis message templates | link of the incident list | Assistance > Crisis and continuity |

#### Settings and action buttons

| Setting or button | Former location | New location |
|---|---|---|
| Configuration page of the plugin | Tools > GRC Manager (one long page) | Setup > Plugins > GRC Manager, in eight tabs |
| Change of the risk matrix size | Tools | Setup > Plugins > GRC Manager, Risks tab |
| Creation and import of custom frameworks | GRC > Compliance > Custom frameworks | Setup, **Frameworks** tab |
| Connection to Zabbix, Uptime Kuma, Veeam, DNS server of the mail checks | page of each screen | Setup, **Connectors** tab |
| Connection to Microsoft Entra ID (Graph), GitHub and GitLab | page of each screen | Setup, **Connectors** tab |
| Remote access groups of the MFA coverage | MFA coverage | Setup, **People and policies** tab |
| Computers to encrypt, patch deadlines, watched software publishers, expected logging, assets to back up | page of each screen | Setup, **Fleet** tab |
| Audit programme, certification readiness, evidence pack size | page of each screen | Setup, **Controls, audits and evidence** tab |
| Entity that keeps the DORA register | DORA register | Setup, **Frameworks** tab (when DORA is enabled) |
| Supplier answer by secure link | configuration (single page) | Setup, **General** tab |
| Decisions that can be delegated during an absence | configuration (single page) | Setup, **People and policies** tab |
| **Update now** (endoflife.date) and product links | plugin configuration | Assets > Asset security > Software to watch |
| **Send the report now** (vulnerabilities) | plugin configuration | Assets > Asset security > Fleet vulnerabilities |
| **Read now** (SentinelOne) | plugin configuration | Assistance > Security incidents > EDR alerts import |

A former link to a block of the configuration (`config.php#…`) opens the right tab, or the screen
that now hosts the action. A former settings form sent from a daily work page is still accepted for
one version: it saves, then takes you to the configuration tab with a message.
