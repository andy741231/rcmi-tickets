 <template>
    <div v-if="editor" class="rcmi-rte border border-gray-300 rounded-md overflow-hidden focus-within:ring-2 focus-within:ring-red-300 focus-within:border-red-400">
        <!-- Toolbar -->
        <div class="flex flex-wrap items-center gap-0.5 border-b border-gray-200 bg-gray-50 px-2 py-1.5">
            <button type="button" @click="editor.chain().focus().toggleBold().run()"
                :class="['rcmi-rte-btn', { 'rcmi-rte-btn-active': editor.isActive('bold') }]"
                title="Bold (Ctrl+B)">
                <strong>B</strong>
            </button>
            <button type="button" @click="editor.chain().focus().toggleItalic().run()"
                :class="['rcmi-rte-btn', { 'rcmi-rte-btn-active': editor.isActive('italic') }]"
                title="Italic (Ctrl+I)">
                <em>I</em>
            </button>
            <span class="mx-0.5 w-px self-stretch bg-gray-300"></span>
            <button type="button" @click="editor.chain().focus().toggleBulletList().run()"
                :class="['rcmi-rte-btn', { 'rcmi-rte-btn-active': editor.isActive('bulletList') }]"
                title="Bullet list">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="8" x2="21" y1="6" y2="6"/><line x1="8" x2="21" y1="12" y2="12"/><line x1="8" x2="21" y1="18" y2="18"/><line x1="3" x2="3.01" y1="6" y2="6"/><line x1="3" x2="3.01" y1="12" y2="12"/><line x1="3" x2="3.01" y1="18" y2="18"/></svg>
            </button>
            <button type="button" @click="editor.chain().focus().toggleOrderedList().run()"
                :class="['rcmi-rte-btn', { 'rcmi-rte-btn-active': editor.isActive('orderedList') }]"
                title="Numbered list">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="10" x2="21" y1="6" y2="6"/><line x1="10" x2="21" y1="12" y2="12"/><line x1="10" x2="21" y1="18" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/></svg>
            </button>
            <span class="mx-0.5 w-px self-stretch bg-gray-300"></span>
            <button type="button" @click="openLinkDialog"
                :class="['rcmi-rte-btn', { 'rcmi-rte-btn-active': editor.isActive('link') }]"
                title="Insert link">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
            </button>
            <button v-if="editor.isActive('link')" type="button" @click="editor.chain().focus().unsetLink().run()"
                class="rcmi-rte-btn" title="Remove link">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <!-- Link dialog -->
        <div v-if="linkOpen" class="flex items-center gap-1.5 border-b border-gray-200 bg-blue-50 px-2.5 py-2">
            <input ref="linkInputRef" v-model="linkUrl" type="url" placeholder="https://example.com"
                class="rcmi-input flex-1 text-sm py-1" @keydown.enter.prevent="setLink" @keydown.escape="linkOpen = false" />
            <button @click="setLink" class="rcmi-button-primary px-2.5 py-1 text-xs">Apply</button>
            <button @click="linkOpen = false" class="rcmi-button-ghost px-2 py-1 text-xs">Cancel</button>
        </div>

        <!-- Editor area -->
        <div class="rcmi-rte-content">
            <editor-content :editor="editor" />
        </div>
    </div>
</template>

<script setup>
import { ref, watch, onBeforeUnmount, onMounted, nextTick } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';

const props = defineProps({
    modelValue: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const linkOpen = ref(false);
const linkUrl = ref('');
const linkInputRef = ref(null);

const editor = useEditor({
    content: props.modelValue,
    extensions: [
        StarterKit.configure({
            heading: false,
            codeBlock: false,
            blockquote: false,
            horizontalRule: false,
        }),
        Link.configure({
            openOnClick: false,
            HTMLAttributes: { class: 'text-red-700 underline' },
        }),
    ],
    editorProps: {
        attributes: {
            class: 'prose prose-sm max-w-none p-3 min-h-[6rem] focus:outline-none',
        },
    },
    onUpdate: ({ editor }) => {
        const html = editor.getHTML();
        // Normalize: if the content is effectively empty, emit empty string
        const stripped = html.replace(/<[^>]*>/g, '').trim();
        emit('update:modelValue', stripped ? html : '');
    },
});

// Sync external modelValue changes into the editor
watch(() => props.modelValue, (val) => {
    const currentHtml = editor.value?.getHTML() || '';
    if (val !== currentHtml) {
        editor.value?.commands.setContent(val || '', false);
    }
});

function openLinkDialog() {
    if (!editor.value) return;
    const prev = editor.value.getAttributes('link').href || '';
    linkUrl.value = prev;
    linkOpen.value = true;
    nextTick(() => linkInputRef.value?.focus());
}

function setLink() {
    if (!editor.value) return;
    const url = linkUrl.value.trim();
    if (url) {
        const href = /^https?:\/\//i.test(url) ? url : 'https://' + url;
        editor.value.chain().focus().extendMarkRange('link').setLink({ href }).run();
    } else {
        editor.value.chain().focus().unsetLink().run();
    }
    linkOpen.value = false;
}

onBeforeUnmount(() => {
    editor.value?.destroy();
});
</script>

<style>
/* RTE toolbar buttons */
.rcmi-rte-btn {
    @apply inline-flex items-center justify-center h-7 w-7 rounded text-sm text-gray-600 hover:bg-gray-200 hover:text-gray-900 transition-colors;
}
.rcmi-rte-btn-active {
    @apply bg-gray-200 text-red-700;
}

/* RTE content area – base typography for email-like content */
.rcmi-rte-content .ProseMirror {
    outline: none;
    min-height: 6rem;
    font-size: 14px;
    line-height: 1.6;
    color: #1f2937;
}
.rcmi-rte-content .ProseMirror p {
    margin: 0 0 0.5rem 0;
}
.rcmi-rte-content .ProseMirror p:last-child {
    margin-bottom: 0;
}
.rcmi-rte-content .ProseMirror ul,
.rcmi-rte-content .ProseMirror ol {
    padding-left: 1.5rem;
    margin: 0 0 0.5rem 0;
}
.rcmi-rte-content .ProseMirror li {
    margin-bottom: 0.125rem;
}
.rcmi-rte-content .ProseMirror a {
    color: #c8102e;
    text-decoration: underline;
}
.rcmi-rte-content .ProseMirror p.is-editor-empty:first-child::before {
    color: #9ca3af;
    content: attr(data-placeholder);
    float: left;
    height: 0;
    pointer-events: none;
}
</style>
