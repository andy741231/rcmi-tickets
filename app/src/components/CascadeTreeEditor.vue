<template>
    <ul class="space-y-1.5" :class="{ 'ml-4 border-l border-gray-200 pl-3': depth > 0 }">
        <li v-for="(node, ni) in nodes" :key="ni">
            <div class="flex items-center gap-2">
                <input v-model="node.label" class="rcmi-input flex-1 text-xs" placeholder="Option label" />
                <button type="button" @click="addChild(node)"
                    class="rcmi-button-ghost px-2 py-1 text-xs" title="Add child option">
                    <Icon name="plus" />
                </button>
                <button type="button" @click="nodes.splice(ni, 1)"
                    class="rcmi-button-ghost px-2 py-1 text-xs text-red-700" title="Delete option and its children">
                    <Icon name="x" />
                </button>
            </div>
            <CascadeTreeEditor v-if="node.children?.length" :nodes="node.children" :depth="depth + 1" />
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
import Icon from './Icon.vue';

const props = defineProps({
    nodes: { type: Array, required: true },
    depth: { type: Number, default: 0 },
});

function addSibling() {
    props.nodes.push({ label: '', children: [] });
}

function addChild(node) {
    if (!Array.isArray(node.children)) node.children = [];
    node.children.push({ label: '', children: [] });
}
</script>
