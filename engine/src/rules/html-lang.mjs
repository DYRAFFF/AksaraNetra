/**
 * Resolver html-has-lang & html-lang-valid.
 *
 * Prinsip: setiap dokumen web wajib mendeklarasikan bahasa utama agar
 * pembaca layar (NVDA, JAWS, TalkBack) melafalkan teks dengan dialek dan fonem
 * yang tepat.
 *
 * Sinyal yang dibaca:
 * 1. Bahasa terkonfigurasi di site config (config.lang atau config.defaultLang)
 * 2. Tag meta http-equiv="content-language"
 * 3. Domain TLD (.id / .go.id mengindikasikan bahasa Indonesia "id")
 * 4. Fallback default ke bahasa Indonesia ("id")
 */
export async function resolveHtmlLangCandidates(
  page,
  selectors = [],
  config = {},
) {
  const configuredLang = config.lang || config.defaultLang || "";

  return page.evaluate(
    ({ selectors, configuredLang }) => {
      const clean = (value = "") => String(value || "").trim().toLowerCase();

      const BCP47_LANG_REGEX = /^[a-z]{2,3}(-[a-z0-9]+)*$/i;

      const skip = (selector, source, reason) => ({
        rule: "html-has-lang",
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

        if (element.tagName.toLowerCase() !== "html") {
          return skip(
            selector,
            "not-html-element",
            "Target element is not <html>",
          );
        }

        const currentLang = clean(element.getAttribute("lang") || "");
        if (currentLang && BCP47_LANG_REGEX.test(currentLang)) {
          return skip(
            selector,
            "existing-name",
            "Document element already has a valid lang attribute",
          );
        }

        // Sinyal 1: Konfigurasi situs
        if (configuredLang && BCP47_LANG_REGEX.test(configuredLang)) {
          return {
            rule: "html-has-lang",
            selector,
            confidence: 0.95,
            source: "site-config-lang",
            reason: `Configured site language "${configuredLang}" declared on root <html>`,
            patches: [{ attribute: "lang", value: configuredLang }],
          };
        }

        // Sinyal 2: Meta content-language
        const metaContentLang = document.querySelector(
          "meta[http-equiv='content-language' i]",
        );
        if (metaContentLang) {
          const rawMeta = clean(metaContentLang.getAttribute("content") || "");
          const langCode = rawMeta.split(",")[0].trim();
          if (BCP47_LANG_REGEX.test(langCode)) {
            return {
              rule: "html-has-lang",
              selector,
              confidence: 0.9,
              source: "meta-content-language",
              reason: `Language "${langCode}" extracted from meta content-language header`,
              patches: [{ attribute: "lang", value: langCode }],
            };
          }
        }

        // Sinyal 3: Default TLD .id / .go.id
        const host = window.location.hostname.toLowerCase();
        const isIndonesianTLD = host.endsWith(".id") || host.endsWith(".go.id");
        const defaultLang = isIndonesianTLD ? "id" : "id";

        return {
          rule: "html-has-lang",
          selector,
          confidence: 0.9,
          source: isIndonesianTLD ? "tld-default-lang" : "engine-default-lang",
          reason: `Document language set to "${defaultLang}" based on domain context`,
          patches: [{ attribute: "lang", value: defaultLang }],
        };
      });
    },
    { selectors, configuredLang },
  );
}
