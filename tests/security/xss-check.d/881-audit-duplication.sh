# shellcheck shell=bash
# Fragment charge par xss-check.sh (memes fonctions et variables).
# Issue #881 : onglet Cycle suivant (titre propose, auditeur de l'organisme, audit d'origine, audits prepares).
check "onglet cycle suivant d'un audit"        "$(tab 'GlpiPlugin%5CGrcmanager%5CAudit' 'GlpiPlugin%5CGrcmanager%5CAuditDuplication$1' "$(xid audit)")"
check "onglet cycle suivant d'un audit prepare" "$(tab 'GlpiPlugin%5CGrcmanager%5CAudit' 'GlpiPlugin%5CGrcmanager%5CAuditDuplication$1' "$(xid audit_next)")"
