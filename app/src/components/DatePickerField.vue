<template>
    <div ref="root" class="rcmi-datepicker">
        <button :id="id" ref="trigger" type="button" class="rcmi-input rcmi-datepicker-trigger"
            :class="{ 'is-empty': !modelValue }"
            :aria-expanded="open ? 'true' : 'false'" aria-haspopup="dialog"
            :aria-describedby="describedby"
            @click="toggle" @keydown.down.prevent="openPicker">
            <span>{{ modelValue ? fmtLong(modelValue) : 'Select a date' }}</span>
            <Icon name="calendar" />
        </button>
        <!-- Carries the value into native form validation (required). -->
        <input class="rcmi-datepicker-proxy" tabindex="-1" aria-hidden="true"
            :value="modelValue || ''" :required="required" @focus="focusTrigger" />

        <div v-if="open" class="rcmi-datepicker-pop" role="dialog" aria-modal="false"
            :aria-label="'Choose ' + (label || 'a date')" @keydown.esc.stop.prevent="close(true)">
            <div class="rcmi-datepicker-head">
                <button type="button" class="rcmi-datepicker-nav" :disabled="!canPrev"
                    aria-label="Previous month" @click="shiftMonth(-1)">
                    <Icon name="chevron-left" />
                </button>
                <div class="rcmi-datepicker-month" aria-live="polite">{{ monthLabel }}</div>
                <button type="button" class="rcmi-datepicker-nav" aria-label="Next month" @click="shiftMonth(1)">
                    <Icon name="chevron-right" />
                </button>
            </div>

            <table class="rcmi-datepicker-grid" role="grid" :aria-label="monthLabel">
                <thead>
                    <tr>
                        <th v-for="d in WEEKDAYS" :key="d.long" scope="col" :abbr="d.long">{{ d.short }}</th>
                    </tr>
                </thead>
                <tbody @keydown="onGridKey">
                    <tr v-for="(week, wi) in weeks" :key="wi">
                        <td v-for="cell in week" :key="cell.iso" role="gridcell"
                            :aria-selected="cell.iso === modelValue ? 'true' : 'false'">
                            <button v-if="cell.inMonth" type="button" class="rcmi-datepicker-day"
                                :class="{
                                    'is-blocked': cell.blocked && cell.code !== 'past',
                                    'is-past': cell.code === 'past',
                                    'is-selected': cell.iso === modelValue,
                                    'is-today': cell.iso === today,
                                    'is-earliest': cell.iso === earliest,
                                }"
                                :data-iso="cell.iso"
                                :tabindex="cell.iso === focusIso ? 0 : -1"
                                :aria-disabled="cell.blocked ? 'true' : undefined"
                                :aria-label="dayAriaLabel(cell)"
                                :title="cell.blocked ? cell.reason : undefined"
                                @click="pick(cell)" @focus="focusIso = cell.iso">
                                {{ cell.day }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="rcmi-datepicker-legend">
                <span><i class="rcmi-datepicker-swatch is-open" aria-hidden="true"></i> Available</span>
                <span><i class="rcmi-datepicker-swatch is-blocked" aria-hidden="true"></i> {{ blockedLegend }}</span>
            </div>
            <p v-if="blockedMsg" class="rcmi-datepicker-blocked-msg" role="alert">{{ blockedMsg }}</p>
        </div>
    </div>
</template>

<script setup>
/**
 * Calendar date picker that shows *why* days are unavailable.
 * The native <input type="date"> grays out days before `min` with no
 * explanation (and can't mark weekends at all), so ticket forms use this
 * instead: unavailable days are hatched with a tooltip, and a legend
 * ("Blocked out — needs 7 days' notice") sits under the grid. Dates are YYYY-MM-DD strings in the user's local time zone.
 */
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    id: { type: String, default: undefined },
    label: { type: String, default: '' },
    required: { type: Boolean, default: false },
    min: { type: String, default: '' },          // earliest selectable YYYY-MM-DD
    weekdaysOnly: { type: Boolean, default: false },
    minDays: { type: Number, default: null },    // for the explanation text
    describedby: { type: String, default: undefined },
});
const emit = defineEmits(['update:modelValue', 'change']);

const WEEKDAYS = [
    { short: 'Su', long: 'Sunday' }, { short: 'Mo', long: 'Monday' },
    { short: 'Tu', long: 'Tuesday' }, { short: 'We', long: 'Wednesday' },
    { short: 'Th', long: 'Thursday' }, { short: 'Fr', long: 'Friday' },
    { short: 'Sa', long: 'Saturday' },
];

const root = ref(null);
const trigger = ref(null);
const open = ref(false);
const view = ref(startOfMonth(props.modelValue || props.min || toIso(new Date())));
const focusIso = ref('');
const blockedMsg = ref('');

const today = toIso(new Date());

function toIso(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}
function fromIso(iso) {
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d);
}
function startOfMonth(iso) {
    const d = fromIso(iso);
    return toIso(new Date(d.getFullYear(), d.getMonth(), 1));
}
function addDays(iso, n) {
    const d = fromIso(iso);
    d.setDate(d.getDate() + n);
    return toIso(d);
}
function fmtLong(iso) {
    return fromIso(iso).toLocaleDateString(undefined, {
        weekday: 'short', month: 'short', day: 'numeric', year: 'numeric',
    });
}
function isWeekend(iso) {
    const day = fromIso(iso).getDay();
    return day === 0 || day === 6;
}

// '' (selectable) | 'past' | 'notice' (inside the notice window) | 'weekend'
function blockReason(iso) {
    if (iso < today) return 'past';
    if (props.min && iso < props.min) return 'notice';
    if (props.weekdaysOnly && isWeekend(iso)) return 'weekend';
    return '';
}
function reasonText(code) {
    if (code === 'past') return 'Unavailable — date has passed';
    if (code === 'weekend') return 'Unavailable — weekends are not available';
    if (code === 'notice') return 'Unavailable — ' + (props.minDays ? `needs ${noticeText.value}` : 'too soon');
    return '';
}
const noticeText = computed(() => {
    const unit = props.weekdaysOnly ? 'business day' : 'day';
    return `${props.minDays} ${unit}${props.minDays === 1 ? '' : 's'}' notice`;
});

// First selectable date on/after min (skips weekends when weekdays-only).
const earliest = computed(() => {
    let iso = props.min && props.min > today ? props.min : today;
    for (let i = 0; i < 7 && blockReason(iso); i++) iso = addDays(iso, 1);
    return iso;
});


const blockedLegend = computed(() => {
    if (props.minDays) return `Blocked out — needs ${noticeText.value}`;
    if (props.weekdaysOnly) return 'Blocked out — weekends';
    return 'Blocked out';
});

const monthLabel = computed(() =>
    fromIso(view.value).toLocaleDateString(undefined, { month: 'long', year: 'numeric' }));

const canPrev = computed(() => {
    // Don't page back past the month containing today.
    return view.value > startOfMonth(today);
});

const weeks = computed(() => {
    const first = fromIso(view.value);
    const start = new Date(first);
    start.setDate(1 - first.getDay());
    const rows = [];
    for (let w = 0; w < 6; w++) {
        const row = [];
        for (let i = 0; i < 7; i++) {
            const d = new Date(start);
            d.setDate(start.getDate() + w * 7 + i);
            const iso = toIso(d);
            const code = blockReason(iso);
            row.push({
                iso, day: d.getDate(),
                inMonth: d.getMonth() === first.getMonth(),
                blocked: !!code, code, reason: reasonText(code),
            });
        }
        // Drop a trailing week that's entirely next month.
        if (w > 3 && row.every((c) => !c.inMonth)) break;
        rows.push(row);
    }
    return rows;
});

function dayAriaLabel(cell) {
    const base = fromIso(cell.iso).toLocaleDateString(undefined, {
        weekday: 'long', month: 'long', day: 'numeric', year: 'numeric',
    });
    if (cell.blocked) return `${base}, unavailable: ${cell.reason.replace(/^Unavailable — /, '')}`;
    if (cell.iso === props.modelValue) return `${base}, selected`;
    return base;
}

function openPicker() {
    if (open.value) return;
    const target = props.modelValue || earliest.value;
    view.value = startOfMonth(target);
    focusIso.value = target;
    blockedMsg.value = '';
    open.value = true;
    document.addEventListener('pointerdown', onOutside, true);
    nextTick(focusDay);
}
function close(returnFocus) {
    if (!open.value) return;
    open.value = false;
    document.removeEventListener('pointerdown', onOutside, true);
    if (returnFocus) trigger.value?.focus();
}
function toggle() {
    open.value ? close(false) : openPicker();
}
function onOutside(e) {
    if (root.value && !root.value.contains(e.target)) close(false);
}
function focusTrigger() {
    trigger.value?.focus();
}

function shiftMonth(n) {
    const d = fromIso(view.value);
    view.value = toIso(new Date(d.getFullYear(), d.getMonth() + n, 1));
    blockedMsg.value = '';
}

function pick(cell) {
    if (cell.blocked) {
        blockedMsg.value = cell.code === 'past'
            ? 'That date has passed.'
            : `${fmtLong(cell.iso)} isn't available.`;
        return;
    }
    emit('update:modelValue', cell.iso);
    emit('change', cell.iso);
    close(true);
}

function focusDay() {
    const el = root.value?.querySelector(`[data-iso="${focusIso.value}"]`);
    el?.focus();
}

// Arrow-key navigation across days/weeks; PageUp/PageDown change month.
function onGridKey(e) {
    const steps = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
    let next = null;
    if (e.key in steps) next = addDays(focusIso.value, steps[e.key]);
    else if (e.key === 'PageUp' || e.key === 'PageDown') {
        const d = fromIso(focusIso.value);
        next = toIso(new Date(d.getFullYear(), d.getMonth() + (e.key === 'PageUp' ? -1 : 1), d.getDate()));
    } else if (e.key === 'Home') next = addDays(focusIso.value, -fromIso(focusIso.value).getDay());
    else if (e.key === 'End') next = addDays(focusIso.value, 6 - fromIso(focusIso.value).getDay());
    if (!next) return;
    e.preventDefault();
    if (next < startOfMonth(today)) return;
    focusIso.value = next;
    if (startOfMonth(next) !== view.value) view.value = startOfMonth(next);
    nextTick(focusDay);
}

watch(() => props.modelValue, (v) => { if (v && !open.value) view.value = startOfMonth(v); });

onBeforeUnmount(() => document.removeEventListener('pointerdown', onOutside, true));
</script>
