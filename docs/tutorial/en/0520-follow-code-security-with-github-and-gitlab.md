### Follow the code security of the applications with GitHub and GitLab (controls A.8.28 and A.8.29)

GitHub and GitLab analyse the code of your applications: vulnerable third-party libraries, passwords
or keys written in the code, weaknesses of the code itself. The plugin reads these alerts **in read
only**: nothing is changed in GitHub or GitLab, no alert is closed, and the value of an exposed
secret is never requested.

1. For each application developed in-house, open its **Secure development** tab and fill in the
   **code repository** (for example `https://github.com/example/portal` or
   `git@gitlab.example.test:team/portal.git`).
2. Prepare a read-only token:
   - GitHub: a fine-grained personal access token, limited to the repositories concerned, with the
     read permissions "Dependabot alerts", "Secret scanning alerts" and "Code scanning alerts";
   - GitLab: a project or group access token with the `read_api` scope (the vulnerability report
     requires GitLab Ultimate).
3. Open **Controls > Code security**, frame **Connection to GitHub and GitLab**: paste the token
   (and, if needed, the address of your GitHub Enterprise or GitLab server), then **Save** and
   **Read now**.
4. The **Applications and their repositories** frame shows, for each application, the number of
   alerts per family (exposed secrets, vulnerable dependencies, code analysis) and the **Not
   enabled** badge when an analysis does not run on the repository: this is already a gap for
   control A.8.29.
5. The **Open alerts** frame ranks the alerts: first the exposed secrets **not revoked** (to revoke
   at the provider, then to remove from the code and its history), then the alerts **past their
   deadline**, by severity and age. The deadlines are those of the vulnerability remediation policy
   (plugin configuration); an exposed secret takes the shortest deadline.
6. The **Code security** tab of each application sums up its alerts; the sheet of controls A.8.28
   and A.8.29 shows the summary as evidence. To be warned, create a risk indicator "Exposed secrets
   not revoked in the code" or "Code security alerts past their deadline".
7. For a daily reading, tick **Read automatically every day**: the automatic action
   `codesecurity` is disabled after installation.
