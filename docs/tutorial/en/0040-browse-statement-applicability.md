### Browse the Statement of Applicability (SoA)

From **GRC & Conformité > Conformité**, the 93 real ISO/IEC 27001:2022 Annex A controls are
listed, seeded at install time and marked "Applicable" by default (clause 6.1.3).

![Full list of the 93 Annex A controls](screenshots/tutorial/10-control-list.png)

The list can be filtered by theme (Organizational, People, Physical, Technological) through
GLPI's native search form.

![Controls filtered on the Technological theme](screenshots/tutorial/11-control-list-filtered-theme.png)

Each control opens to adjust its applicability (applicable/not applicable/partially applicable)
and its implementation status.

![A control's form, default applicability](screenshots/tutorial/12-control-form-applicable.png)

As soon as applicability changes to "Not applicable" or "Partially applicable", a justification
becomes mandatory (clause 6.1.3 d): trying to save without one shows an error and blocks the
save.

![Partial applicability before saving, empty justification](screenshots/tutorial/13-control-form-partial-before-save.png)

![Error: justification required](screenshots/tutorial/14-control-form-justification-error.png)

Once the justification is filled in, the save succeeds.

![Justification filled in before saving](screenshots/tutorial/15-control-form-justified-before-save.png)

![Saved control with its justification](screenshots/tutorial/16-control-form-justified-saved.png)

**Reasons for selection and compensating controls.** Below the justification, tick why the control
is included: risk assessment result, legal or regulatory obligation, contractual requirement,
interested party expectation, good practice (several reasons are possible). As long as nobody has
set them, the reasons deduced from the links are already ticked and marked "From the links": a
linked risk ticks the risk assessment, an obligation of the register attached to that risk ticks
the legal or contractual reason. You can untick them. For a control not applicable or partially
applicable, four fields appear: exclusion category (activity not carried out, handled by a
certified provider, out of the ISMS scope), compensating controls chosen among the other controls
of the same SoA, a free description and the residual risk. Fictitious example: A.7.4 not
applicable, no premises of its own, hosting by a certified provider; compensating control A.5.20
(audit clause in the contract). The justification stays mandatory. This information appears in the
list (filters "Reasons for selection", "Exclusion category", "Residual risk"), in the CSV export and
in the PDF, with a summary by theme (applicable, excluded, implemented, in progress, planned
controls). An SoA already approved is not changed: what you add goes into the next version to
approve.
