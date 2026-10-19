/**
 * Resolver landmark-one-main (axe rule: "landmark-one-main").
 *
 * Prinsip: setiap halaman web harus memiliki satu landmark main utama
 * (<main> atau role="main") agar pengguna pembaca layar dapat langsung melompat
 * ke konten pokok menggunakan pintasan keyboard (tombol D pada NVDA).
 *
 * Sinyal yang dibaca:
 * 1. Kontainer dengan ID atau class standar: #main-content, #content, .content-area, article
 * 2. Kontainer terdekat yang membungkus <h1> dan teks artikel
 * 3. Fallback kontainer tubuh halaman utama
 */
export async function resolveLandmarkCandidates(
  page,
  selectors = [],
  config = {},
) {
  return page.evaluate(
    ({ selectors }) => {
      const skip = (selector, source, reason) => ({
        rule: "landmark-one-main",
        selector,
        confidence: 0,
        source,
        reason,
        patches: [],
      });

      // Cek apakah dokumen sudah memiliki elemen main
      const existingMain = document.querySelector("main, [role='main']");
      if (existingMain) {
        return selectors.map((selector) =>
          skip(selector, "existing-name", "Document already has a main landmark"),
        );
      }

      // Sinyal 1: Mencari kontainer dengan id/class standar
      const CANDIDATE_SELECTORS = [
        "#main-content",
        "#content",
        ".main-content",
        ".content-area",
        "article.main",
        "#main",
        ".main",
      ];

      for (const candidateSel of CANDIDATE_SELECTORS) {
        const matches = document.querySelectorAll(candidateSel);
        if (matches.length === 1) {
          const el = matches[0];
          // Pastikan bukan elemen tersembunyi
          if (el.offsetParent !== null || el.textContent.trim().length > 0) {
            return [
              {
                rule: "landmark-one-main",
                selector: candidateSel,
                confidence: 0.9,
                source: "standard-content-wrapper",
                reason: `Identified standard content container "${candidateSel}" as main landmark`,
                patches: [{ attribute: "role", value: "main" }],
              },
            ];
          }
        }
      }

      // Sinyal 2: Kontainer terdekat dari heading <h1>
      const h1 = document.querySelector("h1");
      if (h1) {
        let parent = h1.parentElement;
        while (parent && parent !== document.body && parent !== document.documentElement) {
          const tag = parent.tagName.toLowerCase();
          const id = parent.getAttribute("id");
          const cls = parent.getAttribute("class");

          if (["article", "section"].includes(tag) || (cls && /content|article|post/i.test(cls))) {
            const selector = id
              ? `#${CSS.escape(id)}`
              : `${tag}${cls ? `.${cls.trim().split(/\s+/)[0]}` : ""}`;

            if (document.querySelectorAll(selector).length === 1) {
              return [
                {
                  rule: "landmark-one-main",
                  selector,
                  confidence: 0.85,
                  source: "dominant-content-container",
                  reason: "Container wrapping primary <h1> identified as main landmark",
                  patches: [{ attribute: "role", value: "main" }],
                },
              ];
            }
          }
          parent = parent.parentElement;
        }
      }

      // Sinyal 3: Elemen div pertama di bawah body yang bukan header/footer/nav
      const bodyChildren = [...document.body.children].filter((el) => {
        const tag = el.tagName.toLowerCase();
        const id = (el.getAttribute("id") || "").toLowerCase();
        const cls = (el.getAttribute("class") || "").toLowerCase();
        return (
          !["header", "footer", "nav", "aside", "script", "style"].includes(tag) &&
          !/header|navbar|menu|footer|sidebar/i.test(`${id} ${cls}`)
        );
      });

      if (bodyChildren.length === 1) {
        const targetEl = bodyChildren[0];
        const id = targetEl.getAttribute("id");
        const selector = id ? `#${CSS.escape(id)}` : "body > div:first-of-type";
        return [
          {
            rule: "landmark-one-main",
            selector,
            confidence: 0.8,
            source: "body-content-child",
            reason: "Single primary content wrapper under body designated as main landmark",
            patches: [{ attribute: "role", value: "main" }],
          },
        ];
      }

      return selectors.map((selector) =>
        skip(
          selector,
          "unknown-landmark",
          "Could not uniquely determine primary content container for main landmark",
        ),
      );
    },
    { selectors },
  );
}
