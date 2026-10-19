import AxeBuilder from "@axe-core/playwright";
import { extractTargets, SUPPORTED_RULES } from "./extract-targets.mjs";
import { DEFAULT_CONFIDENCE_POLICY } from "./confidence.mjs";
import {
  applyCandidates,
  rollbackPatches,
  rollbackSingleRecord,
} from "./apply-patch.mjs";
import {
  createMetrics,
  createRegressionReport,
  findDuplicateLabels,
} from "./report.mjs";
import {
  applyVerifiedLabels,
  findStaleOverrides,
} from "./verified-labels.mjs";
import { disambiguateDuplicateCandidates } from "./disambiguate.mjs";
import { resolveLinkNameCandidates } from "../rules/link-name.mjs";
import { resolveButtonNameCandidates } from "../rules/button-name.mjs";
import { resolveScrollableRegionCandidates } from "../rules/scrollable-region.mjs";
import { resolveImageAltCandidates } from "../rules/image-alt.mjs";
import { resolveFormLabelCandidates } from "../rules/form-label.mjs";
import { resolveHtmlLangCandidates } from "../rules/html-lang.mjs";
import { resolveDocumentTitleCandidates } from "../rules/document-title.mjs";
import { resolveLandmarkCandidates } from "../rules/landmarks.mjs";
import { ENGINE_VERSION } from "../shared/version.mjs";

const resolvers = {
  "link-name": resolveLinkNameCandidates,
  "button-name": resolveButtonNameCandidates,
  "scrollable-region-focusable": resolveScrollableRegionCandidates,
  "image-alt": resolveImageAltCandidates,
  "label": resolveFormLabelCandidates,
  "html-has-lang": resolveHtmlLangCandidates,
  "document-title": resolveDocumentTitleCandidates,
  "landmark-one-main": resolveLandmarkCandidates,
};

const WCAG_REGRESSION_TAGS = Object.freeze([
  "wcag2a",
  "wcag2aa",
  "wcag21a",
  "wcag21aa",
]);

async function runTargetedAxe(page, rules) {
  if (!rules.length) {
    return { violations: [], passes: [], incomplete: [], inapplicable: [] };
  }

  return new AxeBuilder({ page }).withRules(rules).analyze();
}

async function runWideAxe(page, tags = WCAG_REGRESSION_TAGS) {
  if (!page || typeof page.evaluate !== "function") {
    return { violations: [], passes: [], incomplete: [], inapplicable: [] };
  }
  return new AxeBuilder({ page }).withTags(tags).analyze();
}

function regressionSelectors(report, afterAxe) {
  const worsenedRules = new Set((report?.worsened ?? []).map((item) => item.ruleId));
  const selectors = new Set();

  for (const violation of afterAxe?.violations ?? []) {
    if (!worsenedRules.has(violation.id)) continue;
    for (const node of violation.nodes ?? []) {
      if (Array.isArray(node.target) && node.target.length === 1) {
        selectors.add(String(node.target[0]));
      }
    }
  }
  return selectors;
}

export async function remediatePage({
  page,
  axeResult,
  rules = SUPPORTED_RULES,
  confidencePolicy = DEFAULT_CONFIDENCE_POLICY,
  config = {},
  rollbackOnRegression = true,
  runWideScan = true,
}) {
  if (!page) throw new Error("remediatePage requires a Playwright page");
  if (!axeResult) throw new Error("remediatePage requires an axe result");

  const supportedRules = rules.filter((ruleId) => resolvers[ruleId]);

  // The caller's axe result may have been filtered by WCAG tags. Some rules,
  // such as scrollable-region-focusable, are best-practice rules and would be
  // absent from that result. Run an explicit targeted baseline so every
  // requested engine rule is measured before any patch is applied.
  const targetedBefore = await runTargetedAxe(page, supportedRules);
  const targets = extractTargets(targetedBefore, supportedRules);
  const resolved = [];

  for (const ruleId of supportedRules) {
    const selectors = targets[ruleId]?.selectors ?? [];
    resolved.push(...(await resolvers[ruleId](page, selectors, config)));
  }

  // Human verified labels win over anything the heuristics produced.
  const candidatesWithVerified = applyVerifiedLabels(resolved, config);
  const candidates = await disambiguateDuplicateCandidates(
    page,
    candidatesWithVerified,
  );
  const staleOverrides = findStaleOverrides(resolved, config);

  const changes = await applyCandidates(page, candidates, confidencePolicy);

  let after = await runTargetedAxe(page, supportedRules);
  let metrics = createMetrics(targetedBefore, after, supportedRules);
  let rolledBack = false;
  let partiallyRolledBack = false;
  const rolledBackRecords = [];

  if (rollbackOnRegression && !metrics.noRegression) {
    // Mekanisme Rollback Parsial (Poin 2.2)
    const worsenedRuleIds = new Set(metrics.worsened.map((w) => w.ruleId));

    const candidateSuspects = [...changes.fixed].sort((a, b) => {
      const aWorsened = worsenedRuleIds.has(a.rule) ? 1 : 0;
      const bWorsened = worsenedRuleIds.has(b.rule) ? 1 : 0;
      return bWorsened - aWorsened;
    });

    let partialSuccess = false;
    for (const suspect of candidateSuspects) {
      await rollbackSingleRecord(page, suspect);
      const testAfter = await runTargetedAxe(page, supportedRules);
      const testMetrics = createMetrics(
        targetedBefore,
        testAfter,
        supportedRules,
      );

      if (testMetrics.noRegression) {
        after = testAfter;
        metrics = testMetrics;
        partiallyRolledBack = true;
        partialSuccess = true;
        rolledBackRecords.push(suspect);

        changes.fixed = changes.fixed.filter((f) => f !== suspect);
        changes.skipped.push({
          ...suspect,
          status: "skipped",
          reason: `Rolled back to prevent regression on rule(s): ${[...worsenedRuleIds].join(", ")}`,
        });
        break;
      } else {
        rolledBackRecords.push(suspect);
        changes.fixed = changes.fixed.filter((f) => f !== suspect);
        changes.skipped.push({
          ...suspect,
          status: "skipped",
          reason: "Candidate tested for rollback during regression resolution",
        });
        if (changes.fixed.length === 0) {
          after = testAfter;
          metrics = testMetrics;
          rolledBack = true;
          break;
        }
      }
    }

    if (!partialSuccess && !rolledBack && changes.fixed.length > 0) {
      await rollbackPatches(page, changes.fixed);
      after = await runTargetedAxe(page, supportedRules);
      metrics = createMetrics(targetedBefore, after, supportedRules);
      rolledBack = true;
      changes.fixed = [];
    }
  }

  let fixed = rolledBack ? [] : changes.fixed;

  // Pemindaian luas (wide axe scan) untuk menangkap regresi pada seluruh rule WCAG
  let wideAfterAxe = null;
  let wideRegression = { rules: {}, worsened: [], newViolationRules: [], clean: true };

  if (runWideScan !== false && page && typeof page.evaluate === "function") {
    wideAfterAxe = await runWideAxe(page);
    if (axeResult) {
      wideRegression = createRegressionReport(axeResult, wideAfterAxe);

      if (rollbackOnRegression && !wideRegression.clean && fixed.length) {
        const badSelectors = regressionSelectors(wideRegression, wideAfterAxe);
        const implicated = fixed.filter((record) => badSelectors.has(record.selector));

        if (implicated.length) {
          for (const imp of implicated) {
            await rollbackSingleRecord(page, imp);
            rolledBackRecords.push(imp);
            changes.fixed = changes.fixed.filter((f) => f !== imp);
            changes.skipped.push({
              ...imp,
              status: "skipped",
              reason: "Rolled back due to wide WCAG regression at target selector",
            });
          }
          fixed = changes.fixed;
          after = await runTargetedAxe(page, supportedRules);
          metrics = createMetrics(targetedBefore, after, supportedRules);
          wideAfterAxe = await runWideAxe(page);
          wideRegression = createRegressionReport(axeResult, wideAfterAxe);
        }
      }
    }
  }

  return {
    engineVersion: ENGINE_VERSION,
    inputAxe: axeResult,
    beforeAxe: targetedBefore,
    targets,
    candidates,
    fixed,
    review: changes.review,
    skipped: changes.skipped,
    rolledBack: rolledBack || (rolledBackRecords.length > 0 && fixed.length === 0),
    partiallyRolledBack: partiallyRolledBack || (rolledBackRecords.length > 0 && fixed.length > 0),
    rolledBackRecords,
    metrics,
    duplicateLabels: findDuplicateLabels(fixed),
    staleOverrides,
    afterAxe: after,
    wideAfterAxe,
    wideRegression,
  };
}

export {
  DEFAULT_CONFIDENCE_POLICY,
  SUPPORTED_RULES,
  WCAG_REGRESSION_TAGS,
  createRegressionReport,
  rollbackPatches,
  rollbackSingleRecord,
  disambiguateDuplicateCandidates,
};

