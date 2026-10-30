import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import UiDrawer from '../src/components/UiDrawer.vue';
import UiModal from '../src/components/UiModal.vue';

const props = { id: 'demo', title: 'Titel', closeLabel: 'Schliessen' };

describe('UiModal', () => {
    afterEach(() => {
        document.body.replaceChildren();
    });

    it('opens and closes with its prop', async () => {
        const modal = mount(UiModal, { props, attachTo: document.body });
        const dialog = modal.get('dialog').element;

        expect(dialog.open).toBe(false);

        await modal.setProps({ open: true });
        await nextTick();
        expect(dialog.open).toBe(true);

        await modal.setProps({ open: false });
        await nextTick();
        expect(dialog.open).toBe(false);
    });

    it('opens on mount when asked', () => {
        const modal = mount(UiModal, { props: { ...props, open: true }, attachTo: document.body });

        expect(modal.get('dialog').element.open).toBe(true);
    });

    it('emits close when the dialog closes itself, as on Esc', async () => {
        const modal = mount(UiModal, { props: { ...props, open: true }, attachTo: document.body });

        modal.get('dialog').element.close();

        expect(modal.emitted('close')).toHaveLength(1);
    });

    it('emits close from its close button', async () => {
        const modal = mount(UiModal, { props: { ...props, open: true }, attachTo: document.body });

        await modal.get('form').trigger('submit');

        expect(modal.emitted('close')).toHaveLength(1);
    });

    it('does not emit when the parent closed it', async () => {
        const modal = mount(UiModal, { props: { ...props, open: true }, attachTo: document.body });

        await modal.setProps({ open: false });
        await nextTick();

        expect(modal.emitted('close')).toBeUndefined();
    });

    it('renders the footer only with content', () => {
        expect(mount(UiModal, { props }).find('.modal__footer').exists()).toBe(false);
        expect(mount(UiModal, { props, slots: { footer: '<button>Ok</button>' } }).find('.modal__footer').exists()).toBe(true);
    });

    it('is a drawer with one class more', () => {
        const drawer = mount(UiDrawer, { props, slots: { default: 'Inhalt', footer: '<button>Ok</button>' } });

        expect(drawer.get('dialog').classes()).toContain('drawer');
        expect(drawer.get('.modal__body').text()).toBe('Inhalt');
        expect(drawer.find('.modal__footer').exists()).toBe(true);
    });
});
