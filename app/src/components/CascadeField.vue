<template>
    <div class="rcmi-cascade">
        <!-- ── Style: stacked dropdowns (one per level) ─────────────── -->
        <template v-if="style === 'dropdown'">
            <div v-for="(level, li) in levels" :key="li" class="rcmi-cascade-level">
                <div v-if="li" class="rcmi-cascade-sep">
                    <span v-if="path[li - 1]" class="rcmi-cascade-sep-label">under {{ path[li - 1] }}</span>
                </div>
                <select class="rcmi-input" :required="required && li === 0"
                    :value="activeOtherLevel === li ? OTHER : (path[li] || '')" @change="pick(li, $event.target.value)">
                    <option value="">{{ li === 0 ? 'Select…' : 'Select ' + (path[li - 1] || '').toLowerCase() + '…' }}</option>
                    <option v-for="n in level" :key="n.label" :value="n.label">{{ n.label }}</option>
                    <option v-if="otherEnabled" :value="OTHER">Other (specify…)</option>
                </select>
                <input v-if="otherEnabled && activeOtherLevel === li" type="text"
                    class="rcmi-input rcmi-cascade-other" :value="otherText(li)"
                    placeholder="Type your answer…" :required="required"
                    @input="setOther(li, $event.target.value)" />
            </div>
        </template>

        <!-- ── Style: pill tabs per level ───────────────────────────── -->
        <template v-else-if="style === 'pills'">
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
                <input v-if="otherEnabled && activeOtherLevel === li" type="text"
                    class="rcmi-input rcmi-cascade-other" :value="otherText(li)"
                    placeholder="Type your answer…"
                    @input="setOther(li, $event.target.value)" />
            </div>
            <input type="text" class="rcmi-cascade-guard" :required="required" :value="isComplete ? 'ok' : ''" tabindex="-1" aria-hidden="true" />
        </template>

        <!-- ── Style: miller columns ────────────────────────────────── -->
        <template v-else-if="style === 'columns'">
            <div class="rcmi-cascade-columns">
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
                <div v-if="otherEnabled && activeOtherLevel !== null && activeOtherLevel < levels.length" class="rcmi-cascade-column">
                    <input type="text" class="rcmi-input m-1" style="width: calc(100% - 0.5rem)" :value="otherText(activeOtherLevel)"
                        placeholder="Type your answer…"
                        @input="setOther(activeOtherLevel, $event.target.value)" />
                </div>
            </div>
            <input type="text" class="rcmi-cascade-guard" :required="required" :value="isComplete ? 'ok' : ''" tabindex="-1" aria-hidden="true" />
        </template>

        <!-- ── Style: search over all leaf paths ────────────────────── -->
        <template v-else>
            <div v-if="isComplete" class="rcmi-cascade-selection">
                <span class="rcmi-cascade-selection-path">{{ path.join(' › ') }}</span>
                <button type="button" class="text-red-700 hover:text-red-800" @click="clearAll" aria-label="Clear selection"><Icon name="x" /></button>
            </div>
            <template v-else>
                <div class="relative">
                    <input v-model="query" type="text" class="rcmi-input" :required="required && !isComplete"
                        placeholder="Type to search…" @focus="open = true" @blur="onBlur" />
                    <ul v-if="open && query && matches.length" class="rcmi-cascade-results">
                        <li v-for="(m, mi) in matches" :key="mi">
                            <button type="button" class="rcmi-cascade-result" @mousedown.prevent="selectPath(m)">
                                {{ m.join(' › ') }}
                            </button>
                        </li>
                    </ul>
                    <p v-else-if="open && query && !matches.length" class="rcmi-cascade-results-empty">No matches</p>
                </div>
                <template v-if="otherEnabled">
                    <button v-if="activeOtherLevel === null" type="button"
                        class="self-start text-xs font-semibold text-red-700 hover:text-red-800"
                        @click="pick(0, OTHER)">
                        Can't find it? Enter a custom value
                    </button>
                    <input v-else type="text" class="rcmi-input" :value="otherText(0)"
                        placeholder="Type your answer…"
                        @input="setOther(0, $event.target.value)" />
                </template>
            </template>
        </template>

        <!-- Breadcrumb of current partial path (non-search styles) -->
        <p v-if="style !== 'search' && path.length" class="rcmi-field-help mt-1">
            {{ path.join(' › ') }}<span v-if="!isComplete" class="text-gray-400"> — keep going…</span>
        </p>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({
    field: { type: Object, required: true },
    modelValue: { type: Array, default: () => [] },
    required: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const OTHER = '__other__';

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
    return true;
});

// Selecting a label at level i truncates the path at i and appends the label.
// '' clears from level i; OTHER switches that level to free-text mode.
function pick(levelIdx, label) {
    if (label === OTHER) {
        otherAt.value = levelIdx;
        emit('update:modelValue', path.value.slice(0, levelIdx));
        return;
    }
    otherAt.value = null;
    const next = path.value.slice(0, levelIdx);
    if (label) next.push(label);
    emit('update:modelValue', next);
}

// Text shown in the Other input at level li.
function otherText(li) {
    return divergeIdx.value === li ? (path.value[li] || '') : '';
}

// Typing in the Other input stores the text as that level's path segment.
function setOther(li, v) {
    const next = path.value.slice(0, li);
    if (v) next.push(v);
    emit('update:modelValue', next);
}

function clearAll() {
    otherAt.value = null;
    query.value = '';
    emit('update:modelValue', []);
}

// Forget "Other" mode when the path no longer reaches that level
// (e.g. parent cleared the answer externally).
watch(path, (p) => {
    if (otherAt.value !== null && p.length < otherAt.value) {
        otherAt.value = null;
    }
});

// ── search style ─────────────────────────────────────────────────────
const query = ref('');
const open = ref(false);

const leafPaths = computed(() => {
    const out = [];
    const walk = (nodes, prefix) => {
        for (const n of nodes) {
            const p = [...prefix, n.label];
            if (n.children?.length) walk(n.children, p);
            else out.push(p);
        }
    };
    walk(tree.value, []);
    return out;
});

const matches = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return [];
    return leafPaths.value
        .filter(p => p.some(seg => seg.toLowerCase().includes(q)))
        .slice(0, 20);
});

function selectPath(p) {
    otherAt.value = null;
    emit('update:modelValue', p);
    query.value = '';
    open.value = false;
}

function onBlur() {
    open.value = false;
}
</script>
