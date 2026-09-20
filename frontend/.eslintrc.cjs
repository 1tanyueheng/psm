/**
 * ESLint configuration.
 *
 * Deliberately lean. The rules enabled here are the ones that catch real bugs
 * in this codebase — an unused import that hides a typo, a missing hook
 * dependency that produces a stale closure, a `key` missing from a list. Rules
 * that only enforce taste are off, because a noisy linter gets ignored.
 */
module.exports = {
  root: true,
  env: {
    browser: true,
    es2022: true,
  },
  extends: [
    'eslint:recommended',
    'plugin:react/recommended',
    'plugin:react/jsx-runtime',
    'plugin:react-hooks/recommended',
  ],
  parserOptions: {
    ecmaVersion: 'latest',
    sourceType: 'module',
    ecmaFeatures: { jsx: true },
  },
  settings: {
    react: { version: 'detect' },
  },
  plugins: ['react-refresh'],
  rules: {
    // A missing key is a real rendering bug, not a style preference.
    'react/jsx-key': 'error',

    // An unused variable is usually a leftover from a rename. Underscore-
    // prefixed names are the escape hatch for intentional omissions.
    'no-unused-vars': [
      'error',
      {
        varsIgnorePattern: '^_',
        argsIgnorePattern: '^_',
        caughtErrorsIgnorePattern: '^_',
      },
    ],

    // PropTypes are not used — the API is the contract, and every screen
    // already handles a missing field defensively.
    'react/prop-types': 'off',

    'react-refresh/only-export-components': [
      'warn',
      { allowConstantExport: true },
    ],

    // `console.warn`/`console.error` are fine; a stray `console.log` in
    // shipped code usually is not.
    'no-console': ['warn', { allow: ['warn', 'error'] }],

    'no-debugger': 'error',
    eqeqeq: ['error', 'smart'],
    'prefer-const': 'error',
  },
  ignorePatterns: ['dist', 'node_modules', '**/build/**'],

  overrides: [
    {
      // Build tooling runs in Node, not the browser, so `process` and friends
      // are legitimately in scope here.
      files: ['vite.config.js', 'postcss.config.js', 'tailwind.config.js', '*.cjs'],
      env: { node: true },
    },
  ],
}
