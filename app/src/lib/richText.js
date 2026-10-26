/**
 * Rich-text helpers for survey intro/thank-you messages.
 *
 * Values saved before the rich editor was introduced are plain text,
 * possibly with newlines. toEditorHtml() upgrades those to simple
 * <p>/<br> HTML; strings that already contain markup pass through.
 *
 * renderRichText() produces safe HTML for v-html: the same tag subset
 * the server allows via rcmi_tickets_sanitize_completion_message().
 */

import DOMPurify from 'dompurify';

const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'b', 'i', 'a', 'ul', 'ol', 'li'];
const ALLOWED_ATTR = ['href', 'title', 'target', 'rel'];

function escapeHtml(text) {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function plainToHtml(text) {
    // Blank lines split paragraphs; single newlines become <br>.
    return escapeHtml(text)
        .split(/\n\s*\n+/)
        .map(block => `<p>${block.replace(/\n/g, '<br>')}</p>`)
        .join('');
}

export function toEditorHtml(str) {
    const text = String(str ?? '');
    if (!text.trim() || text.includes('<')) return text;
    return plainToHtml(text);
}

export function renderRichText(str) {
    const html = toEditorHtml(str);
    if (!html.trim()) return '';
    // Scoped to this sanitize call: external links open in a new tab.
    DOMPurify.addHook('afterSanitizeAttributes', (node) => {
        if (node.tagName === 'A') {
            node.setAttribute('target', '_blank');
            node.setAttribute('rel', 'noopener');
        }
    });
    try {
        return DOMPurify.sanitize(html, {
            ALLOWED_TAGS,
            ALLOWED_ATTR,
        });
    } finally {
        DOMPurify.removeHook('afterSanitizeAttributes');
    }
}
