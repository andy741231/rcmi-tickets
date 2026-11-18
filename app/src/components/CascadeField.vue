<template>
    <div class="rcmi-cascade">
        <!-- ── Search (primary) ───────────────────────────────────────
             One search over every level of the tree, for all display
             styles. The levels below are the secondary way in. -->
        <div v-if="hasSearch" ref="searchEl" class="rcmi-cascade-search">
            <div class="rcmi-cascade-search-box">
                <Icon name="search" />
                <input v-model="query" type="text" class="rcmi-cascade-search-input"
                    :placeholder="'Search ' + (field.label || 'options').toLowerCase() + '…'"
                    role="combobox" aria-autocomplete="list"
                    :aria-expanded="showResults ? 'true' : 'false'"
                    :aria-controls="uid + '-list'"
                    :aria-activedescendant="showResults && resultRows.length ? uid + '-opt-' + hi : undefined"
                    @focus="searchOpen = true" @blur="searchOpen = false" @keydown="onSearchKey" />
                <button v-if="query" type="button" class="rcmi-cascade-search-clear"
                    aria-label="Clear search" @mousedown.prevent="query = ''">
                    <Icon name="x" />
                </button>
            </div>

            <ul v-if="showResults && resultRows.length" :id="uid + '-list'" role="listbox"
                class="rcmi-cascade-search-results">
                <li v-for="(r, ri) in resultRows" :key="r.key" :id="uid + '-opt-' + ri"
                    role="option" :aria-selected="ri === hi ? 'true' : 'false'"
                    class="rcmi-cascade-search-row"
                    :class="{ 'is-active': ri === hi, 'rcmi-cascade-search-other': r.type === 'other' }"
                    @mouseenter="hi = ri" @mousedown.prevent="activateRow(r)">
                    <template v-if="r.type === 'node'">
                        <span class="rcmi-cascade-search-main">
                            <span class="rcmi-cascade-search-label"><template v-for="(part, pi) in highlightParts(r.label)" :key="pi"><mark v-if="part.hit">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template></span>
                            <span v-if="r.path.length > 1" class="rcmi-cascade-search-trail">in {{ r.path.slice(0, -1).join(' › ') }}</span>
                        </span>
                        <span class="rcmi-cascade-search-meta">
                            <span class="rcmi-cascade-search-level">{{ levelName(r.depth) }}</span>
                            <span v-if="r.childCount" class="rcmi-cascade-search-more">{{ r.childCount }} more option{{ r.childCount === 1 ? '' : 's' }} inside</span>
                        </span>
                    </template>
                    <!-- No matches: offer the typed text as "Other" at the
                         level the user is on. -->
                    <template v-else>
                        <span class="rcmi-cascade-search-main">
                            <span class="rcmi-cascade-search-trail">No matches{{ searchOtherLevel ? ' under ' + path[searchOtherLevel - 1] : '' }}</span>
                            <span class="rcmi-cascade-search-label">Use &ldquo;{{ query.trim() }}&rdquo; as <em>Other</em></span>
                        </span>
                        <span class="rcmi-cascade-search-meta">
                            <span class="rcmi-cascade-search-level">{{ levelName(searchOtherLevel) }}</span>
                        </span>
                    </template>
                </li>
            </ul>
            <p v-else-if="showResults" class="rcmi-cascade-search-empty">
                No matches for &ldquo;{{ query.trim() }}&rdquo;
            </p>
        </div>

        <!-- ── Current answer (while the levels are tucked away) ─────── -->
        <div v-if="hasSearch && isComplete && !showBrowse" class="rcmi-cascade-answer">
            <Icon name="check-circle" />
            <span class="rcmi-cascade-answer-path">
                <template v-for="(seg, si) in path" :key="si">
                    <span v-if="si" class="rcmi-cascade-answer-sep" aria-hidden="true">›</span>
                    <span :class="{ 'rcmi-cascade-answer-leaf': si === path.length - 1 }">{{ seg }}</span>
                </template>
            </span>
            <button v-if="canBrowse" type="button" class="rcmi-cascade-link" @click="browseOpen = true">Change</button>
            <button type="button" class="rcmi-cascade-answer-clear" aria-label="Clear selection" @click="clearAll">
                <Icon name="x" />
            </button>
        </div>

        <!-- ── Browse toggle (secondary) ─────────────────────────────── -->
        <button v-if="hasSearch && canBrowse && !browseForced && !(isComplete && !showBrowse)" type="button"
            class="rcmi-cascade-link rcmi-cascade-browse-toggle"
            :aria-expanded="showBrowse ? 'true' : 'false'" :aria-controls="uid + '-browse'"
            @click="browseOpen = !browseOpen">
            {{ showBrowse ? 'Hide options' : 'Or browse all options' }}
            <Icon :name="showBrowse ? 'chevron-down' : 'chevron-right'" />
        </button>

        <!-- ── Browse levels (secondary) ─────────────────────────────── -->
        <div v-if="showBrowse" :id="uid + '-browse'" class="rcmi-cascade-browse"
            :class="{ 'rcmi-cascade-browse--sub': hasSearch }">

            <!-- Style: stacked dropdowns (one per level) -->
            <template v-if="browseStyle === 'dropdown'">
                <div v-for="(level, li) in levels" :key="li" class="rcmi-cascade-level">
                    <div v-if="li" class="rcmi-cascade-sep">
                        <span v-if="path[li - 1]" class="rcmi-cascade-sep-label">under {{ path[li - 1] }}</span>
                    </div>
                    <select class="rcmi-input" :aria-label="levelName(li)"
                        :value="activeOtherLevel === li ? OTHER : (path[li] || '')" @change="pick(li, $event.target.value)">
                        <option value="">{{ li === 0 ? 'Select…' : 'Select ' + (path[li - 1] || '').toLowerCase() + '…' }}</option>
                        <option v-for="n in level" :key="n.label" :value="n.label">{{ n.label }}</option>
                        <option v-if="otherEnabled" :value="OTHER">Other (specify…)</option>
                    </select>
                </div>
            </template>

            <!-- Style: pill tabs per level -->
            <template v-else-if="browseStyle === 'pills'">
                <div v-for="(level, li) in levels" :key="li" class="rcmi-cascade-level">
                    <div v-if="li" class="rcmi-cascade-sep">
                        <span v-if="path[li - 1]" class="rcmi-cascade-sep-label">under {{ path[li - 1] }}</span>
                    </div>
                    <div class="rcmi-cascade-pills">
                        <button v-for="n in level" :key="n.label" type="button"
                            class="rcmi-cascade-pill"
                            :class="{ 'rcmi-cascade-pill-active': path[li] === n.label && activeOtherLevel !== li }"
                            @click="pick(li, path[li] === n.label ? '' : n.label)">
                            {{ n.label }}
                            <Icon v-if="n.children.length" name="chevron-right" />
                        </button>
                        <button v-if="otherEnabled" type="button"
                            class="rcmi-cascade-pill rcmi-cascade-pill-other"
                            :class="{ 'rcmi-cascade-pill-active': activeOtherLevel === li }"
                            @click="pick(li, OTHER)">
                            Other…
                        </button>
                    </div>
                </div>
            </template>

            <!-- Style: progressive stepper (one level open at a time) -->
            <ol v-else-if="browseStyle === 'steps'" class="rcmi-cascade-steps">
                <li v-for="(level, li) in levels" :key="li" class="rcmi-cascade-step"
                    :class="{
                        'rcmi-cascade-step-open': openStep === li,
                        'rcmi-cascade-step-done': openStep !== li && path[li] !== undefined,
                    }">
                    <div class="rcmi-cascade-step-rail" aria-hidden="true">
                        <span class="rcmi-cascade-step-badge">
                            <Icon v-if="openStep !== li && path[li] !== undefined" name="check" />
                            <template v-else>{{ li + 1 }}</template>
                        </span>
                        <span v-if="li < levels.length - 1" class="rcmi-cascade-step-connector"></span>
                    </div>
                    <div class="rcmi-cascade-step-body">
                        <!-- answered level: collapsed summary -->
                        <template v-if="openStep !== li && path[li] !== undefined">
                            <span class="rcmi-cascade-step-label">{{ stepLabel(li) }}</span>
                            <button type="button" class="rcmi-cascade-step-value"
                                :aria-label="'Change ' + stepLabel(li) + ' (currently ' + path[li] + ')'"
                                title="Click to change"
                                @click="editing = li">{{ path[li] }}</button>
                        </template>
                        <!-- open level: pick an option -->
                        <template v-else-if="openStep === li">
                            <p class="rcmi-cascade-step-heading">{{ stepLabel(li) }}</p>
                            <div class="rcmi-cascade-step-options"
                                :class="[STEP_LAYOUT_CLASSES[stepOptionLayout(level, li)].group, { 'rcmi-cascade-step-options-nested': li > 0 }]"
                                role="radiogroup" :aria-label="stepLabel(li)">
                                <button v-for="n in level" :key="n.label" type="button" role="radio"
                                    class="rcmi-cascade-step-opt"
                                    :class="STEP_LAYOUT_CLASSES[stepOptionLayout(level, li)].opt"
                                    :aria-checked="path[li] === n.label && activeOtherLevel !== li"
                                    @click="pickStep(li, n.label)">
                                    <template v-if="stepOptionLayout(level, li) === 'radios'">
                                        <span class="rcmi-cascade-step-radio" aria-hidden="true"></span>
                                        <span class="rcmi-cascade-step-opt-label">{{ n.label }}</span>
                                    </template>
                                    <template v-else-if="stepOptionLayout(level, li) === 'cards'">
                                        <span class="rcmi-cascade-step-opt-title">{{ n.label }}</span>
                                        <span class="rcmi-cascade-step-opt-sub">{{ n.children.length }} option{{ n.children.length === 1 ? '' : 's' }}</span>
                                    </template>
                                    <template v-else>
                                        <span class="rcmi-cascade-step-opt-label">{{ n.label }}</span>
                                        <Icon v-if="n.children.length" name="chevron-right" />
                                    </template>
                                </button>
                                <button v-if="otherEnabled" type="button" role="radio"
                                    class="rcmi-cascade-step-opt rcmi-cascade-step-opt-other"
                                    :class="STEP_LAYOUT_CLASSES[stepOptionLayout(level, li)].opt"
                                    :aria-checked="activeOtherLevel === li"
                                    @click="pickStep(li, OTHER)">
                                    <span v-if="stepOptionLayout(level, li) === 'radios'"
                                        class="rcmi-cascade-step-radio" aria-hidden="true"></span>
                                    <span class="rcmi-cascade-step-opt-label italic">Other…</span>
                                </button>
                            </div>
                        </template>
                        <!-- upcoming level: muted label only -->
                        <template v-else>
                            <span class="rcmi-cascade-step-label">{{ stepLabel(li) }}</span>
                        </template>
                    </div>
                </li>
            </ol>

            <!-- Style: miller columns -->
            <div v-else-if="browseStyle === 'columns'" class="rcmi-cascade-columns">
                <div v-for="(level, li) in levels" :key="li" class="rcmi-cascade-column"
                    :style="{ backgroundColor: `rgba(148, 163, 184, ${Math.min(li * 0.05, 0.2)})` }">
                    <div class="rcmi-cascade-col-head" :class="{ 'rcmi-cascade-col-head-done': path[li] }">
                        <span class="rcmi-cascade-col-head-label">{{ li === 0 ? 'Start' : path[li - 1] }}</span>
                    </div>
                    <button v-for="n in level" :key="n.label" type="button"
                        class="rcmi-cascade-col-item"
                        :class="{ 'rcmi-cascade-col-item-active': path[li] === n.label && activeOtherLevel !== li }"
                        @click="pick(li, n.label)">
                        <span class="truncate">{{ n.label }}</span>
                        <Icon v-if="n.children.length" name="chevron-right" />
                    </button>
                    <button v-if="otherEnabled" type="button"
                        class="rcmi-cascade-col-item rcmi-cascade-col-item-other"
                        :class="{ 'rcmi-cascade-col-item-active': activeOtherLevel === li }"
                        @click="pick(li, OTHER)">
                        <span class="italic">Other…</span>
                    </button>
                </div>
            </div>

            <!-- Breadcrumb of the current path (styles without their own summary) -->
            <p v-if="browseStyle !== 'steps' && path.length" class="rcmi-field-help mt-1">
                {{ path.join(' › ') }}<span v-if="!isComplete" class="text-gray-400"> — keep going…</span>
            </p>
        </div>

        <!-- ── "Other" free text — one box for every style and level ─── -->
        <div v-if="activeOtherLevel !== null" class="rcmi-cascade-other-wrap">
            <label :for="uid + '-other'" class="rcmi-cascade-other-label">
                Other{{ activeOtherLevel ? ' under ' + path[activeOtherLevel - 1] : '' }} — {{ levelName(activeOtherLevel) }}
            </label>
            <input :id="uid + '-other'" ref="otherEl" type="text"
                class="rcmi-input rcmi-cascade-other" :value="otherText(activeOtherLevel)"
                placeholder="Type your answer…"
                @input="setOther(activeOtherLevel, $event.target.value)" />
        </div>

        <!-- Carries completeness into native form validation (required). -->
        <input type="text" class="rcmi-cascade-guard" :required="required" :value="isComplete ? 'ok' : ''"
            tabindex="-1" aria-hidden="true" />
    </div>
</template>

<script setup>
import { computed, ref, watch, nextTick } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({
    field: { type: Object, required: true },
    modelValue: { type: Array, default: () => [] },
    required: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const OTHER = '__other__';
const uid = 'rcmi-cf-' + Math.random().toString(36).slice(2, 8);

const style = computed(() => props.field.config?.cascade_style || 'dropdown');
// Hidden nodes (visible === false) are stripped from the rendered tree but
// preserved in config, so stored answers and builder edits stay intact.
function filterVisible(nodes) {
    return nodes
        .filter(n => n.visible !== false)
        .map(n => ({ ...n, children: filterVisible(n.children || []) }));
}
const tree = computed(() => {
    const raw = props.field.config?.cascade_tree;
    return Array.isArray(raw) ? filterVisible(raw) : [];
});
const path = computed(() => Array.isArray(props.modelValue) ? props.modelValue : []);
const otherEnabled = computed(() => !!props.field.config?.cascade_other);

// Level index where "Other" was picked (when the path doesn't diverge yet).
const otherAt = ref(null);

// First index where the path diverges from the tree (i.e. a custom value), or -1.
const divergeIdx = computed(() => {
    let siblings = tree.value;
    for (let i = 0; i < path.value.length; i++) {
        const node = siblings.find(n => n.label === path.value[i]);
        if (!node) return i;
        siblings = node.children || [];
    }
    return -1;
});

// Which level currently shows the free-text "Other" input.
const activeOtherLevel = computed(() => {
    if (!otherEnabled.value) return null;
    return divergeIdx.value >= 0 ? divergeIdx.value : otherAt.value;
});

// Walk the tree along the current path; produce the option list for each level.
// Always includes level 0. Stops after a level with no selected node, a custom
// (diverged) value, or whose selected node has no children.
const levels = computed(() => {
    const out = [];
    let siblings = tree.value;
    for (let i = 0; ; i++) {
        if (!siblings.length) break;
        out.push(siblings);
        const sel = siblings.find(n => n.label === path.value[i]);
        if (!sel || !sel.children?.length) break;
        siblings = sel.children;
    }
    return out;
});

// The path is complete when it ends at a leaf node, or at a non-empty custom
// "Other" value.
const isComplete = computed(() => {
    const ol = activeOtherLevel.value;
    if (ol !== null) {
        return Boolean((path.value[ol] || '').trim());
    }
    if (!path.value.length) return false;
    let siblings = tree.value;
    for (const label of path.value) {
        const node = siblings.find(n => n.label === label);
        if (!node) return false;
        siblings = node.children || [];
    }
    return siblings.length === 0;
});

// ── path updates (shared by every style and the search) ──────────────
// Replace the path from level `li` on with `value` ('' clears from li).
function setAt(li, value) {
    const next = path.value.slice(0, li);
    if (value) next.push(value);
    emit('update:modelValue', next);
}

// Choosing a label at level i; OTHER switches that level to free text.
function pick(li, label) {
    if (label === OTHER) {
        otherAt.value = li;
        setAt(li, '');
        focusOther();
        return;
    }
    otherAt.value = null;
    setAt(li, label);
}

// Typing in the Other box stores the text as that level's path segment.
function setOther(li, text) {
    otherAt.value = li;
    setAt(li, text);
}

// Text shown in the Other input at level li.
function otherText(li) {
    return divergeIdx.value === li ? (path.value[li] || '') : '';
}

// Select a whole path at once (search results).
function setPath(p) {
    otherAt.value = null;
    editing.value = null;
    emit('update:modelValue', [...p]);
}

function clearAll() {
    otherAt.value = null;
    editing.value = null;
    query.value = '';
    emit('update:modelValue', []);
}

const otherEl = ref(null);
function focusOther() {
    nextTick(() => otherEl.value?.focus());
}

// Forget "Other" mode when the path no longer reaches that level
// (e.g. parent cleared the answer externally).
watch(path, (p) => {
    if (otherAt.value !== null && p.length < otherAt.value) {
        otherAt.value = null;
    }
});

// ── level labels ─────────────────────────────────────────────────────
// Builder-configured label for level i, or ''.
function configuredLabel(i) {
    const l = props.field.config?.cascade_level_labels?.[i];
    return typeof l === 'string' ? l.trim() : '';
}
// Step heading: configured label, else a prompt built from the path.
function stepLabel(i) {
    return configuredLabel(i) || (i === 0 ? 'Choose an option' : `Under ${path.value[i - 1]}`);
}
// Short level tag (search results, Other box): configured label, else generic.
function levelName(i) {
    return configuredLabel(i) || (i === 0 ? 'Top level' : `Level ${i + 1}`);
}

// ── browse (secondary) ───────────────────────────────────────────────
// Search is available whenever there's more than one option to find.
const allNodes = computed(() => {
    const out = [];
    const walk = (nodes, prefix) => {
        for (const n of nodes) {
            const p = [...prefix, n.label];
            out.push({ label: n.label, path: p, depth: prefix.length, childCount: (n.children || []).length });
            if (n.children?.length) walk(n.children, p);
        }
    };
    walk(tree.value, []);
    return out;
});
const hasSearch = computed(() => allNodes.value.length > 1);

// The "search" style is search-only: levels appear just to finish a
// branch picked from the results (rendered as dropdowns).
const canBrowse = computed(() => style.value !== 'search');
const browseStyle = computed(() => (style.value === 'search' ? 'dropdown' : style.value));

const browseOpen = ref(false);
// Levels must show while a path is part-way (a branch was picked and the
// next level needs answering) — the toggle hides then.
const browseForced = computed(() => (path.value.length > 0 && !isComplete.value) || otherAt.value !== null);
const showBrowse = computed(() => !hasSearch.value || browseOpen.value || browseForced.value);

// Collapse the levels again once an answer is complete.
watch(isComplete, (done) => { if (done && activeOtherLevel.value === null) browseOpen.value = false; });

// ── steps style ──────────────────────────────────────────────────────
// Level index opened for editing via its "Change" button, if any.
const editing = ref(null);

// Only one level is open at a time: the one being edited, else the one
// with an active "Other" input, else the first unanswered level. A fully
// answered path leaves every level collapsed.
const openStep = computed(() => {
    if (editing.value !== null && editing.value < levels.value.length) return editing.value;
    if (activeOtherLevel.value !== null && activeOtherLevel.value < levels.value.length) {
        return activeOtherLevel.value;
    }
    const i = levels.value.findIndex((_, li) => path.value[li] === undefined);
    return i === -1 ? null : i;
});

// Literal class names per control type — building them with template
// literals hides them from Tailwind's content scan, which purges the
// matching @layer components rules in production builds.
const STEP_LAYOUT_CLASSES = {
    cards:  { group: 'rcmi-cascade-step-options-cards',  opt: 'rcmi-cascade-step-opt-cards' },
    tiles:  { group: 'rcmi-cascade-step-options-tiles',  opt: 'rcmi-cascade-step-opt-tiles' },
    radios: { group: 'rcmi-cascade-step-options-radios', opt: 'rcmi-cascade-step-opt-radios' },
};

// Control type for an open level: all-leaf levels get a radio list, the
// root level gets cards, deeper mixed levels get compact tiles.
function stepOptionLayout(level, i) {
    if (level.every(n => !(n.children || []).length)) return 'radios';
    return i === 0 ? 'cards' : 'tiles';
}

// Picking at level i: re-picking the same value while editing keeps the
// deeper path intact; anything else truncates the path at i via pick().
function pickStep(i, label) {
    if (editing.value === i && label !== OTHER && label === path.value[i]) {
        editing.value = null;
        return;
    }
    pick(i, label);
    editing.value = null;
}

// ── search (primary) ─────────────────────────────────────────────────
// Matches items at ANY level of the tree, shows where each one sits, and
// selects it directly. Picking a branch fills the path down to it; the
// levels then open to ask for the next choice.
const searchEl = ref(null);
const query = ref('');
const searchOpen = ref(false);
const hi = ref(0);

function normalize(t) {
    return String(t).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

// Rank: label starts with query > a word starts with it > contains it;
// then shallower levels first, then alphabetical.
const results = computed(() => {
    const q = normalize(query.value.trim());
    if (!q) return [];
    const scored = [];
    for (const n of allNodes.value) {
        const l = normalize(n.label);
        const at = l.indexOf(q);
        if (at === -1) continue;
        const rank = at === 0 ? 0 : (/[\s\-/(&]/.test(l[at - 1]) ? 1 : 2);
        scored.push({ ...n, rank });
    }
    scored.sort((a, b) => a.rank - b.rank || a.depth - b.depth || a.label.localeCompare(b.label));
    return scored.slice(0, 30);
});

// Listbox rows: matches, or — with none and Other enabled — one
// "Use … as Other" row.
const resultRows = computed(() => {
    if (results.value.length) {
        return results.value.map(r => ({ type: 'node', key: r.path.join('\u0001'), ...r }));
    }
    if (otherEnabled.value && query.value.trim()) return [{ type: 'other', key: '__other' }];
    return [];
});

const showResults = computed(() => searchOpen.value && !!query.value.trim());

watch(query, () => { hi.value = 0; searchOpen.value = true; });

// Split a label into matched / unmatched runs for <mark> highlighting.
function highlightParts(label) {
    const q = normalize(query.value.trim());
    const l = normalize(label);
    const at = q ? l.indexOf(q) : -1;
    // Accent folding can change length; only highlight when offsets line up.
    if (at === -1 || l.length !== label.length) return [{ text: label, hit: false }];
    return [
        { text: label.slice(0, at), hit: false },
        { text: label.slice(at, at + q.length), hit: true },
        { text: label.slice(at + q.length), hit: false },
    ].filter(p => p.text);
}

// Level an Other answer typed into the search applies to: the next
// unanswered level of a partial path, otherwise the top level.
const searchOtherLevel = computed(() => {
    if (isComplete.value || divergeIdx.value >= 0) return 0;
    return Math.min(path.value.length, Math.max(levels.value.length - 1, 0));
});

function activateRow(r) {
    const text = query.value.trim();
    query.value = '';
    searchOpen.value = false;
    if (r.type === 'other') {
        editing.value = null;
        setOther(searchOtherLevel.value, text);
        focusOther();
        return;
    }
    setPath(r.path);
    // Branch picked: hand focus to the next level's choices.
    if (r.childCount) {
        nextTick(() => {
            const next = searchEl.value?.parentElement?.querySelector(
                '.rcmi-cascade-step-open button, .rcmi-cascade-level:last-of-type select, .rcmi-cascade-level:last-of-type button, .rcmi-cascade-column:last-child button');
            next?.focus();
        });
    }
}

function onSearchKey(e) {
    const n = resultRows.value.length;
    if (e.key === 'ArrowDown' && n) {
        e.preventDefault();
        searchOpen.value = true;
        hi.value = (hi.value + 1) % n;
    } else if (e.key === 'ArrowUp' && n) {
        e.preventDefault();
        hi.value = (hi.value - 1 + n) % n;
    } else if (e.key === 'Enter') {
        // Never submit the ticket form from the search box.
        e.preventDefault();
        if (showResults.value && n) activateRow(resultRows.value[hi.value]);
    } else if (e.key === 'Escape') {
        if (query.value) { e.preventDefault(); query.value = ''; }
        searchOpen.value = false;
    }
    if (n) nextTick(() => document.getElementById(uid + '-opt-' + hi.value)?.scrollIntoView({ block: 'nearest' }));
}
</script>
