### Record a training session and a participant's completion

From **GRC & Conformité > Opérations > Formations**, the list shows the sessions already recorded.

![Training list](screenshots/tutorial/34-training-list.png)

A training is defined by a title, a format (in-person/e-learning/other), a delivery date, a
target audience, whether it's mandatory, and, where relevant, a renewal period in months
(clauses 7.2 "competence" and 7.3 "awareness"). Participants, real GLPI users, are selected on
the same form.

![Training creation form with participants selected](screenshots/tutorial/35-training-form-filled.png)

A training delivered several times has several **sessions**: from the training's **Sessions** tab,
"Ajouter une session" opens a pre-filled form (title, format, maximum score, pass threshold) where
you set the date, the trainer, the location or link and the participants. Each session keeps its
own documents (attendance sheet, slides, quiz); the tab lists the sessions with their pass rate,
and the pass rate of the whole training across sessions.

A session's **Copies de quiz** tab receives each participant's marked quiz copy. A copy is personal
data: only the participant, the session's trainer and the CISO group can read it, and every download
is logged. Participants find their trainings, scores and copies in the **Mes formations** tab of
their preferences, without any right on the plugin.

Once saved, a per-participant tracking table appears below the form: a completion status
(pending/completed/exempted) and a date for each participant.

![Saved training with the participant tracking table](screenshots/tutorial/36-training-saved-participants.png)

Switching a participant's status to "Terminée" (completed) with their completion date, then
saving: that participant's individual tracking is up to date, and the training now counts towards
the completion rate shown on the dashboard (see step {{step:0100-browse-default-isms-dashboard}}).

![Participant's completion status updated](screenshots/tutorial/37-training-completion-recorded.png)
