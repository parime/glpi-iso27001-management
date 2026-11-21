### Use phishing simulations as evidence of awareness (clause 7.3, control A.6.3)

1. Before the first simulation, inform the staff that phishing simulations take place (purpose:
   awareness, not sanction), and record this processing in the GDPR register: the results per
   person are personal data. Decide how long you keep them (for instance until the remedial
   training is done).
2. Run the campaign with your simulation tool (GoPhish, the tool of a provider...). **The plugin
   never sends any email.**
3. In the plugin menu, open **Operations > Phishing simulations** (next to the trainings), click
   **Add** and fill in the name (for instance "Fake parcel delivery (fictitious)"), the date, the
   tool, the scenario and the targeted population. Choose the entity of the people concerned.
4. In the **Results** tab, choose the CSV export of the results (one line per person) and click
   **Next**. The usual columns are recognised: email address or login, email received, opened,
   link clicked, data entered, reported, or the "status" column of a GoPhish export. Check them,
   then click **Import**. A new import replaces the results of the campaign; the **Example file**
   shows the expected format with fictitious addresses.
5. Each line is matched with an active GLPI user of the entity of the campaign by email address (or
   login). The lines not matched (unknown or ambiguous address, person of another entity) are listed
   with their line number: correct the address in GLPI or in the file, then import again.
6. The tab shows the rates out of the emails received (opened, link clicked, data entered,
   reported). The list of campaigns shows the trend from one campaign to the next. To follow the
   click rate with thresholds, create a risk indicator (KRI) with the source "Click rate of the last
   phishing simulation (%)".
7. Click **Offer a remedial training**: a training form opens with the people who clicked or
   entered data. Check it and click **Add**; nothing is saved or sent before that.
8. **Who sees what**: the results per person and the lines not matched require the right on
   sensitive registers (read; update to import or delete). Without it, only the rates are shown, and
   not at all below 5 people. Each person sees their own results in **Preferences > My phishing
   simulations**. Once the follow-up is done, click **Delete the results per person**: the rates of
   the campaign are kept.
9. On control **A.6.3** and on ISMS requirement **7.3**, the "Phishing simulations" box shows the
   number of campaigns within 12 months, the rates of the last campaign and the change in clicks
   since the previous one.
10. **Rates by group and by entity**: the **Results** tab also shows the rates by GLPI group of the
    matched people (a person in several groups counts in each). Without the right on sensitive
    registers, the groups of fewer than 5 people are combined into "Other groups", hidden too if
    they remain too small. When your campaigns cover several entities, the list of campaigns shows
    the last campaign of each entity. These rates are anonymous and stay after the results per
    person are deleted. On the dashboard, add the card "Phishing simulations: rates by campaign (%)"
    to follow the clicks, the data entered and the reports from one campaign to the next.
11. **Retention period**: in the configuration of the plugin, set **Phishing simulations: retention
    of the results per person** (in months from the date of the campaign; 0 = until you delete them
    by hand). It needs the right to update the sensitive registers. The daily automatic action
    "phishingretention" then deletes the expired results per person and keeps the rates; the
    **Results** tab shows the planned date. Write the same period in your GDPR register.
