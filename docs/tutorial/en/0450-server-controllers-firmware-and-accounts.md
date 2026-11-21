### Follow the firmware of the management controllers and review their accounts (controls A.8.8 and A.8.2)

Once the controllers are read ({{step:0440-review-server-management-controllers}}), their
firmware and the BIOS of the server are followed like the other firmwares of the fleet, and their
local accounts enter the access review.

1. On the page **Server management controllers**, the column **Firmware and BIOS** shows a badge for
   each one: **Not watched** while its name is linked to no product, otherwise the number of known
   vulnerabilities (red if one is a priority) and, if the end of support check is enabled, its state
   (supported, end of support soon, no longer supported).
2. To watch a firmware, open **Fleet vulnerabilities**: its name (for example `HPE iLO 5` or
   `HPE ProLiant DL380 Gen10 BIOS`) appears among the software to link, like a firmware of GLPI.
   Link it once to its product; the scheduled watch then looks for the vulnerabilities of each
   version read.
3. For the end of support, link the same name to an endoflife.date product in the configuration of
   the end of support check, if a product exists for this hardware.
4. The gaps **Firmware with a priority vulnerability**, **Firmware with known vulnerabilities**,
   **Firmware no longer supported** and **Firmware support ending soon** join the other gaps of the
   controller and count in the indicator of serious gaps.
5. Launch an access review campaign: the accounts read on the controllers of the same entities are
   copied into the tab **Server controller accounts** of the campaign, with a suggestion. A factory
   account still enabled or a disabled account is proposed for removal, an administrator or locked
   account is to examine.
6. For each account, click **Access approved** or **To remove**, with a comment; keeping an account
   proposed for removal needs a justification. Then delete the account on the controller itself: the
   plugin changes nothing on the servers, the campaign keeps the decision. For a campaign launched
   before the controllers were read, the button **Add the accounts of the controllers** of the tab
   copies them once.
