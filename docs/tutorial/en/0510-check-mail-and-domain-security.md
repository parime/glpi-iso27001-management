### Check the security of e-mail and domains (controls A.5.14 and A.8.21)

A poorly protected domain lets anyone send e-mails that seem to come from the organisation
(spoofing, phishing). The **Mail and domain security** tab of the **Technical security** section
shows, domain by domain, the protections published in the DNS. The plugin only reads public DNS
records: it sends no e-mail and connects to no mail server. As long as no domain is entered, it makes
no query.

1. In **Add a domain**, enter the name (the part after the @ of the addresses, for example
   `example.org`), the **DKIM selectors** given by your e-mail service (`selector1`, `selector2` for
   Microsoft 365, `google` for Google Workspace) and the **expiry dates** of the domain (customer area
   of the registrar) and, if your mail servers are on your premises, of their certificate. Also add
   the domains the organisation owns without using them: they must announce that they send nothing.
2. Click **Check now** (or **Check all domains**). Each column takes a colour: green for a protection
   in place, orange for a protection to improve, red for a gap to fix, grey for what is not read or
   not entered yet.
3. The **What to do** frame lists the gaps, the most serious first, with plain advice: DMARC missing
   or set to "none" (fake e-mails are still delivered), SPF that allows everyone (`+all`), domain not
   signed with DNSSEC, DKIM key not found, domain or certificate expiring within 30 days. The fixes
   are made with your DNS host: the plugin changes nothing.
4. The forms of controls **A.5.14** and **A.8.21** show the summary as evidence; the KRI source
   **Mail domains without an enforced DMARC policy (%)** lets you follow the progress.
