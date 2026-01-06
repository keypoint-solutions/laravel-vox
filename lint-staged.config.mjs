export default {
    '*.{js,ts,tsx,vue,css,scss,json}': ['prettier --write', 'eslint --fix'],
    '*.blade.php': ['blade-formatter --write'],
    '*.md': ['prettier --write'],
};
