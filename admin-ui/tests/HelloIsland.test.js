import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import HelloIsland from '../src/islands/HelloIsland.vue';

const props = { greeting: 'Hallo', countLabel: 'Klicks', buttonLabel: 'Zählen' };

describe('HelloIsland', () => {
    it('renders the strings it is given', () => {
        const island = mount(HelloIsland, { props });

        expect(island.text()).toContain('Hallo');
        expect(island.get('button').text()).toBe('Zählen');
    });

    it('counts clicks', async () => {
        const island = mount(HelloIsland, { props });

        await island.get('button').trigger('click');
        await island.get('button').trigger('click');

        expect(island.get('[aria-live]').text()).toBe('Klicks: 2');
    });
});
