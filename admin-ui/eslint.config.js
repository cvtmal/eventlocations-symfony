import js from '@eslint/js';
import vue from 'eslint-plugin-vue';
import globals from 'globals';

export default [
    { ignores: ['node_modules/'] },
    js.configs.recommended,
    ...vue.configs['flat/recommended'],
    {
        languageOptions: { globals: globals.browser },
        rules: {
            // S12: styles live in admin.scss, strings arrive as props translated by Twig (S4).
            'vue/no-restricted-block': ['error', 'style'],
            'vue/no-bare-strings-in-template': 'error',
            'no-console': 'error',
        },
    },
    {
        files: ['*.config.js'],
        languageOptions: { globals: globals.node },
    },
];
