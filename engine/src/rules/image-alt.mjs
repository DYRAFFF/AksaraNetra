/**
 * Resolver image-alt.
 *
 * Prinsip inti: engine tidak pernah mengarang deskripsi visual.
 *
 * Sinyal yang dibaca:
 * 1. Gambar di dalam kontrol interaktif berteks (a/button) -> dekoratif, alt=""
 * 2. Dimensi mini (<= 16px) atau class penanda dekorasi -> dekoratif, alt=""
 * 3. Figcaption pada elemen figure pembungkus -> informatif dari figcaption
 * 4. Atribut data-caption, data-title, data-alt -> informatif dari data attribute
 * 5. Atribut title pada img yang bukan nama berkas -> informatif dari title
 * 6. Pemetaan logo resmi pada site config (knownImageAlts)
 * 7. Gambar tanpa sinyal teks DOM -> skip (confidence 0, tidak ditebak)
 */
export async function resolveImageAltCandidates(
  page,
  selectors = [],
  config = {},
) {
  const knownImageAlts = config.knownImageAlts || {};

  return page.evaluate(
    ({ selectors, knownImageAlts }) => {
      const clean = (value = "") =>
        String(value).replace(/[_-]+/g, " ").replace(/\s+/g, " ").trim();

      const isFileName = (text = "") =>
        /\.(jpe?g|png|webp|svg|gif|bmp|avif)$/i.test(text.trim());

      const skip = (selector, source, reason) => ({
        rule: "image-alt",
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

        if (element.tagName.toLowerCase() !== "img") {
          return skip(
            selector,
            "not-an-img",
            "Target element is not an HTML <img>",
          );
        }

        // Jika elemen sudah memiliki alt (bahkan alt=""), jangan ditimpa
        if (element.hasAttribute("alt")) {
          return skip(
            selector,
            "existing-name",
            "Element already has an alt attribute and will not be overwritten",
          );
        }

        const src = element.getAttribute("src") || "";

        // Sinyal 1: Pemetaan konfigurasi situs (knownImageAlts)
        const configuredAlt =
          knownImageAlts[selector] ||
          knownImageAlts[src] ||
          knownImageAlts[src.split("/").pop()];
        if (configuredAlt) {
          return {
            rule: "image-alt",
            selector,
            confidence: 0.95,
            source: "known-image-map",
            reason: "Image is mapped in the configured site image map",
            patches: [{ attribute: "alt", value: configuredAlt }],
          };
        }

        // Sinyal 2: Gambar dekoratif dalam elemen interaktif berteks
        const parentInteractive = element.closest("a[href], button");
        if (parentInteractive) {
          const clone = parentInteractive.cloneNode(true);
          for (const img of clone.querySelectorAll("img")) img.remove();
          const companionText = clean(clone.textContent || "");

          if (companionText.length >= 2) {
            return {
              rule: "image-alt",
              selector,
              confidence: 0.95,
              source: "parent-interactive-has-text",
              reason:
                "Parent interactive element already provides text; image is decorative to prevent speech duplication",
              patches: [{ attribute: "alt", value: "" }],
            };
          }
        }

        // Sinyal 3: Gambar dekoratif mini atau memiliki utility class dekorasi
        const widthAttr = parseInt(element.getAttribute("width") || "0", 10);
        const heightAttr = parseInt(element.getAttribute("height") || "0", 10);
        const rect = element.getBoundingClientRect?.() || { width: 0, height: 0 };
        const isTiny =
          (widthAttr > 0 && widthAttr <= 16 && heightAttr > 0 && heightAttr <= 16) ||
          (rect.width > 0 && rect.width <= 16 && rect.height > 0 && rect.height <= 16);

        const className = String(element.getAttribute("class") || "").toLowerCase();
        const isDecorClass =
          /\b(spacer|divider|separator|bullet|chevron|decor|decorative)\b/i.test(
            className,
          );

        if (isTiny || isDecorClass) {
          return {
            rule: "image-alt",
            selector,
            confidence: 0.9,
            source: "decorative-dimension-or-class",
            reason:
              "Image matches decorative dimensions or utility classes; marked as decorative",
            patches: [{ attribute: "alt", value: "" }],
          };
        }

        // Sinyal 4: Elemen figure dengan figcaption
        const figure = element.closest("figure");
        if (figure) {
          const figcaption = figure.querySelector("figcaption");
          if (figcaption) {
            const captionText = clean(figcaption.textContent || "");
            if (captionText && captionText.length <= 120 && !isFileName(captionText)) {
              return {
                rule: "image-alt",
                selector,
                confidence: 0.9,
                source: "figure-figcaption",
                reason: "Accompanying figcaption describes the image context",
                patches: [{ attribute: "alt", value: captionText }],
              };
            }
          }
        }

        // Sinyal 5: Atribut data eksplisit (data-caption, data-title, data-alt)
        const dataCaption = clean(
          element.getAttribute("data-caption") ||
            element.getAttribute("data-title") ||
            element.getAttribute("data-alt") ||
            "",
        );
        if (dataCaption && dataCaption.length >= 3 && !isFileName(dataCaption)) {
          return {
            rule: "image-alt",
            selector,
            confidence: 0.9,
            source: "data-caption-attribute",
            reason: "Explicit data attribute specifies image description",
            patches: [{ attribute: "alt", value: dataCaption }],
          };
        }

        // Sinyal 6: Atribut title
        const titleAttr = clean(element.getAttribute("title") || "");
        if (titleAttr && titleAttr.length >= 3 && !isFileName(titleAttr)) {
          return {
            rule: "image-alt",
            selector,
            confidence: 0.85,
            source: "img-title-attribute",
            reason: "Title attribute provides descriptive purpose",
            patches: [{ attribute: "alt", value: titleAttr }],
          };
        }

        // Tanpa sinyal yang meyakinkan: skip dan serahkan ke review manusia
        return skip(
          selector,
          "unknown-image",
          "No reliable caption or decorative cue found in DOM; human review required",
        );
      });
    },
    { selectors, knownImageAlts },
  );
}
