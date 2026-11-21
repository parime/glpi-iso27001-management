### Run an access review campaign

From **GRC & Conformité > Opérations > Revues des accès** (access reviews), a campaign lists the accounts to
review and assigns them to their reviewers (manager, group owner or campaign owner). Each reviewer
handles their own lines from **Mes revues d'accès**: keep, change or remove. A right to remove
becomes a ticket; the plugin never removes a right itself. At closure, a PDF report is attached to
the campaign and to the related Annex A control.

![Access review campaigns](screenshots/tutorial/43-access-review-list.png)

**Review pre-filled by rules.** At launch, each account comes with a suggestion and its reason,
computed from what GLPI already knows, without AI or any external service:

- **Remove** (red badge): account deleted from the directory, account end date passed, or a
  departure recorded in assetsign whose date has passed;
- **Examine** (orange badge): departure or end date within the next days, no login for N days or
  never used, groups changed without a profile review, privileged profile (the reason says so when
  no MFA is known, according to the **MFA coverage** page);
- **Keep** (green badge): no warning sign.

1. On the campaign form, set the **Suggestion rules** box: inactivity period, horizon of an upcoming
   departure, privileged profiles, MFA, group changes.
2. Launch the campaign. The **Accounts to review** tab counts the suggestions and adds the
   **Suggestion and reason** column; the button the rules suggest carries a light bulb.
3. The reviewer always decides. Keeping an account the rules suggest removing requires a comment
   (for example an extended contract); a decision opposite to the suggestion is flagged in the tab
   and in the PDF report.
4. During the campaign, **Update the suggestions** recomputes the accounts still to review; accounts
   already decided keep the suggestion their reviewer saw.
