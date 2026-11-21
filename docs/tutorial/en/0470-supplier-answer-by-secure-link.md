### Have a supplier answer by secure link

If your suppliers can reach your GLPI, they can answer the questionnaire online, without a GLPI
account (otherwise, see {{step:0460-supplier-answer-by-file}}).

1. An administrator of the plugin enables **Supplier answer by secure link** in the plugin
   settings. The feature is disabled by default.
2. In the **Secure link** tab of the evaluation, choose how long the link is valid and how many
   sendings it allows, then click **Create a link**. Copy the link shown: it is displayed only once,
   GLPI keeps only a fingerprint of it.
3. Send it to the supplier. Its page shows only the questions of this evaluation; it chooses its
   answers, adds comments and its documents, then clicks **Send my answers**.
4. The answers received appear under **Answers to review**. Click **Review the answers**: the
   preview compares each answer with the current one. Save them, or discard them; nothing counts in
   the evaluation before.
5. A link can be revoked at any time (**Revoke** button). The security audit log keeps the
   creation, each opening, each sending and each revocation.
