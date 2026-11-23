### Import the reports of a vulnerability scanner

The vulnerabilities of the fleet first come from the inventoried software. A free network scanner
such as Greenbone Community Edition (OpenVAS) finds others: exposed services, obsolete protocols,
sensitive files. To turn them into evidence for control A.8.8:

1. In Greenbone, open the report of a finished scan, choose **Download** and the **XML** format.
2. In GLPI, open **Assets > Asset security**, card **Vulnerabilities**, then **Vulnerability scanner
   reports**. The **Download an example file** link gives a fictitious report to try.
3. Choose the file and click **Import**. Each finding is matched to the GLPI computer with the same
   name or IP address. Hosts that are not recognised are listed in **Scanned hosts not matched to a
   computer**: choose the computer and click **Match**; the next imports reuse this choice.
4. Handle the critical and high findings first: the **Propose a ticket** button creates a ticket
   linked to the computer, with the finding and its solution. Nothing is created without this click.
5. After the next scan, import the new report: findings still present are updated without
   duplicates, those that disappeared become **Fixed**. The **Import history** shows the change from
   one scan to the next.

Open findings also appear on the form of control A.8.8 and in the **Security 360°** tab of the
computer; the KRI "Open critical or high scanner findings" tracks their number over time.
