### Measure configuration compliance with OpenSCAP (control A.8.9)

OpenSCAP checks a machine against a hardening guide (CIS, ANSSI, DISA STIG... profiles of the SCAP
Security Guide project) and writes an XCCDF or ARF results file. The plugin reads this file: it
starts no scan and changes nothing on the machines. You need the plugin right and the native right
to read computers; importing requires the right to update the plugin.

1. On the machine, run the scan with a results file, for example
   `oscap xccdf eval --profile <profile> --results-arf results-arf.xml <ssg-datastream.xml>`.
   The `--results` option (XCCDF only) works too. The HTML report cannot be imported.
2. Open Technical security > **Configuration compliance**. The "What is this page for?" box recalls
   what is expected. In **Import OpenSCAP results**, choose the file and click **Import**. One file
   can hold several machines; a file already imported is not counted twice. A fictitious example
   file can be downloaded.
3. Each result is matched to the GLPI computer of the same name (host name, full name or short
   name), among those you can see. Results without a computer, or with several computers of that
   name, are listed in **Scan results not matched to a computer**: choose the computer and click
   **Match**.
4. The counters give the average compliance, the number of machines scanned, those below the 80 %
   target and those with a high severity rule failed. The rate of a scan is the share of rules
   passed among the rules checked (not applicable rules do not count). The list shows the last scan
   of each computer, the least compliant first, with the change since the previous scan of the same
   profile.
5. **Details** opens a scan: failed rules by severity and history of the machine. The **Propose a
   ticket** button creates, only when you click, a remediation ticket linked to the computer with
   the failed rules. The most frequent failed rules of the fleet are at the bottom of the page.
6. On control A.8.9, the "Configuration compliance" box shows these figures and the date of the
   last scan. Risk indicators offer the source "Scanned computers with a high severity configuration
   rule failed (%)".

Import security: the file is read as a stream (large ARF reports are accepted), any document type or
entity declaration is refused and no network access is made while reading. The file itself is not
kept, only the counts and the failed rules.
