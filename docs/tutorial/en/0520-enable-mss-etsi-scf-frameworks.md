### Follow MonServiceSécurisé, ETSI EN 319 401 or the Secure Controls Framework

Three additional frameworks are offered, disabled by default, and linked to Annex A:

- **MonServiceSécurisé (ANSSI)**, for a public body or a provider that accredits a digital service
  with the French cybersecurity agency's service: its 135 measures, in 29 themes;
- **ETSI EN 319 401**, for a trust service provider (signature, seal, time-stamping, certificates)
  audited under eIDAS: clauses 5 to 7 of the standard;
- **Secure Controls Framework (SCF)**, for an organisation that uses the SCF as its common
  framework: its 34 domains.

1. In the plugin configuration, **Compliance frameworks** box, tick the framework you need, then
   click **Save**.
2. Open the **Reference frameworks** page: a new card lists the themes, clauses or domains, with the linked
   Annex A controls (clickable) and the plugin modules that already produce evidence. The **Filter**
   field quickly finds an identifier (`AUTH`, `7.14.3`, `VPM`...).
3. On an Annex A control, a badge ("MSS AUTH", "EN 319 401 7.4.5", "SCF IAC") shows what it covers;
   the framework rate appears in the dashboard and in the per-framework assessment.

The mapping to Annex A is indicative and made by the project: review it with your auditor. The
footer of each card gives the source and the licence (Apache 2.0 for MonServiceSécurisé, a protected
standard of which only the numbers are used for ETSI, CC BY-ND 4.0 for the SCF, whose domain names
stay in English).
