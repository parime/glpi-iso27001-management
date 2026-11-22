# shellcheck shell=bash
# Issue #1047 (signale par #1079) : cas des profils sans droit et lecteur ecrits dans la partie commune
# d'access-matrix.sh, qui ne tourne que pour le gestionnaire limite et le super-admin : ils n'y
# etaient jamais executes. Deplaces ici, ils tournent enfin : aucune action de modification n'est
# permise sans droit ni en lecture seule.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
case $ROLE in
  none|reader)
    # Issue #612 : matrice des responsabilites, activites par defaut et archivage.
    for action in add_defaults=1 archive=1; do
      expect "front/responsibilityactivity.php : ${action%%=*}" 403 \
        "$(code -d "$action&_glpi_csrf_token=$(csrf)" "$P/responsibilityactivity.php")"
    done
    # Issue #866 : recalcul des suggestions, droit de modification sur la campagne exige.
    expect "recalculer les suggestions de la campagne A en lecture seule" 403 \
      "$(code -d "refresh_suggestions=1&id=$(id campaign_A)&_glpi_csrf_token=$(csrf)" "$P/accessreview.form.php")"
    # Issue #774 : fonctions du SMSI par defaut.
    expect "front/ismsfunction.php : fonctions par defaut" 403 \
      "$(code -d "add_defaults=1&_glpi_csrf_token=$(csrf)" "$P/ismsfunction.php")"
    # Issue #607 : campagnes de prise de connaissance des politiques.
    for action in launch=1 remind=1 close=1 add_audience=1 save_days=1; do
      expect "front/policyacknowledgement.form.php : ${action%%=*}" 403 \
        "$(code -d "$action&policies_id=$(id policy_ack)&campaigns_id=$(id ack_campaign)&itemtype=Group&items_id=1&acknowledgement_days=3&_glpi_csrf_token=$(csrf)" "$P/policyacknowledgement.form.php")"
    done
    # Issue #611 : approbation des politiques.
    for action in submit_approval=1 save_approvers=1 withdraw=1 new_version_submit=1; do
      expect "front/policyapproval.php : ${action%%=*}" 403 \
        "$(code -d "$action&policies_id=$(id policy_ack)&versions_id=1&new_version=9.9&approver_users[]=2&_glpi_csrf_token=$(csrf)" "$P/policyapproval.php")"
    done
    # Issue #538 : demandes de preuves d'audit (gestion reservee a qui modifie l'audit).
    for action in add=1 accept=1 ask_completion=1 update=1 purge=1 remind=1 grant_access=1 revoke_access=1; do
      expect "front/auditevidencerequest.form.php : ${action%%=*}" 403 \
        "$(code -d "$action&plugin_grcmanager_audits_id=$(id audit)&id=$(id evreq_submitted)&reference=securite&review_comment=x&users_id=2&date_end=$(date +%Y-%m-%d)&accesses_id=1&_glpi_csrf_token=$(csrf)" "$P/auditevidencerequest.form.php")"
    done
    expect "front/auditevidencerequest.form.php : import CSV" 403 \
      "$(code -F import_csv=1 -F "plugin_grcmanager_audits_id=$(id audit)" -F "csv_file=@$EVIDENCE_PDF;filename=liste.csv" -F "_glpi_csrf_token=$(csrf)" "$P/auditevidencerequest.form.php")"
    # Issue #540 : registre d'informations DORA.
    for action in save_settings=1 save_provider=1 save_function=1 include=1 exclude=1 save_arrangement=1 add_function=1 \
      remove_function=1 add_subcontractor=1 remove_subcontractor=1; do
      expect "front/doraregister.form.php : ${action%%=*}" 403 \
        "$(code -d "$action&contracts_id=$(id contract_A)&suppliers_id=$(id supplier)&name=securite&reference=securite&_glpi_csrf_token=$(csrf)" "$P/doraregister.form.php")"
    done
    ;;
esac
