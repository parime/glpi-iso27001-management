### Configure the probability x impact matrix

From **Configuration > Plugins**, the wrench icon on the plugin's row opens the risk matrix
configuration screen: each probability/impact combination can be mapped to the risk level of
your choice (low/medium/high/critical), with a live-updating color preview. The default values
reproduce the register's behavior from before this screen existed: no existing risk changes
level until the grid is edited.

![Probability x impact risk matrix](screenshots/tutorial/09-config-matrix.png)

**Matrix size (4 x 4 or 5 x 5).** The matrix is 4 x 4 by default. Below the grid, "Matrix size"
switches to 5 x 5 (very rare, rare, possible, probable, almost certain; negligible, low, medium,
high, critical), or back to 4 x 4. The change takes two steps, and nothing is modified before the
confirmation:

1. **Correspondence of the values**: for each probability and impact in use today, the number of
   risks concerned and the proposed new value (the one that changes the fewest risk levels), which
   can be changed;
2. **Check the effect and apply**: number of risks updated, levels going up or down, grid of the
   new size; a checkbox confirms the check.

Risks and supplier risks are then converted; the history of each risk keeps the old and new value,
with a message explaining the conversion. The risk level stays on four values (low, medium, high,
critical): appetite, indicators and consistency checks do not change. This change requires the
right to update the plugin configuration and the sensitive registers. The scales of EBIOS RM
studies and DPIAs are not affected.
