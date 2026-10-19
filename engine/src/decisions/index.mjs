/**
 * Daftar keputusan yang bisa ditampilkan ke pengguna.
 *
 * Kenapa berkas ini ada. Snapshot selama ini hanya menyimpan jumlah: 18
 * diperbaiki, 0 perlu diperiksa, 2 dilewati. Angka itu tidak bisa dibantah
 * siapa pun, dan itu masalahnya. Alat yang mengubah halaman orang lain harus
 * mau menunjukkan apa saja yang diubahnya, satu per satu.
 *
 * ATURAN KEAMANAN YANG TIDAK BOLEH DILONGGARKAN.
 * Record dari engine membawa field htmlAfter berisi HTML mentah dari situs
 * yang diaudit, lengkap dengan tag svg dan atributnya. Field itu WAJIB
 * dibuang di sini. Dua alasannya:
 *
 *   1. Ukurannya. Satu record bisa lebih dari dua kilobyte, dan dua puluh
 *      record akan membuat snapshot.json membengkak tanpa manfaat.
 *   2. Keamanan. Menaruh HTML mentah milik situs lain ke dalam JSON yang
 *      dibaca UI kita adalah cara termudah membuat lubang injeksi. UI
 *      menampilkan nilai di sini sebagai teks biasa, dan itu hanya aman
 *      selama isinya memang teks biasa.
 *
 * Modul ini murni. Tidak membaca berkas, tidak menyentuh browser.
 */

/**
 * Batas jumlah baris per kelompok.
 *
 * Bukan untuk menyembunyikan sesuatu. Daftar 200 baris di layar hasil justru
 * membuat orang berhenti membacanya. Kalau ada yang dipotong, jumlah aslinya
 * tetap dilaporkan lewat field total, dan berkas engine-result.json di folder
 * hasil tetap memuat semuanya tanpa dipotong.
 */
export const MAX_DECISIONS_PER_GROUP = 25;

/**
 * Atribut yang isinya memang teks untuk dibacakan pembaca layar.
 *
 * Dipakai untuk menemukan label yang diusulkan. Urutannya sengaja, karena satu
 * record bisa punya lebih dari satu tambalan.
 */
const LABEL_ATTRIBUTES = Object.freeze(["aria-label", "alt", "title"]);

function bacaLabel(record) {
  const patches = Array.isArray(record?.patches) ? record.patches : [];

  for (const attribute of LABEL_ATTRIBUTES) {
    const cocok = patches.find((patch) => patch?.attribute === attribute);
    if (cocok && String(cocok.value ?? "").trim()) return String(cocok.value);
  }

  // Tambalan yang bukan label, misalnya tabindex pada area gulir. Tidak ada
  // teks untuk ditampilkan, dan mengarang teks di sini akan menyesatkan.
  const pertama = patches[0];
  if (pertama?.attribute) return null;

  return null;
}

function bacaAtribut(record) {
  const patches = Array.isArray(record?.patches) ? record.patches : [];
  return patches
    .map((patch) => patch?.attribute)
    .filter((value) => typeof value === "string" && value);
}

const SELF_CLOSING_TAGS = new Set(["img", "input", "br", "hr", "meta", "link", "area", "col"]);
const FORBIDDEN_TAGS = new Set(["script", "iframe", "object", "embed", "applet", "style", "svg"]);

function escapeAttr(val) {
  return String(val ?? "")
    .replace(/&/g, "&amp;")
    .replace(/"/g, "&quot;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

function isSafeAttr(name, value) {
  if (typeof name !== "string") return false;
  const cleanName = name.trim();
  if (!/^[a-zA-Z0-9_:-]+$/.test(cleanName)) return false;
  if (/^on/i.test(cleanName)) return false;
  if (/^data-project-a-/i.test(cleanName)) return false;

  const strVal = String(value ?? "").trim();
  if (/^\s*(javascript|vbscript|data:text\/html):/i.test(strVal)) return false;
  return true;
}

function parseAttributesFromTag(openingTagStr) {
  const attrs = {};
  const regex = /([a-zA-Z0-9_:-]+)(?:=(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/g;
  let match;
  while ((match = regex.exec(openingTagStr)) !== null) {
    const attrName = match[1];
    const attrVal = match[2] ?? match[3] ?? match[4] ?? "";
    if (isSafeAttr(attrName, attrVal)) {
      attrs[attrName] = attrVal;
    }
  }
  return attrs;
}

/**
 * Menghasilkan cuplikan kode diff HTML mini yang sudah dibersihkan dari tag berbahaya
 * dan atribut berisiko, menggantikan kebutuhan terhadap htmlAfter mentah.
 */
export function createSanitizedSnippet(record) {
  if (!record || typeof record !== "object") return null;

  if (
    record.snippet &&
    typeof record.snippet === "object" &&
    typeof record.snippet.before === "string" &&
    typeof record.snippet.after === "string"
  ) {
    return {
      before: record.snippet.before,
      after: record.snippet.after,
    };
  }

  let tagName = "div";
  let beforeAttrs = {};
  let innerText = "";

  if (record.prePatchSnapshot && typeof record.prePatchSnapshot === "object") {
    const rawTag = String(record.prePatchSnapshot.tagName || "").toLowerCase().trim();
    if (rawTag && !FORBIDDEN_TAGS.has(rawTag)) {
      tagName = rawTag;
    }
    if (record.prePatchSnapshot.attributes && typeof record.prePatchSnapshot.attributes === "object") {
      for (const [k, v] of Object.entries(record.prePatchSnapshot.attributes)) {
        if (isSafeAttr(k, v)) {
          beforeAttrs[k] = String(v);
        }
      }
    }
    if (typeof record.prePatchSnapshot.textContent === "string") {
      innerText = record.prePatchSnapshot.textContent.trim().slice(0, 50);
    }
  } else if (record.htmlAfter && typeof record.htmlAfter === "string") {
    const tagMatch = record.htmlAfter.match(/<([a-zA-Z0-9:-]+)(\s[^>]*)?>/i);
    if (tagMatch) {
      const rawTag = tagMatch[1].toLowerCase();
      if (!FORBIDDEN_TAGS.has(rawTag)) {
        tagName = rawTag;
      }
      if (tagMatch[2]) {
        beforeAttrs = parseAttributesFromTag(tagMatch[2]);
      }
    }
  } else if (record.rule) {
    if (record.rule === "image-alt") tagName = "img";
    else if (record.rule === "label") tagName = "input";
    else if (record.rule === "link-name") tagName = "a";
    else if (record.rule === "button-name") tagName = "button";
    else if (record.rule === "document-title") tagName = "title";
    else if (record.rule === "html-has-lang") tagName = "html";
    else if (record.rule === "landmark-one-main") tagName = "main";
    else if (record.rule === "scrollable-region-focusable") tagName = "div";

    if (record.selector && typeof record.selector === "string") {
      const classMatch = record.selector.match(/\.([a-zA-Z0-9_-]+)/);
      if (classMatch) {
        beforeAttrs.class = classMatch[1];
      }
      const idMatch = record.selector.match(/#([a-zA-Z0-9_-]+)/);
      if (idMatch) {
        beforeAttrs.id = idMatch[1];
      }
    }
  }

  const patches = Array.isArray(record.patches)
    ? record.patches
    : Array.isArray(record.appliedPatches)
      ? record.appliedPatches
      : [];

  const afterAttrs = { ...beforeAttrs };
  let afterText = innerText;

  for (const patch of patches) {
    if (!patch) continue;
    const attr = patch.attribute;
    const val = patch.value ?? patch.newValue;
    if (attr === "textContent") {
      afterText = String(val ?? "").trim().slice(0, 50);
    } else if (isSafeAttr(attr, val)) {
      afterAttrs[attr] = String(val);
    }
  }

  const renderTag = (tag, attrs, text) => {
    const attrPairs = Object.entries(attrs)
      .map(([k, v]) => `${k}="${escapeAttr(v)}"`)
      .sort((a, b) => {
        const order = ["id=", "class=", "type=", "href=", "src="];
        const aIdx = order.findIndex((prefix) => a.startsWith(prefix));
        const bIdx = order.findIndex((prefix) => b.startsWith(prefix));
        if (aIdx !== -1 && bIdx !== -1) return aIdx - bIdx;
        if (aIdx !== -1) return -1;
        if (bIdx !== -1) return 1;
        return a.localeCompare(b);
      });

    const attrStr = attrPairs.length ? " " + attrPairs.join(" ") : "";
    if (SELF_CLOSING_TAGS.has(tag)) {
      return `<${tag}${attrStr}>`;
    }
    if (tag === "title") {
      return `<title${attrStr}>${escapeAttr(text)}</title>`;
    }
    const inner = text ? escapeAttr(text) : "...";
    return `<${tag}${attrStr}>${inner}</${tag}>`;
  };

  return {
    before: renderTag(tagName, beforeAttrs, innerText),
    after: renderTag(tagName, afterAttrs, afterText),
  };
}

/**
 * Menyaring satu record engine menjadi bentuk yang aman ditampilkan.
 *
 * Bentuk keluarannya sengaja datar dan hanya berisi nilai sederhana. Kalau
 * suatu hari engine menambah field baru, field itu tidak akan ikut lolos ke
 * UI tanpa seseorang memutuskannya di sini lebih dulu.
 */
export function decisionFromRecord(record) {
  if (!record?.rule) return null;

  return {
    rule: String(record.rule),
    selector: record.selector ? String(record.selector) : null,
    confidence: typeof record.confidence === "number" ? record.confidence : null,
    source: record.source ? String(record.source) : null,
    reason: record.reason ? String(record.reason) : null,
    label: bacaLabel(record),
    attributes: bacaAtribut(record),
    status: record.status ? String(record.status) : null,
    snippet: createSanitizedSnippet(record),
  };
}

function kelompok(records, maxPerGroup) {
  const semua = Array.isArray(records) ? records : [];
  const bersih = semua.map(decisionFromRecord).filter(Boolean);

  return {
    total: bersih.length,
    truncated: bersih.length > maxPerGroup,
    items: bersih.slice(0, maxPerGroup),
  };
}

/**
 * Menyusun tiga kelompok keputusan dari satu laporan engine.
 *
 * applied  sudah diterapkan ke halaman tambalan, keyakinan minimal 0.80
 * review   diusulkan tetapi tidak diterapkan, keyakinan 0.60 sampai 0.79
 * skipped  tidak diusulkan sama sekali, keyakinan di bawah 0.60
 *
 * Kelompok review sering kosong, dan itu bukan alasan untuk menghapusnya dari
 * tampilan. Kelompok yang kosong adalah informasi: pada audit itu, tidak ada
 * satu pun kasus yang menggantung di tengah.
 */
export function summarizeDecisions(report, { maxPerGroup = MAX_DECISIONS_PER_GROUP } = {}) {
  return {
    maxPerGroup,
    applied: kelompok(report?.fixed, maxPerGroup),
    review: kelompok(report?.review, maxPerGroup),
    skipped: kelompok(report?.skipped, maxPerGroup),
  };
}
