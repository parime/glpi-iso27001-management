### Follow the hardware warranty (controls A.7.13 and A.8.9)

The plugin reads the financial information already recorded in GLPI ("Management" tab of a
computer, a monitor, a network device...): the **warranty start date** and the **warranty
duration** in months. It changes nothing. You need the "Sensitive registers" right of the plugin,
the native right to read financial information ("Management") and the right to read each type of
hardware.

1. On a fictitious computer, for instance "PC-ACCOUNTS-01", open the **Management** tab and enter a
   warranty start date (2023-01-01) and a duration (36 months). GLPI shows the warranty expiration
   date (2026-01-01).
2. Open Technical security > **Fleet vulnerabilities**, then scroll down to the **Hardware
   warranty** card, below the "End of support" card. Four counters give the number of pieces of
   hardware whose warranty is **expired**, **ending soon**, **running**, or **unknown** (no date or
   no duration: never counted as under warranty). A "Lifetime" duration counts as under warranty.
3. The table crosses the **hardware type** and the **entity**; only your entities are shown. The
   list below gives the hardware to handle, with a link to each record; the **All hardware** button
   shows all of it.
4. The "ending soon" threshold is the one of the end of support (90 days by default), set in the
   plugin configuration, "End of support (endoflife.date)" box. It applies even when endoflife.date
   is not enabled.
5. For each expired warranty, check that a maintenance contract covers the hardware, renew the
   warranty or plan the replacement. Click **Export (CSV)** to hand the list to the auditor (one
   piece of hardware per line, with manufacturer, model and dates).
6. On controls A.7.13 and A.8.9 of the SoA, the "Hardware warranty" box shows the counters; the
   ISMS consistency checks list each piece of hardware whose warranty has expired (rule "Hardware
   whose warranty has expired").
7. **Manufacturer end of life** (optional): when a manufacturer announces the date after which a
   model no longer gets repairs, spare parts or firmware, open the plugin configuration, box
   **Manufacturer end of life of hardware models** (link "Enter the end of life dates" of the
   card). The models in service in your entities are listed: enter for instance 2025-06-30 for the
   fictitious model "Test-Model 100", then **Save**. Nothing is written in the GLPI models; an
   emptied date is deleted, and each change is recorded in the audit log.
8. The card then shows the counters "Manufacturer end of life reached", "soon" (same threshold),
   "supported" and "no date", and the **Manufacturer end of life** column; the **To be handled**
   filter includes the hardware whose end of life is reached or near, even under warranty. The
   export adds the date and the state, the evidence of the controls counts them, and the
   consistency check lists each affected piece of hardware (rule "Hardware whose model has reached
   the manufacturer end of life").
