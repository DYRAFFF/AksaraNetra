import { uniqueSelectors } from "../shared/selector.mjs";

export const SUPPORTED_RULES = Object.freeze([
  "link-name",
  "button-name",
  "scrollable-region-focusable",
  "image-alt",
  "label",
  "html-has-lang",
  "document-title",
  "landmark-one-main",
]);

export function extractTargets(
  axeResult,
  supportedRules = SUPPORTED_RULES,
) {
  const allowed = new Set(supportedRules);
  const targets = {};

  for (const violation of axeResult?.violations ?? []) {
    if (!allowed.has(violation.id)) continue;

    const simpleSelectors = [];
    const nestedTargets = [];
    let invalidCount = 0;

    for (const node of violation.nodes ?? []) {
      if (!Array.isArray(node.target) || node.target.length === 0) {
        invalidCount++;
        continue;
      }

      if (node.target.length === 1 && typeof node.target[0] === "string") {
        simpleSelectors.push(node.target[0]);
      } else if (
        node.target.length > 1 &&
        node.target.every((t) => typeof t === "string")
      ) {
        nestedTargets.push({
          chain: node.target,
          compoundSelector: node.target.join(" >>> "),
          frameSelector: node.target[0],
          innerSelector: node.target.slice(1).join(" >>> "),
        });
      } else {
        invalidCount++;
      }
    }

    targets[violation.id] = {
      ruleId: violation.id,
      impact: violation.impact,
      selectors: [...new Set(simpleSelectors)],
      nestedTargets,
      unsupportedTargets: invalidCount,
    };
  }

  return targets;
}
