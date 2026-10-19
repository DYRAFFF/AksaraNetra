/**
 * Resolver document-title (axe rule: "document-title").
 *
 * Prinsip: setiap halaman wajib memiliki judul dokumen <title> yang menjelaskan
 * topik atau tujuan halaman, agar pengguna screen reader langsung memahami
 * konteks saat halaman dibuka.
 *
 * Sinyal yang dibaca:
 * 1. Heading utama <h1> pertama di halaman
 * 2. Tag meta OpenGraph (meta[property='og:title'])
 * 3. Identitas situs terkonfigurasi (config.siteLabel)
 * 4. Fallback nama domain yang dinormalisasi
 */
export async function resolveDocumentTitleCandidates(
  page,
  selectors = [],
  config = {},
) {
  const siteLabel = config.siteLabel || "";

  return page.evaluate(
    ({ selectors, siteLabel }) => {
      const clean = (value = "") =>
        String(value || "").replace(/\s+/g, " ").trim();

      const skip = (selector, source, reason) => ({
        rule: "document-title",
        selector,
        confidence: 0,
        source,
        reason,
        patches: [],
      });

      // Pastikan ada elemen <title> di <head>
      let titleElement = document.querySelector("head > title");
      if (!titleElement) {
        titleElement = document.createElement("title");
        document.head?.appendChild(titleElement);
      }

      const existingTitle = clean(document.title || titleElement.textContent || "");
      if (existingTitle && existingTitle.length >= 2 && !/^(untitled|document|index)$/i.test(existingTitle)) {
        return selectors.map((selector) =>
          skip(selector, "existing-name", "Document already has a descriptive title"),
        );
      }

      // Cari sinyal judul terbaik
      const h1 = document.querySelector("h1, [role='heading'][aria-level='1']");
      const h1Text = clean(h1?.textContent || "");

      const ogMeta = document.querySelector("meta[property='og:title'], meta[name='twitter:title']");
      const ogTitle = clean(ogMeta?.getAttribute("content") || "");

      let chosenTitle = "";
      let source = "";
      let reason = "";
      let confidence = 0.85;

      if (h1Text) {
        chosenTitle = siteLabel ? `${h1Text} - ${siteLabel}` : h1Text;
        source = "primary-heading";
        reason = `Primary <h1> heading used as document title${siteLabel ? ` with site label "${siteLabel}"` : ""}`;
        confidence = 0.95;
      } else if (ogTitle) {
        chosenTitle = siteLabel ? `${ogTitle} - ${siteLabel}` : ogTitle;
        source = "opengraph-title";
        reason = "Title extracted from social metadata tag";
        confidence = 0.9;
      } else if (siteLabel) {
        chosenTitle = siteLabel;
        source = "site-label-fallback";
        reason = `Configured site label "${siteLabel}" used as title fallback`;
        confidence = 0.85;
      } else {
        const host = window.location.hostname.replace(/^www\./, "");
        chosenTitle = host || "Halaman Informasi Publik";
        source = "hostname-fallback";
        reason = "Hostname used as minimal fallback title";
        confidence = 0.8;
      }

      return [
        {
          rule: "document-title",
          selector: "head > title",
          confidence,
          source,
          reason,
          patches: [
            {
              attribute: "textContent",
              textContent: chosenTitle,
              value: chosenTitle,
            },
          ],
        },
      ];
    },
    { selectors, siteLabel },
  );
}
