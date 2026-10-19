/**
 * Disambiguasi Kontekstual untuk Nama Aksesibilitas Kembar.
 *
 * Mengatasi nama tombol, tautan, atau kontrol interaktif kembar yang dihasilkan
 * heuristik (misal: tombol carousel "Media sebelumnya" pada multi-widget)
 * dengan menempelkan nama heading seksi/kontainer terdekat.
 *
 * Human verified labels (source: "verified-override") dilindungi dan tidak diubah.
 */
export async function disambiguateDuplicateCandidates(page, candidates = []) {
  if (!page || !Array.isArray(candidates) || candidates.length === 0) {
    return candidates;
  }

  // Kelompokkan kandidat heuristik berdasarkan nilai label yang diterapkan
  const labelGroups = new Map();

  for (let i = 0; i < candidates.length; i++) {
    const candidate = candidates[i];
    if (candidate.source === "verified-override" || !candidate.patches?.length) {
      continue;
    }

    const labelPatch = candidate.patches.find(
      (p) => p.attribute === "aria-label" || p.attribute === "alt",
    );
    if (!labelPatch || !labelPatch.value) continue;

    const key = labelPatch.value.trim().toLowerCase();
    if (!labelGroups.has(key)) {
      labelGroups.set(key, []);
    }
    labelGroups.get(key).push({ index: i, candidate, patch: labelPatch });
  }

  // Cari kelompok yang memiliki lebih dari satu kandidat dengan nama sama
  const duplicatesToResolve = [];
  for (const [, items] of labelGroups.entries()) {
    if (items.length > 1) {
      for (const item of items) {
        duplicatesToResolve.push({
          index: item.index,
          selector: item.candidate.selector,
          originalValue: item.patch.value,
        });
      }
    }
  }

  if (duplicatesToResolve.length === 0) {
    return candidates;
  }

  // Evaluasi konteks DOM di dalam browser
  const contexts = await page.evaluate((items) => {
    const clean = (val = "") =>
      String(val || "").replace(/\s+/g, " ").trim();

    return items.map((item) => {
      let element;
      try {
        const matches = document.querySelectorAll(item.selector);
        if (matches.length === 1) {
          element = matches[0];
        }
      } catch {
        return { index: item.index, context: null };
      }

      if (!element) return { index: item.index, context: null };

      // Cari kontainer bermakna terdekat
      const container = element.closest(
        "section, article, aside, [role='region'], .card, .widget, .carousel, .slider, li, fieldset",
      );

      let headingText = "";
      if (container) {
        const heading = container.querySelector(
          "h1, h2, h3, h4, h5, h6, [role='heading'], legend",
        );
        headingText = clean(heading?.textContent || "");

        if (!headingText) {
          headingText = clean(
            container.getAttribute("aria-label") ||
              container.getAttribute("title") ||
              "",
          );
        }

        // Cek heading tepat sebelum kontainer
        if (!headingText && container.previousElementSibling) {
          const prev = container.previousElementSibling;
          if (/^h[1-6]$/i.test(prev.tagName)) {
            headingText = clean(prev.textContent || "");
          }
        }
      }

      // Validasi panjang heading agar tidak menjadi kalimat berita panjang
      if (headingText && headingText.length >= 2 && headingText.length <= 40) {
        return { index: item.index, context: headingText };
      }

      return { index: item.index, context: null };
    });
  }, duplicatesToResolve);

  // Perbarui kandidat dengan konteks yang ditemukan
  for (const { index, context } of contexts) {
    if (!context) continue;
    const candidate = candidates[index];
    const patch = candidate.patches.find(
      (p) => p.attribute === "aria-label" || p.attribute === "alt",
    );
    if (patch) {
      const orig = patch.value;
      patch.value = `${orig} (${context})`;
      candidate.source = `${candidate.source}-disambiguated`;
      candidate.reason = `${candidate.reason} (disambiguated with context "${context}")`;
    }
  }

  return candidates;
}
