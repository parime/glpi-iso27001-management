[🇫🇷 Français](ROLES.md) | [🇬🇧 English](ROLES.en.md)

# Guides by role

Each guide is a few steps: what the person does, where they click and what they get. Paths are
those of the English interface; the plugin menu is called **GRC & Compliance** and has six sections
(Dashboard, Risks, Compliance, Audits and improvement, Operations, Technical security). Within a
section, the other pages open from the tab bar at the top of the page. For a screen-by-screen walk
through with screenshots, see the [tutorial](TUTORIAL.md#-english).

The examples use fictitious data: a company "Example Ltd", its subsidiary "North Branch" and
made-up people (Alice Martin, CISO; Bruno Petit, IT manager; Claire Durand, managing director).

| Role | What they do in GRC Manager | Guide |
|---|---|---|
| Plugin administrator | Installs, grants rights, configures the plugin | [Administrator](#plugin-administrator) |
| CISO, ISMS manager | Keeps the risks and each entity's SoA, prepares the management review | [CISO](#ciso-or-isms-manager) |
| Control or risk owner | Handles what is assigned to them | [Owner](#control-or-risk-owner) |
| Auditor | Runs an audit, requests and reviews evidence | [Auditor](#auditor) |
| Top management | Approves, accepts risks, follows the dashboard | [Top management](#top-management) |

---

## Plugin administrator

Goal: an installed plugin, profiles that see what they need, and settings that fit the
organization. Allow half an hour.

1. **Install and activate**: put the `grcmanager` folder in `plugins/` (see
   [Installation](../README.en.md#installation)), then **Setup > Plugins**, "GLPI GRC Manager" row,
   **Install** then **Enable**. The **GRC & Compliance** menu appears.
2. **Grant rights**: **Administration > Profiles**, open a profile, **GRC Manager** tab. Five
   rights, granted separately:
   - **Security governance (ISMS)**: the general right (SoA, audits, policies, evidence...); its
     **Decide on releases** box (security validation of a change) goes to the CIO and the CISO
     only;
   - **Sensitive registers** (risks, risk indicators, vulnerabilities, network flows): to the CISO
     and risk owners only, since these registers describe the organization's weak points;
   - **Business continuity** (business processes, BIA, security crisis scenarios);
   - **Personal data protection** (GDPR record of processing, breaches): to the DPO;
   - **Security incidents** and their rules, for the response team.

   Typical setup: the CISO has everything; a technician reads the ISMS; top management needs no
   right at all to approve (see [Top management](#top-management)).
3. **Set up entities**: risks, SoA controls and SoA versions belong to a GLPI entity (since version
   2.53.0). After an upgrade, everything is in the root entity: move each subsidiary's risks with a
   mass update ("Entity" column) in **GRC & Compliance > Risks**. See
   [Perimeters](../README.en.md#perimeters-glpi-entities).
4. **Open the configuration**: **Setup > Plugins**, click "GLPI GRC Manager" (or the wrench icon on
   its row). Every setting is on this page, by section:
   - **Compliance frameworks**: enable the ones the organization follows (NIS2, DORA, ISO 27701...);
   - **Risk matrix**: probability x impact labels and thresholds, 4 x 4 or 5 x 5 size (correspondence
     screen before any conversion of the register);
   - **Risk appetite and tolerance**: the "management" group, the only one allowed to accept a risk
     beyond tolerance;
   - **Internal audits**: the CISO group, which reads the restricted documents of audits;
   - **ISMS consistency checks**: active rules and thresholds (tolerance in days, minimum length of
     a justification...);
   - **Calendar subscription links**: lifetime of the dedicated token (365 days by default);
   - **Approval of security policies by management**: off by default, approvers and approval rule;
   - **Security incidents**, **CVE enrichment (NVD)**, **Data breach monitoring**: modules and
     outgoing calls, each off until it is set up.
5. **Check**: log in with a delegated profile limited to "North Branch" and open
   **GRC & Compliance > Risks**: only the subsidiary's risks are listed.

Options: with the [Docker inventory](https://github.com/parime/glpi-docker-inventory) plugin
enabled, its data becomes evidence (A.5.9, A.8.8, A.8.9, A.8.32) with no extra setting; the same
goes for the assetsign and Wazuh plugins.

## CISO or ISMS manager

Goal: an up-to-date risk register, an approved SoA for each entity that needs one, and a management
review prepared with figures.

**Risks**

1. **GRC & Compliance > Risks**, **Add** button: category, probability, impact (the level is
   computed), owner, review date. Example: "Administrator leaves without handover", category People,
   owner Bruno Petit.
2. To get started quickly: **Catalog of typical risks** (above the list) to import common risks, or
   **Import / export CSV** to take over a register kept in a spreadsheet (dry run before import).
3. On the risk form, choose the treatment decision (accept, mitigate, transfer, avoid) and fill in
   the **Risk treatment plan**: one action, one owner, one due date.
4. **Risk heatmap** (above the list) to present the matrix; **Risk appetite and tolerance** tab of
   the section to see the risks above the threshold.

**SoA by entity**

5. **GRC & Compliance > Compliance** ("Annex A controls" page): for each of the 93 controls, state
   whether it applies, why and how far it is implemented. The list header shows the SoA followed
   ("Own SoA" or "Inherited SoA").
6. In a subsidiary with its own controls: switch the active entity to "North Branch", then
   **Create the SoA of this entity**. The copy keeps applicability and justifications; each control
   is still to be reviewed and implemented in the subsidiary.
7. **Management approval** button on the same list: **Freeze and send for approval** to the chosen
   approvers. The frozen version is a dated PDF, with its fingerprint, kept in GLPI documents. Any
   later change to the SoA requires a new version.

**Before an audit and before the management review**

8. **GRC & Compliance > Dashboard**, **ISMS consistency checks** tab: the gaps in the ISMS (risk
   without an owner, control implemented without evidence, expired evidence, policy not reviewed...)
   by severity, each with a link to the record to fix. Nothing is fixed automatically. Export as CSV
   or PDF to follow the audit preparation.
9. Same section: **Maturity score** and **Assessment by framework** give the figures for the review;
   in a parent entity, the **Perimeters** tab of the dashboard compares subsidiaries (SoA followed,
   implementation rate, latest approved version, open risks).
   **Assessment by framework** measures each framework on its own measures: the SoA for ISO 27001,
   the requirements and their self-assessment for a custom framework, the register points for
   NIS2 Italy. For NIS2 (EU), ANSSI, NIST CSF, CIS Controls, the AI Act and the other built-in
   catalogs, the plugin tracks no status of their own: each requirement takes the status of the
   Annex A controls mapped to it (indicative mapping), only while ISO 27001 is enabled; otherwise
   the screen states that the framework is not assessed. Pick the framework to detail in the list:
   the choice is kept for the session.
10. **GRC & Compliance > Audits and improvement**, **Management reviews** tab, **Add** button: date,
    participants, topics covered, ISMS objectives reviewed, decisions and actions. The standard
    requires this review at planned intervals (ISO 27001, 9.3); at least once a year is common
    practice.

Evidence from other plugins shows up by itself on the relevant controls: for instance, with Docker
inventory, the **Docker inventory evidence** field of control A.5.9 lists the inventoried services
and the date of each connection's last successful import.

## Control or risk owner

Goal: know what is expected of you and do it, without browsing the whole ISMS.

1. Menu **GRC & Compliance > My GRC space**. The number in brackets counts what is waiting for you.
   The entry also appears for someone with no right on the plugin as soon as they have something to
   do.
2. The page lists, sorted by due date, overdue items in red: risks to review, treatment actions,
   control effectiveness tests, evidence to renew, nonconformities, periodic and access reviews,
   policies to read or review, trainings, audit evidence to provide... Only items you are allowed to
   see are listed.
3. Open each line and act: for example, on an overdue treatment action, update its status and
   completion date; on evidence to renew, attach the new document.
4. To see your deadlines in your calendar: **GRC & Compliance > Dashboard**, **Compliance calendar**
   tab, **Subscribe** button, then **Create a subscription link**. Copy the link right away: it is
   shown only once. The link carries a token dedicated to the calendar (it opens neither GLPI nor its
   API), expires after the lifetime set by the administrator and can be regenerated or revoked from
   the same window. Older links based on the personal GLPI token no longer work: create a new one.

Shortcut: on **GRC & Compliance > Risks**, the **My risks** button filters the list on the risks
you own.

## Auditor

Two situations: the internal auditor who runs the audit in GLPI, and the auditor (internal or
external) who only consults.

**Running an internal audit**

1. **GRC & Compliance > Audits and improvement** ("Internal audits" page), **Add** button: scope,
   dates, auditors. **Team** tab for the audit team; its working documents stay restricted to the
   team and the CISO group.
2. **Evidence requests** tab of the audit: add each expected item (control concerned, assignee,
   due date) or **Import the auditor's list (CSV)**.
3. The assignee uploads their documents from **My evidence requests**, reachable from My GRC space,
   even without any right on the plugin. They are reminded by email before the due date, then every
   week when late.
4. On the same tab, **Accept** each submitted piece of evidence or **Ask to complete** with the
   reason. Progress (accepted, to review, to provide) is shown at the top.
5. Record the **Findings**; each nonconformity opens a record in **Non-conformities** with its
   corrective actions. The PDF report is archived when the audit is closed.

**Giving an auditor read access**

6. **Evidence requests** tab of the audit, **Auditor access (read only)** section: **Give read
   access to this audit** to the auditor's account, with an end date.
7. The auditor opens their **Auditor space**: from My GRC space ("Audit to consult as auditor") or,
   in the simplified interface, through the **GRC Manager** menu entry. They see the controls, the
   progress, the submitted evidence and the findings; every download is recorded (**Document access**
   tab of the audit). Access ends on the set date and can be revoked at any time.

## Top management

Goal: decide in a few clicks, without having to know the plugin.

1. **Approve the SoA**: an email announces the request. Open **My GRC space** ("SoA approval"
   line), read the frozen version (PDF), then **I approve this version** or **Reject** with a
   comment. No right on the plugin is needed; the requester cannot approve their own request.
2. **Approve a policy** (when policy approval is enabled): same path, "Policy to approve" line. A
   rejection needs a comment, so that the author can correct the policy.
3. **Accept a risk beyond tolerance**: only members of the "management" group set by the
   administrator can do so. The acceptance, dated and named, is marked "Management" on the risk
   form.
4. **Follow up**: **GRC & Compliance > Dashboard**, **ISMS overview** view (risks, controls,
   incidents, deadlines); **Maturity score** tab for a 0 to 100 score and its monthly trend;
   **Perimeters** view to compare subsidiaries. These pages require read access to the ISMS.
5. **Management review**: the CISO prepares it; top management validates the decisions and actions
   recorded in **Audits and improvement > Management reviews**.
