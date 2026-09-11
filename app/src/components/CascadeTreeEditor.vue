<template>
    <ul class="space-y-2" :class="{ 'mt-2 ml-3 border-l border-gray-200 pl-3': depth > 0 }">
        <li v-for="(node, ni) in nodes" :key="ni"
            :class="{ 'opacity-40': dragIdx === ni, 'border-t-2 border-red-400': dropIdx === ni }"
            @dragover.prevent.stop="dropIdx = ni"
            @dragleave.stop="dropIdx === ni && (dropIdx = null)"
            @drop.prevent.stop="onDrop(ni)">
            <div class="flex items-center gap-2" :class="{ 'opacity-50': !isVisible(node) }">
                <span class="cursor-grab text-gray-400" aria-label="Drag to reorder"
                    draggable="true"
                    @dragstart="onDragStart(ni, $event)"
                    @dragend="onDragEnd">
                    <Icon name="grip" />
                </span>
                <button v-if="node.children?.length" type="button"
                    @click="node._collapsed = !node._collapsed"
                    class="text-gray-400 hover:text-gray-600"
                    :title="node._collapsed ? 'Expand children' : 'Collapse children'"
                    :aria-expanded="!node._collapsed">
                    <span class="inline-block transition-transform duration-150"
                        :class="{ 'rotate-90': !node._collapsed }">
                        <Icon name="chevron-right" />
                    </span>
                </button>
                <span v-else class="w-4 shrink-0"></span>
                <input v-model="node.label" class="rcmi-input flex-1 text-xs"
                    :class="{ 'line-through decoration-gray-400': !isVisible(node) }"
                    placeholder="Option label" />
                <button type="button" @click="node.visible = !isVisible(node)"
                    class="rcmi-button-ghost px-2 py-1 text-xs"
                    :class="{ 'text-red-600': !isVisible(node) }"
                    :title="isVisible(node) ? 'Hide option from form' : 'Show option'"
                    :aria-pressed="!isVisible(node)">
                    <Icon :name="isVisible(node) ? 'eye' : 'eye-off'" />
                </button>
                <button type="button" @click="addChild(node)"
                    class="rcmi-button-ghost px-2 py-1 text-xs" title="Add child option">
                    <Icon name="plus" />
                </button>
                <button type="button" @click="nodes.splice(ni, 1)"
                    class="rcmi-button-ghost px-2 py-1 text-xs text-red-700" title="Delete option and its children">
                    <Icon name="x" />
                </button>
            </div>
            <CascadeTreeEditor v-if="node.children?.length && !node._collapsed"
                :nodes="node.children" :depth="depth + 1" />
        </li>
        <li>
            <button type="button" @click="addSibling"
                class="rcmi-button-secondary inline-flex items-center gap-1 px-2.5 py-1.5 text-xs">
                <Icon name="plus" /> {{ depth === 0 ? 'Add top-level option' : 'Add option' }}
            </button>
        </li>
    </ul>
</template>

<script setup>
import { ref } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({
    nodes: { type: Array, required: true },
    depth: { type: Number, default: 0 },
});

// Sibling reorder within this level only. Each recursive instance owns its
// own drag state, so drags can't cross levels.
const dragIdx = ref(null);
const dropIdx = ref(null);

function isVisible(node) {
    return node.visible !== false;
}

function onDragStart(idx, e) {
    dragIdx.value = idx;
    e.dataTransfer.effectAllowed = 'move';
    e.stopPropagation();
}

function onDragEnd() {
    dragIdx.value = null;
    dropIdx.value = null;
}

function onDrop(targetIdx) {
    const from = dragIdx.value;
    dragIdx.value = null;
    dropIdx.value = null;
    if (from === null || from === targetIdx) return;
    const moved = props.nodes.splice(from, 1)[0];
    props.nodes.splice(targetIdx, 0, moved);
}

function addSibling() {
    props.nodes.push({ label: '', children: [] });
}

function addChild(node) {
    if (!Array.isArray(node.children)) node.children = [];
    node._collapsed = false;
    node.children.push({ label: '', children: [] });
}
</script>
