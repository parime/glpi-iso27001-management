### Check the disk encryption of the fleet (controls A.8.24 and A.8.1)

For each volume of a computer, the GLPI inventory agent reports the encryption state, the tool
(BitLocker, FileVault, LUKS, VeraCrypt...) and the algorithm. The plugin reads this data; it changes
nothing in GLPI and never reads nor stores any recovery key. You need the plugin right and the
native right to read computers.

1. Open Technical security > **Disk encryption**. The "What is this page for?" box recalls what is
   expected.
2. In **Which computers must be encrypted?**, choose the computers concerned: all of them (default),
   laptops and tablets only (computer type reported by the agent: Laptop, Notebook, Tablet...), or
   the selected types. You can add any computer classified with a medium or high confidentiality
   (C/I/A classification, tab of the computer form). This requires the right to update the plugin.
3. The counters give the rate of computers concerned that are fully encrypted, the rate of laptops,
   the weak (DES, RC4...) and older (BitLocker with diffuser, CBC mode) algorithms, then the number
   of computers fully encrypted, partially encrypted, not encrypted or of unknown state (no volume
   inventoried: no agent, or an agent too old).
4. The tables by entity, type and location show where to focus.
5. The list starts with the **gaps**: a computer concerned with a volume that is not encrypted, or
   encrypted with a weak algorithm. For each one: unencrypted volumes, tool, algorithm and date of
   the last inventory. Encrypt the computer, then wait for the next inventory.
6. **Recovery key backed up (declared)** column: tick the box and enter the date when the key is
   backed up (directory, team vault). Only this declaration is kept.
7. On controls A.8.24 and A.8.1, the "Disk encryption" box shows the rate, the date of the
   computation and the number of gaps. In the tests tab of the control, the technical check
   "Computers with an unencrypted disk" (A.8.24) or "Laptops with an unencrypted disk" (A.8.1) is
   recorded as an effectiveness test. The dashboard offers the card "Computers that must be
   encrypted and are (%)" and risk indicators the source "Laptops with an unencrypted disk (%)".
