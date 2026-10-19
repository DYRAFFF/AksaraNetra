export async function resolveLinkNameCandidates(
  page,
  selectors = [],
  config = {},
) {
  const siteLabel = config.siteLabel || "";
  const knownLinkLabels = config.knownLinkLabels || {};

  return page.evaluate(
    ({ selectors, siteLabel, knownLinkLabels }) => {
      const clean = (value = "") =>
        String(value).replace(/[_-]+/g, " ").replace(/\s+/g, " ").trim();

      const nearbyHeading = (element) => {
        const container = element.closest(
          "article, section, li, .card, .box, .info-box",
        );
        const heading = container?.querySelector(
          "h1, h2, h3, h4, h5, h6, [role='heading']",
        );
        const text = clean(heading?.textContent || "");
        return text.length <= 80 ? text : "";
      };

      return selectors.map((selector) => {
        let element;
        try {
          const matches = document.querySelectorAll(selector);
          if (matches.length !== 1) {
            return {
              rule: "link-name",
              selector,
              confidence: 0,
              source: "selector-not-unique",
              reason: `Selector matched ${matches.length} elements`,
              patches: [],
            };
          }
          element = matches[0];
        } catch {
          return {
            rule: "link-name",
            selector,
            confidence: 0,
            source: "invalid-selector",
            reason: "Selector could not be evaluated",
            patches: [],
          };
        }

        const existingName =
          clean(element.innerText || "") ||
          clean(element.getAttribute("aria-label") || "") ||
          clean(element.getAttribute("title") || "") ||
          Array.from(element.querySelectorAll("img"))
            .map((image) => clean(image.getAttribute("alt") || ""))
            .filter(Boolean)
            .join(" ");

        if (existingName) {
          return {
            rule: "link-name",
            selector,
            confidence: 0,
            source: "existing-name",
            reason: "Element already has a name and will not be overwritten",
            patches: [],
          };
        }

        const href = element.getAttribute("href") || "";
        if (!href || href === "#" || href.toLowerCase().startsWith("javascript:")) {
          return {
            rule: "link-name",
            selector,
            confidence: 0,
            source: "unknown-destination",
            reason: "Link destination does not explain its purpose",
            patches: [],
          };
        }

        try {
          let url;

          if (/^https?:\/\//i.test(href)) {
            url = new URL(href);
          } else if (/^https?:\/\//i.test(document.baseURI)) {
            url = new URL(href, document.baseURI);
          } else {
            url = new URL(href, "https://domain.local");
          }

          const host = url.hostname.replace(/^www\./, "").toLowerCase();
          const suffix = siteLabel ? ` ${siteLabel}` : "";
          const socialMappings = [
            ["instagram.com", `Buka Instagram${suffix}`],
            ["facebook.com", `Buka Facebook${suffix}`],
            ["youtube.com", `Buka YouTube${suffix}`],
            ["youtu.be", `Buka YouTube${suffix}`],
            ["tiktok.com", `Buka TikTok${suffix}`],
            ["x.com", `Buka X${suffix}`],
            ["twitter.com", `Buka X${suffix}`],
          ];

          const social = socialMappings.find(
            ([domain]) => host === domain || host.endsWith(`.${domain}`),
          );
          if (social) {
            return {
              rule: "link-name",
              selector,
              confidence: 0.95,
              source: "destination-domain",
              reason: "Recognized social destination",
              patches: [{ attribute: "aria-label", value: social[1] }],
            };
          }

          // Sinyal 1: Inline SVG title atau desc
          const svgTitle = clean(
            element.querySelector("svg > title, svg > desc")?.textContent || "",
          );
          if (svgTitle && svgTitle.length >= 2) {
            return {
              rule: "link-name",
              selector,
              confidence: 0.92,
              source: "inline-svg-title",
              reason: "Inline SVG title describes the link purpose",
              patches: [{ attribute: "aria-label", value: svgTitle }],
            };
          }

          const pathname = decodeURIComponent(url.pathname).replace(/\/$/, "");
          const known = knownLinkLabels[pathname] || knownLinkLabels[href];
          if (known) {
            return {
              rule: "link-name",
              selector,
              confidence: 0.9,
              source: "known-link-map",
              reason: "Destination is present in the configured link map",
              patches: [{ attribute: "aria-label", value: known }],
            };
          }

          // Sinyal 2: Atribut tooltip eksplisit
          const tooltip = clean(
            element.getAttribute("data-original-title") ||
              element.getAttribute("data-title") ||
              element.getAttribute("data-tooltip") ||
              element.getAttribute("title") ||
              "",
          );
          if (tooltip && tooltip.length >= 2) {
            return {
              rule: "link-name",
              selector,
              confidence: 0.88,
              source: "tooltip-attribute",
              reason: "Tooltip attribute specifies link destination purpose",
              patches: [{ attribute: "aria-label", value: tooltip }],
            };
          }

          const heading = nearbyHeading(element);
          if (heading) {
            return {
              rule: "link-name",
              selector,
              confidence: 0.85,
              source: "nearby-heading",
              reason: "A nearby heading provides contextual purpose",
              patches: [
                { attribute: "aria-label", value: `Buka ${heading}` },
              ],
            };
          }

          // Sinyal 3: Slug URL dengan kalibrasi bobot
          const slug = clean(pathname.split("/").filter(Boolean).at(-1) || "");
          if (slug && slug.length <= 50 && !/^\d+$/.test(slug)) {
            const formattedSlug = slug.replace(/\b\w/g, (c) => c.toUpperCase());
            const lowerSlug = slug.toLowerCase();
            const INFORMATIVE_KEYWORDS = [
              "kontak",
              "hubungi",
              "profil",
              "tentang",
              "agenda",
              "layanan",
              "regulasi",
              "berita",
              "artikel",
              "ppid",
              "transparansi",
              "unduh",
              "download",
              "faq",
              "bantuan",
              "panduan",
              "kebijakan",
              "privasi",
              "syarat",
              "ketentuan",
              "home",
              "beranda",
            ];
            const isInformative = INFORMATIVE_KEYWORDS.some(
              (kw) => lowerSlug === kw || lowerSlug.includes(kw),
            );

            if (isInformative) {
              return {
                rule: "link-name",
                selector,
                confidence: 0.85,
                source: "informative-url-slug",
                reason: "URL slug matches known informative public section",
                patches: [
                  {
                    attribute: "aria-label",
                    value: `Buka ${formattedSlug}`,
                  },
                ],
              };
            }

            if (
              slug.length < 3 ||
              /^[a-f0-9]{8,}$/i.test(slug) ||
              /^(view|detail|item|post|id|data)$/i.test(slug)
            ) {
              return {
                rule: "link-name",
                selector,
                confidence: 0.5,
                source: "technical-url-slug",
                reason: "Technical URL slug requires human verification",
                patches: [
                  {
                    attribute: "aria-label",
                    value: `Buka ${formattedSlug}`,
                  },
                ],
              };
            }

            return {
              rule: "link-name",
              selector,
              confidence: 0.65,
              source: "generic-url-slug",
              reason: "URL slug is only a suggestion and requires review",
              patches: [
                {
                  attribute: "aria-label",
                  value: `Buka ${formattedSlug}`,
                },
              ],
            };
          }

        } catch {
          // Returned as unknown below.
        }

        return {
          rule: "link-name",
          selector,
          confidence: 0,
          source: "unknown-link",
          reason: "No reliable name source was found",
          patches: [],
        };
      });
    },
    { selectors, siteLabel, knownLinkLabels },
  );
}
