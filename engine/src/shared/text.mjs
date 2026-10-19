/**
 * Utilitas teks dan tokenisasi berbasis Unicode.
 *
 * Menggunakan Unicode Property Escapes (\p{L}, \p{N}, \p{Lu}, \p{Ll}) untuk
 * memastikan teks beraksara daerah Nusantara (Aksara Jawa, Lontara Sulawesi,
 * Bali, Sunda) atau teks non-Latin tidak rusak atau terpotong saat diproses
 * oleh heuristik engine.
 */

export function cleanUnicode(text = "") {
  return String(text || "")
    .replace(/[_\-]+/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

export function splitCamelCaseUnicode(text = "") {
  return cleanUnicode(
    String(text || "")
      .replace(/([\p{Ll}\p{N}])([\p{Lu}])/gu, "$1 $2")
      .replace(/(\p{Lu}+)(\p{Lu}\p{Ll})/gu, "$1 $2")
      .replace(/[^\p{L}\p{M}\p{N}\s]/gu, " "),
  );
}

export function tokenizeUnicode(text = "") {
  return splitCamelCaseUnicode(text)
    .toLowerCase()
    .split(/[^\p{L}\p{M}\p{N}]+/u)
    .filter(Boolean);
}
