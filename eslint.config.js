import js from '@eslint/js';
import stylistic from '@stylistic/eslint-plugin';
import prettier from 'eslint-config-prettier/flat';
import importPlugin from 'eslint-plugin-import';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import typescript from 'typescript-eslint';

const controlStatements = [
    'if',
    'return',
    'for',
    'while',
    'do',
    'switch',
    'try',
    'throw',
];
const paddingAroundControl = [
    ...controlStatements.flatMap((stmt) => [
        { blankLine: 'always', prev: '*', next: stmt },
        { blankLine: 'always', prev: stmt, next: '*' },
    ]),
];

/** @type {import('eslint').Linter.Config[]} */
export default [
    js.configs.recommended,
    reactHooks.configs.flat['recommended-latest'],
    ...typescript.configs.recommended,
    {
        ...react.configs.flat.recommended,
        ...react.configs.flat['jsx-runtime'], // Required for React 17+
        languageOptions: {
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            'react/react-in-jsx-scope': 'off',
            'react/prop-types': 'off',
            'react/no-unescaped-entities': 'off',
        },
        settings: {
            react: {
                version: 'detect',
            },
        },
    },
    {
        plugins: {
            import: importPlugin,
        },
        settings: {
            'import/resolver': {
                typescript: {
                    alwaysTryTypes: true,
                    project: './tsconfig.json',
                },
                node: true,
            },
        },
        rules: {
            '@typescript-eslint/no-explicit-any': 'error',
            'react/jsx-no-useless-fragment': [
                'error',
                { allowExpressions: true },
            ],
            'react/button-has-type': 'error',
            'react/jsx-no-target-blank': 'error',
            'no-restricted-imports': [
                'error',
                {
                    paths: [
                        {
                            name: 'axios',
                            message:
                                'Inertia v3 retiró axios: usa useForm / useHttp (o lib/sam-fetch para streaming).',
                        },
                    ],
                },
            ],
            'no-restricted-syntax': [
                'error',
                {
                    selector:
                        'Literal[value=/(^|\\s)(text|tracking|leading)-\\[/]',
                    message:
                        'Tamaños arbitrarios prohibidos: usa los tokens de @theme (text-3xs…text-3xl, tracking-label/caps).',
                },
                {
                    selector:
                        'TemplateElement[value.raw=/(^|\\s)(text|tracking|leading)-\\[/]',
                    message:
                        'Tamaños arbitrarios prohibidos: usa los tokens de @theme (text-3xs…text-3xl, tracking-label/caps).',
                },
            ],
            '@typescript-eslint/consistent-type-imports': [
                'error',
                {
                    prefer: 'type-imports',
                    fixStyle: 'separate-type-imports',
                },
            ],
            'import/order': [
                'error',
                {
                    groups: [
                        'builtin',
                        'external',
                        'internal',
                        'parent',
                        'sibling',
                        'index',
                    ],
                    alphabetize: {
                        order: 'asc',
                        caseInsensitive: true,
                    },
                },
            ],
            'import/consistent-type-specifier-style': [
                'error',
                'prefer-top-level',
            ],
        },
    },
    {
        plugins: {
            '@stylistic': stylistic,
        },
        rules: {
            '@stylistic/brace-style': [
                'error',
                '1tbs',
                { allowSingleLine: false },
            ],
            '@stylistic/padding-line-between-statements': [
                'error',
                ...paddingAroundControl,
            ],
        },
    },
    {
        ignores: [
            'vendor',
            'node_modules',
            'public',
            'bootstrap/ssr',
            // Agent worktrees live under .claude/worktrees/ and carry a full
            // copy of the app. Without this, linting from the main checkout
            // walks into every open worktree and reports tens of thousands of
            // errors that belong to another checkout entirely.
            '.claude',
            'tailwind.config.js',
            'vite.config.ts',
            'resources/js/actions/**',
            // shadcn sin tocar; los componentes propios de ui/ sí se revisan.
            'resources/js/components/ui/*',
            '!resources/js/components/ui/combobox.tsx',
            '!resources/js/components/ui/empty-state.tsx',
            '!resources/js/components/ui/page-header.tsx',
            '!resources/js/components/ui/pagination.tsx',
            '!resources/js/components/ui/sonner.tsx',
            '!resources/js/components/ui/switch.tsx',
            '!resources/js/components/ui/textarea.tsx',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
    },
    prettier, // Turn off all rules that might conflict with Prettier
    {
        plugins: {
            '@stylistic': stylistic,
        },
        rules: {
            curly: ['error', 'all'],
            '@stylistic/brace-style': [
                'error',
                '1tbs',
                { allowSingleLine: false },
            ],
        },
    },
];
