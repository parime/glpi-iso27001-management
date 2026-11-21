### Create a risk in the supplier/third-party register

From **GRC & Conformité > Risques > Risques fournisseurs**, the list shows the supplier risks already recorded on
this instance.

![Supplier risk register](screenshots/tutorial/28-supplierrisk-list.png)

A new supplier risk reuses exactly the same category/probability/impact fields as the generic
register (see step {{step:0020-create-risk-generic-register}}), plus a mandatory real GLPI supplier (the core `Supplier` itemtype): this
register covers dependency on a third party (clause 6.1.2/8.2, Annex A.5.19-A.5.22).

![Supplier risk creation form](screenshots/tutorial/29-supplierrisk-form-filled.png)

As with the generic register, the risk level and the numeric score are computed automatically on
save, using the same probability x impact matrix configured in step {{step:0030-configure-probability-x-impact-matrix}}.

![Saved supplier risk with computed level](screenshots/tutorial/30-supplierrisk-created-level.png)

The same treatment decision cycle (accept/mitigate/transfer/avoid) and justification as the
generic register apply next.

![Treatment decision and justification before saving](screenshots/tutorial/31-supplierrisk-treatment-filled.png)

![Saved treatment decision](screenshots/tutorial/32-supplierrisk-treatment-saved.png)

The list then shows every supplier risk with its linked GLPI supplier and the same colored
badges as the generic register.

![Supplier risk register with colored badges](screenshots/tutorial/33-supplierrisk-list-with-badges.png)
