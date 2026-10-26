/**
 * Shared conditional-logic helpers for dynamic forms (ticket form + survey).
 *
 * v2 logic shape: { action: 'show'|'hide', match: 'all'|'any',
 *                   rules: [{ field_key, op, value }] }
 * Legacy shape (pre-v14): { field_key, op, value, action } — normalized
 * to v2 with match 'all'.
 *
 * Survey fields may reference the ticket's own form answers in rules via
 * "ticket:<field_key>" — callers merge those into the answer map under
 * the prefixed key.
 */

const VALID_OPS = ['equals', 'not_equals', 'contains', 'not_contains'];

function cleanRule(rule) {
    if (!rule || typeof rule !== 'object' || !rule.field_key) return null;
    return {
        field_key: String(rule.field_key),
        op: VALID_OPS.includes(rule.op) ? rule.op : 'equals',
        value: String(rule.value ?? ''),
    };
}

/**
 * Normalize any stored logic config to the v2 shape. Returns null when
 * there is no usable rule (e.g. the editor's empty placeholder).
 */
export function normalizeLogic(logic) {
    if (!logic || typeof logic !== 'object') return null;

    if (Array.isArray(logic.rules)) {
        const rules = logic.rules.map(cleanRule).filter(Boolean).slice(0, 10);
        if (!rules.length) return null;
        return {
            action: logic.action === 'hide' ? 'hide' : 'show',
            match: logic.match === 'any' ? 'any' : 'all',
            rules,
        };
    }

    // Legacy single-rule shape.
    const rule = cleanRule(logic);
    if (!rule) return null;
    return {
        action: logic.action === 'hide' ? 'hide' : 'show',
        match: 'all',
        rules: [rule],
    };
}

/**
 * Evaluate one rule against an answer map. Array values use includes()
 * for contains (matches DynamicForm's original semantics — a cascade
 * answer like ['A','B','C'] contains 'B').
 */
export function ruleMatches(rule, answers) {
    if (!rule || !rule.field_key) return false;
    const depVal = answers?.[rule.field_key];
    const target = String(rule.value ?? '');
    switch (rule.op) {
        case 'equals':       return String(depVal) === target;
        case 'not_equals':   return String(depVal) !== target;
        case 'contains':     return Array.isArray(depVal) ? depVal.includes(target) : String(depVal).includes(target);
        case 'not_contains': return Array.isArray(depVal) ? !depVal.includes(target) : !String(depVal).includes(target);
    }
    return false;
}

/**
 * Is a field visible given its config and the current answer map?
 * Accepts both legacy and v2 logic shapes; absent logic = visible.
 */
export function isFieldVisible(config, answers) {
    const logic = normalizeLogic(config?.logic);
    if (!logic) return true;
    const met = logic.match === 'any'
        ? logic.rules.some(r => ruleMatches(r, answers))
        : logic.rules.every(r => ruleMatches(r, answers));
    return logic.action === 'show' ? met : !met;
}
