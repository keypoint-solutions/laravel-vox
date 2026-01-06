/** @type {import('prettier').Config} */
export default {
    // General formatting
    printWidth: 120,
    tabWidth: 4,

    // JavaScript / TypeScript
    singleQuote: true,
    jsxSingleQuote: false,
    trailingComma: 'es5',
    arrowParens: 'always',

    // Vue
    vueIndentScriptAndStyle: true,
    singleAttributePerLine: true,

    // Tailwind CSS class sorting
    plugins: ['prettier-plugin-tailwindcss'],
};
