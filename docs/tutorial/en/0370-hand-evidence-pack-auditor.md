### Hand the evidence pack to the auditor (clauses 7.5.3 and 9.2)

Before the visit, the auditor often asks for all the evidence of the audited perimeter. The
**Compliance > Evidence pack** page prepares it as one ZIP archive.

1. Choose the **perimeter** (an entity) and click **Show the contents**: number of pieces included,
   expired, without a file or over the size limit, applicable controls without evidence, and the
   list of expired evidence.
2. Renew expired evidence (**Evidence validity** tab of the control) and attach what is missing.
3. Click **Generate the evidence pack**, then download it from **Archived evidence packs**: the
   archive holds a PDF index (control by control, requirement by requirement, with the date, state
   and SHA-256 fingerprint of each piece), a `SHA256SUMS.txt` file and the pieces, each stored once
   in the `evidence` folder.

Only the pieces you can read enter the archive; expired evidence is listed in the index but not
included. The size limit is set on the page (200 MB by default). The archive is kept as a
restricted document: you, and anyone who can read every piece it contains, can download it.
Nothing is changed in the registers.
