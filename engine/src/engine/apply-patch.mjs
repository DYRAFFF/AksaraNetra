import { classifyConfidence } from "./confidence.mjs";
import { createSanitizedSnippet } from "../decisions/index.mjs";

export async function applyCandidates(page, candidates, policy) {
  const fixed = [];
  const review = [];
  const skipped = [];

  for (const candidate of candidates) {
    const classification = classifyConfidence(candidate.confidence, policy);

    if (classification === "review") {
      review.push({ ...candidate, status: "review" });
      continue;
    }

    if (classification === "skip" || !candidate.patches?.length) {
      skipped.push({ ...candidate, status: "skipped" });
      continue;
    }

    const result = await page.evaluate((candidate) => {
      let element;
      try {
        let matches = Array.from(document.querySelectorAll(candidate.selector));
        if (matches.length === 1) {
          element = matches[0];
        } else if (matches.length === 0) {
          try {
            const sanitized = candidate.selector.replace(
              /([#\.])([0-9]|[^a-zA-Z0-9_\-\\])/g,
              "$1\\$2",
            );
            const sanitizedMatches = Array.from(
              document.querySelectorAll(sanitized),
            );
            if (sanitizedMatches.length === 1) {
              element = sanitizedMatches[0];
            }
          } catch {
            // Abaikan error sintaks pada sanitasi
          }
        } else if (matches.length > 1 && candidate.fingerprint) {
          const fp = candidate.fingerprint;
          const filtered = matches.filter((el) => {
            if (
              fp.tag &&
              el.tagName.toLowerCase() !== fp.tag.toLowerCase()
            ) {
              return false;
            }
            if (fp.id && el.getAttribute("id") !== fp.id) return false;
            if (fp.role && el.getAttribute("role") !== fp.role) return false;
            if (
              fp.parentTag &&
              el.parentElement?.tagName.toLowerCase() !==
                fp.parentTag.toLowerCase()
            ) {
              return false;
            }
            if (fp.classes?.length) {
              const elClasses = (el.getAttribute("class") || "")
                .split(/\s+/)
                .filter(Boolean);
              if (!fp.classes.some((c) => elClasses.includes(c))) return false;
            }
            return true;
          });

          if (filtered.length === 1) {
            element = filtered[0];
          }
        }

        if (!element) {
          return {
            applied: false,
            reason: `Selector matched ${matches.length} elements during apply`,
            appliedPatches: [],
          };
        }
      } catch {
        return {
          applied: false,
          reason: "Selector failed during apply",
          appliedPatches: [],
        };
      }

      // Snapshot atribut pra-patch komprehensif (Poin 2.5)
      const prePatchSnapshot = {
        tagName: element.tagName.toLowerCase(),
        attributes: {},
        textContent: element.textContent || "",
        outerHTML: element.outerHTML,
      };
      for (let i = 0; i < element.attributes.length; i++) {
        const attr = element.attributes[i];
        prePatchSnapshot.attributes[attr.name] = attr.value;
      }

      const preparedPatches = candidate.patches.map((patch) => {
        const patchId = `${candidate.rule}:${candidate.selector}:${patch.attribute}`;
        if (patch.attribute === "textContent") {
          return {
            patchId,
            attribute: "textContent",
            isText: true,
            oldValue: element.textContent || null,
            newValue: patch.value,
          };
        }
        return {
          patchId,
          attribute: patch.attribute,
          isText: false,
          oldValue: element.hasAttribute(patch.attribute)
            ? element.getAttribute(patch.attribute)
            : null,
          newValue: patch.value,
        };
      });

      const conflict = preparedPatches.find(
        (patch) =>
          patch.oldValue !== null &&
          patch.oldValue !== "" &&
          patch.oldValue !== patch.newValue,
      );

      if (conflict) {
        return {
          applied: false,
          reason: `Existing ${conflict.attribute} was preserved`,
          appliedPatches: [],
        };
      }

      const appliedPatches = [];
      for (const patch of preparedPatches) {
        if (patch.isText) {
          element.textContent = patch.newValue;
        } else {
          element.setAttribute(patch.attribute, patch.newValue);
        }
        appliedPatches.push(patch);
      }

      element.setAttribute("data-project-a-fixed", candidate.rule);
      element.setAttribute("data-project-a-source", candidate.source);
      element.setAttribute(
        "data-project-a-confidence",
        String(candidate.confidence),
      );

      return {
        applied: true,
        reason: "Patch applied",
        appliedPatches,
        prePatchSnapshot,
        htmlAfter: element.outerHTML,
      };
    }, candidate);

    if (result.applied) {
      const fixedRecord = {
        ...candidate,
        status: "applied",
        appliedPatches: result.appliedPatches,
        prePatchSnapshot: result.prePatchSnapshot,
        htmlAfter: result.htmlAfter,
      };
      fixedRecord.snippet = createSanitizedSnippet(fixedRecord);
      fixed.push(fixedRecord);
    } else {
      skipped.push({
        ...candidate,
        status: "skipped",
        reason: result.reason,
        appliedPatches: result.appliedPatches,
      });
    }
  }

  return { fixed, review, skipped };
}

export async function rollbackSingleRecord(page, record) {
  if (!page || !record) return;

  await page.evaluate((record) => {
    let element;
    try {
      const matches = document.querySelectorAll(record.selector);
      if (matches.length === 1) {
        element = matches[0];
      } else if (matches.length > 1 && record.fingerprint) {
        const fp = record.fingerprint;
        const filtered = Array.from(matches).filter((el) => {
          if (fp.tag && el.tagName.toLowerCase() !== fp.tag.toLowerCase()) {
            return false;
          }
          if (fp.id && el.getAttribute("id") !== fp.id) return false;
          return true;
        });
        if (filtered.length === 1) element = filtered[0];
      }
    } catch {
      return;
    }

    if (!element) return;

    if (record.prePatchSnapshot && record.prePatchSnapshot.attributes) {
      const origAttrs = record.prePatchSnapshot.attributes;
      for (const patch of record.appliedPatches || []) {
        if (patch.isText) {
          element.textContent = record.prePatchSnapshot.textContent || "";
        } else if (!(patch.attribute in origAttrs) || origAttrs[patch.attribute] === null) {
          element.removeAttribute(patch.attribute);
        } else {
          element.setAttribute(patch.attribute, origAttrs[patch.attribute]);
        }
      }
    } else {
      for (const patch of [...(record.appliedPatches || [])].reverse()) {
        if (patch.isText) {
          element.textContent = patch.oldValue || "";
        } else if (patch.oldValue === null) {
          element.removeAttribute(patch.attribute);
        } else {
          element.setAttribute(patch.attribute, patch.oldValue);
        }
      }
    }

    element.removeAttribute("data-project-a-fixed");
    element.removeAttribute("data-project-a-source");
    element.removeAttribute("data-project-a-confidence");
  }, record);
}

export async function rollbackPatches(page, fixedRecords = []) {
  for (const record of [...fixedRecords].reverse()) {
    await rollbackSingleRecord(page, record);
  }
}

