### Monitor data breaches of work email addresses without a subscription

Data breach monitoring checks every day whether your users' work email addresses appear in known
data breaches (control A.5.7, threat intelligence). It works with **XposedOrNot**, a free service,
or with **Have I Been Pwned**, whose API key is paid.

1. Open **Setup > Plugins > GRC Manager**, **Connectors** tab, **Data breach monitoring** block (you
   need the right to configure the plugin and the right on sensitive registers).
2. Enter the **work email domains** to monitor (for example `example.com`): only addresses on these
   domains are sent to the provider, never a personal address.
3. Choose **XposedOrNot** ("Free, no key needed" badge), tick **Enable data breach monitoring**, then
   **Save**. The **Test the connection** button checks that the service answers, with the example
   address of its documentation: no real address is sent.
4. Without a key, XposedOrNot checks up to 20 addresses per run (the service allows 25 per hour and
   100 per day); the next run resumes where the previous one stopped. To cover many users faster,
   set the **breachmonitor** automatic action (Setup > Automatic actions) to run every hour.
5. If you verified your domain on the XposedOrNot dashboard, paste the **domain API key** it gives
   you: the whole domain is then checked with a single request. The key is encrypted by GLPI and
   never displayed again.
6. Exposures found appear in **GRC > People and awareness > Data breach exposures** and the CISO group
   is notified. For each one, have the password changed if passwords were exposed, then mark it as
   **Handled (password changed…)**.

Checked addresses are sent to a third party (the chosen provider): record it in your register of
processing activities.
