### Match operating systems with CVEs (control A.8.8)

Fleet vulnerabilities follow installed software, firmware and **operating systems** ("Operating
systems" tab of a computer in GLPI: name, version, kernel version). An operating system is matched
with a CVE only after you have validated it. You need the "Sensitive registers" right of the
plugin with update, and the right to read software.

1. Open Technical security > **Software to watch**. The operating systems of the fleet are listed
   with an **Operating system** badge, for instance "Microsoft Windows 11 Pro 23H2" or "Debian
   GNU/Linux 12 (bookworm)". For Windows 10 and 11, the feature release (23H2) is part of the
   name, as in the NVD database.
2. For Windows 10 and 11, Windows Server 2016 to 2025, Debian, Ubuntu and Red Hat Enterprise
   Linux, the "Watched product" column shows a **Proposed match**, for instance
   `cpe:2.3:o:microsoft:windows_11_23h2`. Check it, then click **Accept the proposal**. For any
   other operating system, click **Look up the CPE** as for software.
3. Open **Fleet vulnerabilities**: the CVEs of the operating system carry the "Operating system"
   badge. The table, the "Vulnerabilities" tab of the computer and the CVE tab of a security
   incident only name the assets of your entities.
4. Versions are compared as in NVD: build number for Windows (kernel version), major version for
   Debian and RHEL, "22.04" for Ubuntu. When the inventory does not give the revision of the
   Windows build ("10.0.22631" instead of "10.0.22631.4317"), the computer stays "version not
   verifiable": it is neither reported vulnerable nor reported patched.
5. Update tickets link the affected computers of the ticket's entity, including those that become
   affected after the ticket was opened.
