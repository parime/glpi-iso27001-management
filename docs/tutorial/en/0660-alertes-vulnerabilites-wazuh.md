### Turn Wazuh alerts into incidents and follow critical vulnerabilities (controls A.8.8, A.5.24 to A.5.26)

When the GLPI Wazuh plugin is installed and active, GRC reads its alerts and vulnerabilities, without
ever changing anything in it. Without that plugin, none of the following appears.

1. Open Assistance > Security incidents, **EDR alerts import** tab (right to create an incident).
   The **Wazuh alerts to review** box lists the alerts of the last 30 days on the computers you can
   see. Repeated alerts (same rule on the same computer) form a single group, with their number, the
   rule level, the agent, the last alert and a link to the alerts of the computer in the Wazuh
   plugin.
2. The **Minimum rule level** filter shows levels 7 and above by default; choose **Level 12 and
   above** to keep only the most serious ones, or **All levels**.
3. Tick the groups that deserve an incident, then **Create the incidents of the selected groups**.
   Each group becomes a security incident (SIEM origin) with the computer, a severity taken from the
   rule level (12 and above: critical, 7 to 11: major), the timestamps, the MITRE ATT&CK techniques
   of the rule and the link to the alerts. An alert is never imported twice.
4. When the same alert comes back while the incident is still open, the group shows the badge
   **Already in an incident: new repetitions**; importing it adds a task to that incident instead of
   opening a new one. Once the incident is solved or closed, a new repetition opens a new incident.
5. In Assets > Asset security, the **Detected by Wazuh only** box names the computers affected by
   each vulnerability and shows first the **computers with untreated critical vulnerabilities**:
   CVSS 9 or more, still reported by Wazuh and without an approved, current waiver. An accepted
   waiver removes the CVE from the count.
6. The same indicator is available as a dashboard card ("Computers with untreated critical
   vulnerabilities (Wazuh)") and as a key risk indicator (KRI) source, to set an alert threshold.
