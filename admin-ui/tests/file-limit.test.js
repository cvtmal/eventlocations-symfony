import { afterEach, beforeAll, describe, expect, it } from 'vitest';

const MESSAGE = 'Das PDF ist zu gross, höchstens 15 MB.';

// The documents page's upload card as DocumentUploadType renders it: limit and message from the server.
function card() {
    document.body.innerHTML = `
        <form>
            <div class="field">
                <label for="document_upload_file">PDF</label>
                <input type="file" id="document_upload_file" data-max-bytes="10" data-max-message="${MESSAGE}" aria-describedby="document_upload_file_help">
                <span id="document_upload_file_help">Höchstens 15 MB.</span>
            </div>
            <div class="field"><input id="document_upload_titles_de" value="Preisliste"></div>
        </form>`;

    return document.querySelector('input[type="file"]');
}

function choose(input, bytes) {
    Object.defineProperty(input, 'files', { value: [new File(['x'.repeat(bytes)], 'preise.pdf')], configurable: true });
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

describe('file-limit.js', () => {
    beforeAll(async () => {
        await import('../../assets/js/admin/file-limit.js');
    });

    afterEach(() => {
        document.body.replaceChildren();
    });

    it('refuses a file above the limit in German and keeps the typed titles', () => {
        const input = card();

        choose(input, 11);

        const message = document.getElementById('document_upload_file_limit');
        expect(message.textContent).toBe(MESSAGE);
        expect(message.getAttribute('role')).toBe('alert');
        expect(message.closest('.field')).toBe(input.closest('.field'));
        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(input.getAttribute('aria-describedby')).toBe('document_upload_file_help document_upload_file_limit');
        expect(input.value).toBe('');
        expect(document.getElementById('document_upload_titles_de').value).toBe('Preisliste');
    });

    it('shows one message for two refusals and clears it for a file within the limit', () => {
        const input = card();

        choose(input, 11);
        choose(input, 12);
        expect(document.querySelectorAll('[role="alert"]')).toHaveLength(1);

        choose(input, 10);
        expect(document.getElementById('document_upload_file_limit')).toBeNull();
        expect(input.hasAttribute('aria-invalid')).toBe(false);
        expect(input.getAttribute('aria-describedby')).toBe('document_upload_file_help');
    });

    it('leaves no empty aria-describedby behind', () => {
        const input = card();
        input.removeAttribute('aria-describedby');

        choose(input, 11);
        choose(input, 1);

        expect(input.hasAttribute('aria-describedby')).toBe(false);
    });

    it('ignores a file input without a limit', () => {
        const input = card();
        input.removeAttribute('data-max-bytes');

        choose(input, 1_000);

        expect(document.querySelector('[role="alert"]')).toBeNull();
        expect(input.hasAttribute('aria-invalid')).toBe(false);
    });
});
