### Keep the software bill of materials (SBOM) and the VEX statements of your products (controls A.5.21, A.8.8 and A.8.28)

If you build or develop software (an application, a product with digital elements in the sense of
the Cyber Resilience Act), your customers and the CRA expect its software bill of materials and your
statements about its vulnerabilities. The product is a GLPI application, as in the register of secure
development ({{step:0320-frame-secure-development-releases}}).

1. Have your build produce the bill of materials in CycloneDX or SPDX 2 format, as JSON (for example
   with Syft, Trivy, cdxgen or the CycloneDX plugin for Maven or npm).
2. Open the application in **Assets > Appliances**, tab **SBOM and VEX**. In **Import an SBOM**,
   enter the released version (for example `2.4.0`), choose the file and click **Import**. The file is
   refused if it exceeds 10 MB, is nested too deeply or lists more than 10,000 components: split it by
   product in that case.
3. The card of the version lists the **known vulnerabilities of the components**: those cited by the
   file (CycloneDX, often added by the scanner) and those the vulnerability watch of the fleet already
   knows for the same product and version (name linked to a watched product, or CPE of the
   component). No request is sent outside GLPI. Unfold **Components** for the details: name,
   version, supplier, licenses, purl or CPE.
4. For each vulnerability, choose a VEX status: **Affected**, **Under investigation**, **Fixed** or
   **Not affected**. "Not affected" needs a justification (component not present, vulnerable code
   not present or never executed, out of reach of an attacker, protections already in place) or an
   explanation. You can also import the VEX document of your tool (CycloneDX VEX or OpenVEX) in
   **Import a VEX document**, or add a statement for the whole product in **VEX statements**.
5. A new statement replaces the previous one for the same vulnerability, component and version; the
   most precise one applies (a named component rather than the whole product).
6. On the sheet of controls A.5.21, A.8.8 and A.8.28, the box **Software bills of materials and VEX**
   gives the number of products with an SBOM, the applications of the register without an SBOM and,
   for the latest bill of materials of each product, the vulnerabilities without a statement and
   those that still affect the product.
