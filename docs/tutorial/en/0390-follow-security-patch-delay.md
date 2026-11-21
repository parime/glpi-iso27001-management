### Follow the security patch delay (controls A.8.8 and A.8.19)

The GLPI inventory agent reports the operating system of each computer, its version and, depending
on the platform, the installed updates (Windows KB articles) or the installed packages with their
date. The plugin reads this data; it starts no update and changes nothing in GLPI. You need the
plugin right and the native right to read computers.

1. Open Technical security > **Security patches**. The "What is this page for?" box recalls what is
   expected.
2. In **Target delays by criticality**, set the number of days allowed since the last patch for
   critical, important and standard computers (15, 30 and 60 days by default). The criticality comes
   from the C/I/A classification of the computer; a computer that a declared network flow publishes
   on the Internet is critical. This requires the right to update the plugin.
3. The counters give the rate of computers on time, the number late and of unknown state (no dated
   update: no agent, or a system whose agent does not report updates), and the critical servers and
   computers published on the Internet that are late.
4. The list starts with the late computers, those exposed on the Internet and critical first. For
   each one: system and version, date and origin of the last known patch, target delay, days late,
   known CVEs of the system and end of support, date of the last inventory ("Old inventory" badge
   beyond 30 days). Apply the patches, then wait for the next inventory.
5. On controls A.8.8 and A.8.19, the "Security patches" box shows the rate, the late computers and
   the date of the computation. The dashboard offers the card "Computers with security patches on
   time (%)" and risk indicators the source "Critical servers with overdue patches".
