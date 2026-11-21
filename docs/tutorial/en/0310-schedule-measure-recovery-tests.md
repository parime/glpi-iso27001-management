### Schedule and measure recovery tests (control A.5.30)

The auditor asks for evidence that ICT recovery is tested regularly and that the measured times
meet the objectives. The profile needs the **Business continuity** right.

1. Open a fictitious business process, for instance "Invoicing (fictitious)", with an **RTO** of
   4 h and an **RPO** of 1 h, then the **Recovery strategy** tab.
2. In **Test programme**, set the **test frequency** (for instance 6 months). 0 keeps the frequency
   of the periodic review of continuity tests (yearly by default). The next due date and the
   **coverage by year** (green badge: objectives met, red: objectives missed, grey: not tested) are
   shown next to it.
3. Record a test: measured recovery time, **data loss** in minutes (the time between the last
   usable backup and the incident; leave it empty if it was not measured, 0 if nothing was lost),
   **type of exercise** (tabletop, functional, technical restore test, full-scale) and, if needed,
   the **item tested** among the dependencies of the process. The test is compared with the RTO and
   RPO of the process, and with those of the item tested (Classification tab).
4. On the **Crisis exercises** page, choose the type when starting; it can be changed in the crisis
   room until the review is validated. A test recorded from the crisis room takes the type of the
   exercise.
5. The list of business processes has a **Recovery test programme** button: every process, its
   coverage over four years, the frequency and the next due date, with the number of processes
   overdue or not tested this year. This is the view to show the auditor.
6. A process not tested in time (or never tested, one period after its creation) is reported to its
   owner and to the CISO group by the automatic action **businessprocessreminder**. Nothing is
   changed automatically.
7. On an item (server, application...), the **Business processes** tab lists the recovery tests
   that covered it. The list of types is set in **Setup > GRC Manager**, **Types of recovery
   exercise** box.
