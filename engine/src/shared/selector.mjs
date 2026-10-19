export function firstSimpleSelector(axeNode) {
  const target = axeNode?.target;
  if (!Array.isArray(target) || target.length !== 1) return null;
  return typeof target[0] === "string" ? target[0] : null;
}

export function uniqueSelectors(nodes = []) {
  return [
    ...new Set(
      nodes
        .map(firstSimpleSelector)
        .filter((selector) => typeof selector === "string"),
    ),
  ];
}

/**
 * Membuat sidik jari atribut (fingerprint) dari elemen DOM untuk resolusi target tangguh.
 */
export function createElementFingerprint(element) {
  if (!element || typeof element !== "object") return null;
  const tag = element.tagName ? element.tagName.toLowerCase() : "";
  const role = element.getAttribute ? element.getAttribute("role") : null;
  const id = element.getAttribute ? element.getAttribute("id") : null;
  const className = element.getAttribute ? element.getAttribute("class") || "" : "";
  const classes = className.split(/\s+/).filter(Boolean);
  const parent = element.parentElement;
  const parentTag = parent?.tagName ? parent.tagName.toLowerCase() : null;
  const parentClass = parent?.getAttribute ? parent.getAttribute("class") || null : null;
  const textSnippet = (element.textContent || "").trim().slice(0, 30);

  return {
    tag,
    role,
    id,
    classes,
    parentTag,
    parentClass,
    textSnippet,
  };
}

/**
 * Mencari elemen unik di DOM menggunakan multi-strategi:
 * 1. Exact querySelectorAll(selector)
 * 2. CSS-escaped selector jika selector memuat karakter khusus
 * 3. Fingerprint filtering jika selector menghasilkan lebih dari satu elemen
 */
export function findTargetElementInDom(doc, selector, fingerprint = null) {
  if (!doc || !selector) return null;

  let matches = [];
  try {
    matches = Array.from(doc.querySelectorAll(selector));
  } catch {
    try {
      const sanitized = selector.replace(/([#\.])([0-9]|[^a-zA-Z0-9_\-\\])/g, "$1\\$2");
      matches = Array.from(doc.querySelectorAll(sanitized));
    } catch {
      matches = [];
    }
  }

  if (matches.length === 1) {
    return matches[0];
  }

  if (matches.length > 1 && fingerprint) {
    const filtered = matches.filter((el) => {
      if (fingerprint.tag && el.tagName.toLowerCase() !== fingerprint.tag.toLowerCase()) {
        return false;
      }
      if (fingerprint.id && el.getAttribute("id") !== fingerprint.id) {
        return false;
      }
      if (fingerprint.role && el.getAttribute("role") !== fingerprint.role) {
        return false;
      }
      if (fingerprint.parentTag && el.parentElement?.tagName.toLowerCase() !== fingerprint.parentTag.toLowerCase()) {
        return false;
      }
      if (fingerprint.classes?.length) {
        const elClasses = (el.getAttribute("class") || "").split(/\s+/).filter(Boolean);
        const hasMatchingClass = fingerprint.classes.some((c) => elClasses.includes(c));
        if (!hasMatchingClass) return false;
      }
      return true;
    });

    if (filtered.length === 1) {
      return filtered[0];
    }
  }

  return null;
}

/**
 * Menemukan elemen bersarang di dalam iframe menggunakan Playwright frameLocator.
 */
export function resolveNestedTarget(page, targetChain = []) {
  if (!page || !Array.isArray(targetChain) || targetChain.length === 0) {
    return null;
  }

  if (targetChain.length === 1) {
    return page.locator(targetChain[0]);
  }

  let locator = page.frameLocator(targetChain[0]);
  for (let i = 1; i < targetChain.length - 1; i++) {
    locator = locator.frameLocator(targetChain[i]);
  }
  return locator.locator(targetChain.at(-1));
}


