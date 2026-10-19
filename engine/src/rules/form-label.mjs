/**
 * Resolver form-label (axe rule: "label").
 *
 * Prinsip: engine memberikan label aksesibel pada kontrol input publik tanpa
 * melanggar penonaktifan formulir dan tanpa mengarang fungsi input.
 *
 * Sinyal yang dibaca:
 * 1. Pemetaan konfigurasi situs (knownFormLabels)
 * 2. Teks elemen <label> pembungkus atau tetangga terdekat
 * 3. Tombol submit pendamping (misal tombol "Cari" di samping input)
 * 4. Atribut placeholder yang deskriptif
 * 5. Token id atau name dengan pemecahan camelCase
 * 6. Tanpa sinyal -> skip (confidence 0, serahkan ke review manusia)
 */
export async function resolveFormLabelCandidates(
  page,
  selectors = [],
  config = {},
) {
  const knownFormLabels = config.knownFormLabels || {};

  return page.evaluate(
    ({ selectors, knownFormLabels }) => {
      const clean = (value = "") =>
        String(value).replace(/[_-]+/g, " ").replace(/\s+/g, " ").trim();

      const splitCamelCase = (raw = "") =>
        clean(
          raw
            .replace(/([\p{Ll}\p{N}])([\p{Lu}])/gu, "$1 $2")
            .replace(/[^\p{L}\p{M}\p{N}\s]/gu, " "),
        );

      const skip = (selector, source, reason) => ({
        rule: "label",
        selector,
        confidence: 0,
        source,
        reason,
        patches: [],
      });

      return selectors.map((selector) => {
        let element;
        try {
          const matches = document.querySelectorAll(selector);
          if (matches.length !== 1) {
            return skip(
              selector,
              "selector-not-unique",
              `Selector matched ${matches.length} elements`,
            );
          }
          element = matches[0];
        } catch {
          return skip(
            selector,
            "invalid-selector",
            "Selector could not be evaluated",
          );
        }

        const tag = element.tagName.toLowerCase();
        if (!["input", "select", "textarea"].includes(tag)) {
          return skip(
            selector,
            "not-a-form-control",
            "Target element is not a form input control",
          );
        }

        // Cek jika elemen sudah memiliki nama aksesibel
        const existingAria =
          clean(element.getAttribute("aria-label") || "") ||
          clean(element.getAttribute("aria-labelledby") || "") ||
          clean(element.getAttribute("title") || "");

        const id = element.getAttribute("id");
        const hasAssociatedLabel =
          id && document.querySelector(`label[for="${CSS.escape(id)}"]`);

        if (existingAria || hasAssociatedLabel) {
          return skip(
            selector,
            "existing-name",
            "Element already has an associated label or aria-label",
          );
        }

        const name = element.getAttribute("name") || "";

        // Sinyal 1: Pemetaan konfigurasi situs
        const configured =
          knownFormLabels[selector] ||
          (id && knownFormLabels[id]) ||
          (name && knownFormLabels[name]);
        if (configured) {
          return {
            rule: "label",
            selector,
            confidence: 0.95,
            source: "known-form-map",
            reason: "Form input is mapped in the configured site form map",
            patches: [{ attribute: "aria-label", value: configured }],
          };
        }

        // Sinyal 2: Elemen <label> pembungkus atau tetangga langsung
        const wrappingLabel = element.closest("label");
        let labelText = "";
        if (wrappingLabel) {
          const clone = wrappingLabel.cloneNode(true);
          for (const sub of clone.querySelectorAll("input, select, textarea")) {
            sub.remove();
          }
          labelText = clean(clone.textContent || "");
        } else if (
          element.previousElementSibling?.tagName.toLowerCase() === "label"
        ) {
          labelText = clean(element.previousElementSibling.textContent || "");
        }

        if (labelText && labelText.length >= 2) {
          return {
            rule: "label",
            selector,
            confidence: 0.95,
            source: "adjacent-label-text",
            reason: "Associated label text describes the input control",
            patches: [{ attribute: "aria-label", value: labelText }],
          };
        }

        // Sinyal 3: Tombol aksi/submit pendamping dalam kontainer pencarian
        const container = element.closest("form, .search-group, .search-box, div");
        if (container) {
          const companionButton = container.querySelector(
            "button, input[type='submit']",
          );
          if (companionButton && companionButton !== element) {
            const btnText = clean(
              companionButton.innerText ||
                companionButton.getAttribute("aria-label") ||
                companionButton.getAttribute("title") ||
                "",
            );
            const lowerBtn = btnText.toLowerCase();
            if (
              lowerBtn.includes("cari") ||
              lowerBtn.includes("search") ||
              lowerBtn.includes("temukan")
            ) {
              return {
                rule: "label",
                selector,
                confidence: 0.9,
                source: "companion-submit-button",
                reason:
                  "Companion search button indicates this input is a search field",
                patches: [{ attribute: "aria-label", value: "Cari informasi" }],
              };
            }
          }
        }

        // Sinyal 4: Atribut placeholder deskriptif
        const placeholder = clean(element.getAttribute("placeholder") || "");
        if (
          placeholder &&
          placeholder.length >= 3 &&
          !/^[\s\.\-_]+$/.test(placeholder)
        ) {
          return {
            rule: "label",
            selector,
            confidence: 0.85,
            source: "input-placeholder",
            reason: "Descriptive placeholder specifies input purpose",
            patches: [{ attribute: "aria-label", value: placeholder }],
          };
        }

        // Sinyal 5: Token dari ID atau Name camelCase
        const idText = id ? splitCamelCase(id) : "";
        const nameText = name ? splitCamelCase(name) : "";
        const splitName =
          idText && nameText && idText.toLowerCase() === nameText.toLowerCase()
            ? idText
            : idText || nameText;

        if (splitName) {
          if (splitName.length >= 3 && !/^\d+$/.test(splitName)) {
            // Ubah huruf pertama tiap kata jadi kapital
            const formatted = splitName.replace(/\b\w/g, (c) => c.toUpperCase());
            return {
              rule: "label",
              selector,
              confidence: 0.8,
              source: "input-identifier",
              reason: "Input identifier tokens describe the form field",
              patches: [{ attribute: "aria-label", value: formatted }],
            };
          }
        }

        return skip(
          selector,
          "unknown-input",
          "No reliable label, placeholder, or identifier found in DOM; human review required",
        );
      });
    },
    { selectors, knownFormLabels },
  );
}
