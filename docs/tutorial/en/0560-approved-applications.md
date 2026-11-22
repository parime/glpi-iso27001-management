### Say which applications and online services are allowed (control A.5.23)

The supplier risk register assesses a vendor as a whole; control A.5.23 also asks you to know which
online services are used, what for and with which data. The plugin records this decision on the
appliances of GLPI, the same ones used by secure development: no application is entered twice.

1. Open an appliance of GLPI (**Assets > Appliances**, or create it), for example "Online file
   sharing tool", then its **Approved use** tab.
2. Choose the **status**: *Under evaluation* while the security team studies it, *Approved*,
   *Approved with restrictions* or *Refused*. Fill in the matching supplier and software of GLPI,
   the owner, the user departments and the hosting (online or on premises).
3. Describe the **allowed uses** ("sharing files with customers and partners"), the
   **restrictions** ("no customer personal data, links limited to 30 days") and the **most
   sensitive data allowed** ("Low: public or internal data only"). A red badge warns when the C/I/D
   classification of the appliance is above this level. Add the waivers granted, from the waiver
   register.
4. Keep the **review every 12 months** (0 for no review). When it is due, the automatic action
   "approvedappreview" opens a reassessment ticket for the owner; after the review, tick **Review
   done today** and save.

The **Risks > Approved applications** page gives the full register with its badges, the overdue
reviews and the sheets to complete, then the **comparison with the software inventory**: installed
software that matches a refused application or one still under evaluation, and software missing
from the register (filter by software category to narrow the list). Nothing is uninstalled or
changed: open a ticket if action is needed.

Every user, even without any right on the plugin, finds the list in **My settings**, **Authorised
applications** tab: what they may use, what for, with which data, and the applications not to use.
The sheet of control A.5.23 shows these figures as evidence.
